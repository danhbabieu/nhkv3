<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{CanonicalApplyReadBackVerifier, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeService};
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Governance\{ApplyExecutionHook, GovernanceAuthorizer};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, GovernanceMigration003, GraphMigration001, KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class GovernedSemanticIngestIntegrationTest extends TestCase
{
    private string $prefix = '';
    private array $owned = [];

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
        (new GovernanceMigration003())->up();
        (new KnowledgeMigration005())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
        do_action('rest_api_init');
        $this->prefix = 'governed-ingest-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        foreach ($this->owned as $id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_evidence WHERE evidence_uuid=%s", UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE canonical_uuid=%s", UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_sources WHERE canonical_uuid=%s", UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_videos WHERE canonical_uuid=%s", UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_entities WHERE canonical_uuid=%s", UuidCodec::toBinary($id)));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_proposals WHERE idempotency_key LIKE %s", $this->prefix . '%'));
        $ownedKeys = array_values(array_unique(array_merge($this->owned, array_map(static fn (string $id): string => 'video:' . $id, $this->owned))));
        $placeholders = implode(',', array_fill(0, count($ownedKeys), '%s'));
        $nodeIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN ($placeholders)", ...$ownedKeys));
        if ($nodeIds !== []) $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE source_node_id IN (" . implode(',', array_map('intval', $nodeIds)) . ") OR target_node_id IN (" . implode(',', array_map('intval', $nodeIds)) . ")");
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN ($placeholders)", ...$ownedKeys));
    }

    public function test_governed_full_flow_reads_back_source_claim_evidence_video_and_variant_relation(): void
    {
        [$authority, $variant, $governance, $apply] = $this->fixture();
        $source = $this->runGoverned($governance, $apply, 'source', ['stable_key' => $this->prefix . '-source', 'title' => 'Independent catalogue', 'source_type' => 'catalog', 'locator' => 'https://example.test/source']);
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-claim', 'text' => 'The variant uses a spring-driven movement.', 'claim_type' => 'technical', 'provenance' => ['test' => $this->prefix]]);
        $evidence = $this->runGoverned($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Spring-driven movement', 'relation' => 'supports', 'locator' => 'https://example.test/source#movement', 'metadata' => ['visibility' => 'PUBLIC']]);
        $video = $this->runGoverned($governance, $apply, 'video', [
            'url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => 'Governed test video',
            'metadata' => $this->videoMetadata([[
                'target_type' => 'variant', 'target_key' => $variant->canonicalId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION',
                'evidence_refs' => [['evidence_id' => $evidence['canonical_id']]],
            ]], true),
        ]);

        self::assertSame($source['canonical_id'], (new WpdbSourceRepository($GLOBALS['wpdb']))->findByCanonicalId($source['canonical_id'])?->canonicalId);
        self::assertSame($claim['canonical_id'], (new WpdbKnowledgeRepository($GLOBALS['wpdb']))->findByCanonicalId($claim['canonical_id'])?->canonicalId);
        self::assertSame($evidence['canonical_id'], (new WpdbEvidenceRepository($GLOBALS['wpdb']))->findByCanonicalId($evidence['canonical_id'])?->canonicalId);
        self::assertSame($video['canonical_id'], (new WpdbVideoRepository($GLOBALS['wpdb']))->findByCanonicalId($video['canonical_id'])?->canonicalId);
        $edge = (new WpdbGraphRepository($GLOBALS['wpdb']))->findEdge(new NodeReference('video', $video['canonical_id']), 'about', new NodeReference('variant', $variant->canonicalId));
        self::assertNotNull($edge);
        self::assertNotSame($source['proposal_id'], $source['canonical_id']);
        self::assertTrue($video['canonical_readback']['active']);
    }

    public function test_fail_closed_rejects_proposal_uuid_as_canonical_dependency(): void
    {
        [, , $governance, ] = $this->fixture();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'source', 'ingest', ['stable_key' => $this->prefix . '-invalid'], hash('sha256', 'invalid'), null, hash('sha256', 'invalid-dependency'), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-invalid', entityType: 'source'));
        $validator = new CanonicalDependencyValidator(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb']));
        $this->expectExceptionMessage('Claim must resolve by canonical UUID.');
        $validator->claim($proposal->id);
    }

    public function test_video_relation_failure_rolls_back_inactive_video_and_relation(): void
    {
        [, $variant, $governance, $apply] = $this->fixture();
        $source = $this->runGoverned($governance, $apply, 'source', ['stable_key' => $this->prefix . '-relation-failure-source', 'title' => 'Relation failure source', 'source_type' => 'catalog']);
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-relation-failure-claim', 'text' => 'Relation failure claim', 'claim_type' => 'fact']);
        $evidence = $this->runGoverned($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Relation failure evidence', 'relation' => 'supports']);
        $videoId = UuidCodec::newV7();
        $missingTarget = UuidCodec::newV7();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'video', 'ingest', [
            'canonical_id' => $videoId,
            'url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => 'Relation rollback video',
            'metadata' => $this->videoMetadata([[
                'target_type' => 'variant', 'target_uuid' => $missingTarget, 'predicate' => 'about',
                'evidence_refs' => [['evidence_id' => $evidence['canonical_id']]],
            ]]),
        ], hash('sha256', $videoId), null, hash('sha256', 'video-relation-failure'), ProposalState::APPROVED, idempotencyKey: $this->prefix . '-video-relation-failure', entityType: 'video'));

        try { $apply->apply($proposal->id); self::fail('Expected relation endpoint failure.'); }
        catch (\Throwable $error) { self::assertSame('Endpoint does not exist: variant:' . $missingTarget, $error->getMessage()); }
        self::assertNull((new WpdbVideoRepository($GLOBALS['wpdb']))->findByCanonicalId($videoId));
        self::assertNull((new WpdbGraphRepository($GLOBALS['wpdb']))->findEdge(new NodeReference('video', $videoId), 'about', new NodeReference('variant', $missingTarget)));
        self::assertNotNull($variant);
    }

    public function test_video_activation_phase_failure_rolls_back_video_and_relation(): void
    {
        $hook = new class implements ApplyExecutionHook {
            public function afterAttemptStarted(): void {}
            public function afterAuthorityMutation(): void { throw new \RuntimeException('VIDEO_ACTIVATION_PHASE_FAILED'); }
            public function beforeProposalApplied(): void {}
            public function beforeCommit(): void {}
        };
        [, $variant, $governance, $apply] = $this->fixture($hook);
        $source = $this->runGoverned($governance, $apply, 'source', ['stable_key' => $this->prefix . '-activation-failure-source', 'title' => 'Activation failure source', 'source_type' => 'catalog']);
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-activation-failure-claim', 'text' => 'Activation failure claim', 'claim_type' => 'fact']);
        $evidence = $this->runGoverned($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Activation failure evidence', 'relation' => 'supports']);
        $videoId = UuidCodec::newV7();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'video', 'ingest', [
            'canonical_id' => $videoId,
            'url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => 'Activation rollback video',
            'metadata' => $this->videoMetadata([[
                'target_type' => 'variant', 'target_uuid' => $variant->canonicalId, 'predicate' => 'about',
                'evidence_refs' => [['evidence_id' => $evidence['canonical_id']]],
            ]]),
        ], hash('sha256', $videoId), null, hash('sha256', 'video-activation-failure'), ProposalState::APPROVED, idempotencyKey: $this->prefix . '-video-activation-failure', entityType: 'video'));

        try { $apply->apply($proposal->id); self::fail('Expected activation phase failure.'); }
        catch (\Throwable $error) { self::assertSame('VIDEO_ACTIVATION_PHASE_FAILED', $error->getMessage()); }
        self::assertNull((new WpdbVideoRepository($GLOBALS['wpdb']))->findByCanonicalId($videoId));
    }

    public function test_fail_closed_rejects_proposal_uuid_as_source_dependency(): void
    {
        [, , $governance, ] = $this->fixture();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'source', 'ingest', ['stable_key' => $this->prefix . '-invalid-source'], hash('sha256', 'invalid-source'), null, hash('sha256', 'invalid-source-dependency'), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-invalid-source', entityType: 'source'));
        $validator = new CanonicalDependencyValidator(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb']));
        $this->expectExceptionMessage('Source must resolve by canonical UUID.');
        $validator->source($proposal->id);
    }

    public function test_fail_closed_rejects_proposal_uuid_as_evidence_dependency(): void
    {
        [, , $governance, ] = $this->fixture();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'evidence', 'ingest', ['excerpt' => 'invalid'], hash('sha256', 'invalid-evidence'), null, hash('sha256', 'invalid-evidence-dependency'), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-invalid-evidence', entityType: 'evidence'));
        $validator = new CanonicalDependencyValidator(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb']));
        $this->expectExceptionMessage('Evidence must resolve by canonical UUID.');
        $validator->evidence($proposal->id);
    }

    public function test_fail_closed_requires_evidence_refs_and_keeps_private_evidence_internal_only(): void
    {
        [, $variant, $governance, $apply] = $this->fixture();
        $source = $this->runGoverned($governance, $apply, 'source', ['stable_key' => $this->prefix . '-private-source', 'title' => 'Private', 'source_type' => 'archive', 'locator' => 'https://example.test/private']);
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-private-claim', 'text' => 'Private claim', 'claim_type' => 'fact']);
        $evidence = $this->runGoverned($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Private excerpt', 'metadata' => ['visibility' => 'PRIVATE']]);
        $this->expectExceptionMessage('EVIDENCE_REFS_REQUIRED');
        $this->runGoverned($governance, $apply, 'video', [
            'url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => 'No refs',
            'metadata' => [
                'intake_version' => 1,
                'semantic_attachments' => [[
                    'target_type' => 'variant',
                    'target_key' => $variant->canonicalId,
                    'predicate' => 'about',
                    'origin' => 'EXPLICIT_USER_RELATION',
                    'evidence_refs' => [],
                ]],
            ],
        ]);
        self::assertNotNull((new WpdbEvidenceRepository($GLOBALS['wpdb']))->findByCanonicalId($evidence['canonical_id']));
        self::assertSame(404, rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/evidence/' . $evidence['canonical_id']))->get_status());
    }

    public function test_fail_closed_rejects_inactive_canonical_claim(): void
    {
        [, , $governance, $apply] = $this->fixture();
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-inactive-claim', 'text' => 'Inactive claim', 'claim_type' => 'fact']);
        $validator = new CanonicalDependencyValidator(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb']));
        (new KnowledgeService(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb'])))->retireClaim($claim['canonical_id'], 1);
        $this->expectExceptionMessage('Claim dependency is not active.');
        $validator->claim($claim['canonical_id']);
    }

    public function test_same_idempotency_key_reuses_same_command_and_rejects_changed_fingerprint(): void
    {
        [, , $governance, ] = $this->fixture();
        $key = $this->prefix . '-idempotency';
        $payload = ['stable_key' => $this->prefix . '-idempotent', 'title' => 'Same source', 'source_type' => 'catalog'];
        $one = new Proposal(UuidCodec::newV7(), 'source', 'ingest', $payload, hash('sha256', 'same'), null, hash('sha256', 'deps'), ProposalState::DRAFT, idempotencyKey: $key, entityType: 'source');
        $same = new Proposal(UuidCodec::newV7(), 'source', 'ingest', $payload, hash('sha256', 'same'), null, hash('sha256', 'deps'), ProposalState::DRAFT, idempotencyKey: $key, entityType: 'source');
        self::assertSame($governance->create($one)->id, $governance->create($same)->id);
        $changed = new Proposal(UuidCodec::newV7(), 'source', 'ingest', ['stable_key' => $this->prefix . '-changed'], hash('sha256', 'changed'), null, hash('sha256', 'deps'), ProposalState::DRAFT, idempotencyKey: $key, entityType: 'source');
        $this->expectExceptionMessage('Idempotency key is already bound to different content.');
        $governance->create($changed);
    }

    public function test_dependency_revision_change_rejects_apply(): void
    {
        [$authority, $variant, $governance, $apply] = $this->fixture();
        $proposal = new Proposal(UuidCodec::newV7(), 'variant', 'update', ['entity_payload' => ['description' => 'stale'], 'dependency_revisions' => [$variant->canonicalId => 1]], hash('sha256', 'stale'), 1, hash('sha256', 'stale-deps'), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-stale', targetUuid: $variant->canonicalId, entityType: 'variant');
        $proposal = $governance->create($proposal);
        $proposal = $governance->approve($governance->submit($proposal->id)->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'test-policy');
        $authority->update($variant->canonicalId, ['description' => 'changed first'], 1);
        $this->expectExceptionMessage('Proposal is not eligible for apply.');
        $apply->apply($proposal->id);
    }

    public function test_canonical_readback_failure_blocks_downstream_result(): void
    {
        [, , $governance, ] = $this->fixture();
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), 'source', 'ingest', ['stable_key' => $this->prefix . '-readback', 'title' => 'Readback', 'source_type' => 'catalog'], hash('sha256', 'readback'), null, hash('sha256', 'deps'), ProposalState::APPROVED, idempotencyKey: $this->prefix . '-readback', entityType: 'source'));
        $verifier = new CanonicalApplyReadBackVerifier(static fn (string $type, string $id): ?array => null);
        $this->expectExceptionMessage('CANONICAL_READBACK_VERIFICATION_FAILED');
        $verifier->verify($proposal, UuidCodec::newV7());
    }

    private function fixture(?ApplyExecutionHook $hook = null): array
    {
        global $wpdb;
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new WpdbAuthorityRepository($wpdb); $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', $this->prefix . '-brand', 'Governed test brand');
        $model = $authority->create('model', $this->prefix . '-model', 'Governed test model', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', $this->prefix . '-variant', 'Governed test variant', ['model_uuid' => $model->canonicalId]);
        array_push($this->owned, $brand->canonicalId, $model->canonicalId, $variant->canonicalId);
        $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb); $videos = new WpdbVideoRepository($wpdb);
        $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authorityRepo, new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb), $videos, $claims, $sources, $evidence);
        $graph = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, new PredicateRegistry(), new WpdbAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
        $proposalRepo = new WpdbProposalRepository($wpdb); $eligibility = new ProposalEligibilityService($proposalRepo, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authorityRepo, $proposalRepo, new WpdbGraphRepository($wpdb), null, $videos, $claims, $sources, $evidence));
        $knowledge = new KnowledgeService($claims, $sources, $evidence); $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
        $executor = new \NHK\Core\Application\Governance\AuthorityProposalExecutor($authority, $graph, null, new VideoService($videos), $knowledge, null, null, null, $dependencyValidator);
        $reader = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($authorityRepo, $claims, $sources, $evidence, $videos, $graph): ?array { $entity = match ($type) { 'source' => $sources->findByCanonicalId($id), 'knowledge' => $claims->findByCanonicalId($id), 'evidence' => $evidence->findByCanonicalId($id), 'video' => $videos->findByCanonicalId($id), 'relation' => $graph->findByUuid($id), default => $authorityRepo->findByCanonicalId($id) }; if ($entity === null) return null; return ['entity_type' => $type, 'canonical_id' => $id, 'active' => property_exists($entity, 'active') ? (bool) $entity->active : (method_exists($entity, 'isActive') ? $entity->isActive() : true), 'revision' => property_exists($entity, 'revision') ? (int) $entity->revision : 1, 'snapshot' => ['id' => $id]]; });
        $audit = new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb);
        $apply = new ControlledApplyService($proposalRepo, new WpdbApplyAttemptRepository($wpdb), new WpdbTransactionManager($wpdb), $executor, $audit, $eligibility, $hook, new class implements GovernanceAuthorizer { public function require(string $capability): void {} }, $reader);
        return [$authority, $variant, new GovernanceService($proposalRepo, $audit, new WpdbTransactionManager($wpdb)), $apply];
    }

    private function runGoverned(GovernanceService $governance, ControlledApplyService $apply, string $type, array $payload): array
    {
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), $type, 'ingest', $payload, hash('sha256', json_encode($payload)), null, hash('sha256', $type), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-' . $type . '-' . count($this->owned), entityType: $type));
        self::assertNotNull((new WpdbProposalRepository($GLOBALS['wpdb']))->find($proposal->id));
        $proposal = $governance->submit($proposal->id); $proposal = $governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'test-policy');
        self::assertTrue($governance->review($proposal->id)->state === ProposalState::APPROVED);
        self::assertTrue($apply->apply($proposal->id)['canonical_readback'] !== null);
        $result = $apply->apply($proposal->id); $id = (string) $result['canonical_id']; $this->owned[] = $id;
        return ['proposal_id' => $proposal->id, 'canonical_id' => $id, 'canonical_readback' => $result['canonical_readback']];
    }

    private function videoMetadata(array $attachments, bool $staleCompleteness = false): array
    {
        return ['intake_version' => 1, 'completeness' => ['blockers' => $staleCompleteness ? ['NO_SEMANTIC_ATTACHMENT'] : []], 'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true], 'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE', 'editorial' => ['title' => 'Governed video', 'summary' => 'Summary', 'body' => 'Body'], 'category' => ['primary' => ['key' => '01']], 'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'seo' => ['title' => 'Governed video', 'description' => 'Summary'], 'semantic_attachments' => $attachments];
    }
}
