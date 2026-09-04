<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{CanonicalApplyReadBackVerifier, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeService};
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Governance\GovernanceAuthorizer;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, CoreEndpointResolverRegistrar, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbAuditSink, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, GovernanceMigration003, GraphMigration001, KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005, MediaMigration004};
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
        (new MediaMigration004())->up();
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
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE edge_uuid IN (SELECT edge_uuid FROM {$wpdb->prefix}nhk_graph_edges) AND source_node_id IN (SELECT id FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN (%s,%s))", $this->owned[0] ?? '', $this->owned[1] ?? ''));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN (%s,%s)", ...[$this->owned[0] ?? '', $this->owned[1] ?? '']));
    }

    public function test_governed_full_flow_reads_back_source_claim_evidence_video_and_variant_relation(): void
    {
        [$authority, $variant, $governance, $apply] = $this->fixture();
        $source = $this->run($governance, $apply, 'source', ['stable_key' => $this->prefix . '-source', 'title' => 'Independent catalogue', 'source_type' => 'catalog', 'locator' => 'https://example.test/source']);
        $claim = $this->run($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-claim', 'text' => 'The variant uses a spring-driven movement.', 'claim_type' => 'technical', 'provenance' => ['test' => $this->prefix]]);
        $evidence = $this->run($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Spring-driven movement', 'relation' => 'supports', 'locator' => 'https://example.test/source#movement', 'metadata' => ['visibility' => 'PUBLIC']]);
        $video = $this->run($governance, $apply, 'video', [
            'url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => 'Governed test video',
            'metadata' => ['intake_version' => 1, 'semantic_attachments' => [[
                'target_type' => 'variant', 'target_key' => $variant->canonicalId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION',
                'evidence_refs' => [['evidence_id' => $evidence['canonical_id']]],
            ]]],
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
        $proposal = $governance->createFromArguments(['operation' => 'ingest', 'entity_type' => 'source', 'idempotency_key' => $this->prefix . '-invalid', 'payload' => ['stable_key' => $this->prefix . '-invalid']]);
        $validator = new CanonicalDependencyValidator(new WpdbKnowledgeRepository($GLOBALS['wpdb']), new WpdbSourceRepository($GLOBALS['wpdb']), new WpdbEvidenceRepository($GLOBALS['wpdb']));
        $this->expectExceptionMessage('Claim must resolve by canonical UUID.');
        $validator->claim($proposal->id);
    }

    public function test_fail_closed_requires_evidence_refs_and_keeps_private_evidence_internal_only(): void
    {
        [, $variant, $governance, $apply] = $this->fixture();
        $source = $this->run($governance, $apply, 'source', ['stable_key' => $this->prefix . '-private-source', 'title' => 'Private', 'source_type' => 'archive', 'locator' => 'https://example.test/private']);
        $claim = $this->run($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-private-claim', 'text' => 'Private claim', 'claim_type' => 'fact']);
        $evidence = $this->run($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Private excerpt', 'metadata' => ['visibility' => 'PRIVATE']]);
        $this->expectExceptionMessage('EVIDENCE_REFS_REQUIRED');
        $this->run($governance, $apply, 'video', ['url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(8)), 0, 11), 'title' => 'No refs', 'metadata' => ['intake_version' => 1, 'semantic_attachments' => [['target_type' => 'variant', 'target_key' => $variant->canonicalId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION', 'evidence_refs' => []]]]);
        self::assertNotNull((new WpdbEvidenceRepository($GLOBALS['wpdb']))->findByCanonicalId($evidence['canonical_id']));
    }

    private function fixture(): array
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
        $graph = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, new PredicateRegistry(), new WpdbAuditSink($wpdb));
        $proposalRepo = new WpdbProposalRepository($wpdb); $eligibility = new ProposalEligibilityService($proposalRepo, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authorityRepo, $proposalRepo, new WpdbGraphRepository($wpdb), null, $videos, $claims, $sources, $evidence));
        $knowledge = new KnowledgeService($claims, $sources, $evidence); $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
        $executor = new \NHK\Core\Application\Governance\AuthorityProposalExecutor($authority, $graph, null, new VideoService($videos), $knowledge, null, null, null, $dependencyValidator);
        $reader = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($authorityRepo, $claims, $sources, $evidence, $videos, $graph): ?array { $entity = match ($type) { 'source' => $sources->findByCanonicalId($id), 'knowledge' => $claims->findByCanonicalId($id), 'evidence' => $evidence->findByCanonicalId($id), 'video' => $videos->findByCanonicalId($id), 'relation' => $graph->findByUuid($id), default => $authorityRepo->findByCanonicalId($id) }; if ($entity === null) return null; return ['entity_type' => $type, 'canonical_id' => $id, 'active' => property_exists($entity, 'active') ? (bool) $entity->active : (method_exists($entity, 'isActive') ? $entity->isActive() : true), 'revision' => property_exists($entity, 'revision') ? (int) $entity->revision : 1, 'snapshot' => ['id' => $id]]; });
        $apply = new ControlledApplyService($proposalRepo, new WpdbApplyAttemptRepository($wpdb), new WpdbTransactionManager($wpdb), $executor, new WpdbAuditSink($wpdb), $eligibility, null, new class implements GovernanceAuthorizer { public function require(string $capability): void {} }, $reader);
        return [$authority, $variant, new GovernanceService($proposalRepo, new WpdbAuditSink($wpdb), new WpdbTransactionManager($wpdb)), $apply];
    }

    private function run(GovernanceService $governance, ControlledApplyService $apply, string $type, array $payload): array
    {
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), $type, 'ingest', $payload, hash('sha256', json_encode($payload)), null, hash('sha256', $type), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-' . $type . '-' . count($this->owned), entityType: $type));
        $proposal = $governance->submit($proposal->id); $proposal = $governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'test-policy');
        self::assertTrue($governance->review($proposal->id)->state === ProposalState::APPROVED);
        self::assertTrue($apply->apply($proposal->id)['canonical_readback'] !== null);
        $result = $apply->apply($proposal->id); $id = (string) $result['canonical_id']; $this->owned[] = $id;
        return ['proposal_id' => $proposal->id, 'canonical_id' => $id, 'canonical_readback' => $result['canonical_readback']];
    }
}
