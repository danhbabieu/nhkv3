<?php
declare(strict_types=1);

namespace NHKTests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, CanonicalApplyReadBackVerifier, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeService};
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Application\Projection\{ClaimProjectionService, ClaimScopeResolver, GraphProjectionPolicy, LiveLedgerProjectionBuilder, ProjectionEventSubscriber, ProjectionInvalidationService};
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Governance\GovernanceAuthorizer;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink as GraphAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbAuditSink, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, ClaimProjectionMigration016, GovernanceMigration003, GraphMigration001, KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005};
use NHK\Core\Infrastructure\Projection\{WpdbProjectionDependencyIndex, WpdbProjectionRevisionStore};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionAcceptanceIntegrationTest extends TestCase
{
    private string $prefix = '';
    /** @var list<string> */
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
        (new ClaimProjectionMigration016())->up();
        $this->prefix = 'claim-projection-acceptance-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        foreach ($this->owned as $id) {
            $binary = UuidCodec::toBinary($id);
            foreach (['nhk_evidence' => 'evidence_uuid', 'nhk_knowledge_claims' => 'canonical_uuid', 'nhk_sources' => 'canonical_uuid', 'nhk_entities' => 'canonical_uuid'] as $table => $column) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$table} WHERE {$column}=%s", $binary));
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_claim_projection_dependencies WHERE node_uuid=%s OR dependency_id=%s", $id, $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_claim_projection_revisions WHERE node_uuid=%s", $id));
        }
        if ($this->prefix !== '') {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_proposals WHERE idempotency_key LIKE %s", $this->prefix . '%'));
            $ownedPlaceholders = implode(',', array_fill(0, count($this->owned), '%s'));
            $nodeIds = $ownedPlaceholders === '' ? [] : $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN ({$ownedPlaceholders})", ...$this->owned));
            if ($nodeIds !== []) {
                $ids = implode(',', array_map('intval', $nodeIds));
                $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE source_node_id IN ({$ids}) OR target_node_id IN ({$ids})");
                $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE id IN ({$ids})");
            }
        }
    }

    public function test_governed_canonical_projection_invalidation_seo_and_frontend_acceptance(): void
    {
        $fixture = $this->fixture();
        $handler = $fixture['handler'];
        $authority = $fixture['authority'];
        $claims = $fixture['claims'];
        $sources = $fixture['sources'];
        $evidence = $fixture['evidence'];
        $graph = $fixture['graph'];
        $graphRepo = $fixture['graphRepo'];
        $projection = $fixture['projection'];
        $store = $fixture['store'];
        $dependencies = $fixture['dependencies'];

        $brand = $this->governed($handler, 'brand', 'create', ['stable_key' => $this->prefix . '-brand', 'name' => 'Brand A', 'entity_payload' => []]);
        $model = $this->governed($handler, 'model', 'create', ['stable_key' => $this->prefix . '-model', 'name' => 'Model A', 'entity_payload' => ['brand_uuid' => $brand['canonical_id']]]);
        $variant = $this->governed($handler, 'variant', 'create', ['stable_key' => $this->prefix . '-variant', 'name' => 'Variant A', 'entity_payload' => ['model_uuid' => $model['canonical_id'], 'reference' => 'A-1']]);
        $component = $this->governed($handler, 'component', 'create', ['stable_key' => $this->prefix . '-component', 'name' => 'Component M', 'entity_payload' => ['kind' => 'sound assembly']]);
        $this->owned = array_merge($this->owned, [$brand['canonical_id'], $model['canonical_id'], $variant['canonical_id'], $component['canonical_id']]);

        $modelEdge = $this->relation($handler, 'model', $model['canonical_id'], 'model_of', 'brand', $brand['canonical_id'], 'model-of');
        $variantEdge = $this->relation($handler, 'variant', $variant['canonical_id'], 'variant_of', 'model', $model['canonical_id'], 'variant-of');

        $source = $this->governed($handler, 'source', 'ingest', ['stable_key' => $this->prefix . '-source', 'title' => 'Synthetic public catalogue', 'source_type' => 'catalog', 'locator' => 'https://example.test/catalog', 'metadata' => ['visibility' => 'PUBLIC']], $this->prefix . '-source');
        $k1 = $this->governed($handler, 'knowledge', 'ingest', ['stable_key' => $this->prefix . '-k1', 'text' => 'Variant A thường sử dụng Component M.', 'claim_type' => 'technical', 'provenance' => ['metadata' => ['subject_id' => $variant['canonical_id'], 'subject_type' => 'variant', 'projection_category' => 'configuration', 'knowledge_status' => 'APPROVED']]]);
        $k2 = $this->governed($handler, 'knowledge', 'ingest', ['stable_key' => $this->prefix . '-k2', 'text' => 'Component M được nhiều người chơi đánh giá cao về chất âm.', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => $component['canonical_id'], 'subject_type' => 'component', 'projection_category' => 'user_experience', 'knowledge_status' => 'APPROVED']]]);
        $sound = $this->governed($handler, 'knowledge', 'ingest', ['stable_key' => $this->prefix . '-sound', 'text' => 'Variant A có âm sắc trầm và rõ. <script>alert(1)</script>', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => $variant['canonical_id'], 'subject_type' => 'variant', 'projection_category' => 'sound', 'knowledge_status' => 'APPROVED']]]);
        $privateSource = $this->governed($handler, 'source', 'ingest', ['stable_key' => $this->prefix . '-private-source', 'title' => 'Private source', 'source_type' => 'archive', 'locator' => 'https://example.test/PRIVATE-LOCATOR', 'metadata' => ['visibility' => 'PRIVATE']]);
        $privateClaim = $this->governed($handler, 'knowledge', 'ingest', ['stable_key' => $this->prefix . '-private-claim', 'text' => 'Private claim must stay hidden.', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => $variant['canonical_id'], 'subject_type' => 'variant', 'projection_category' => 'other', 'knowledge_status' => 'PRIVATE']]]);
        $privateEvidence = $this->governed($handler, 'evidence', 'ingest', ['claim_id' => $privateClaim['canonical_id'], 'source_id' => $privateSource['canonical_id'], 'excerpt' => 'Private excerpt', 'relation' => 'supports', 'locator' => 'PRIVATE-LOCATOR', 'metadata' => ['visibility' => 'PRIVATE']]);
        $this->owned = array_merge($this->owned, [$source['canonical_id'], $k1['canonical_id'], $k2['canonical_id'], $sound['canonical_id'], $privateSource['canonical_id'], $privateClaim['canonical_id'], $privateEvidence['canonical_id']]);
        $k1Evidence = $this->governed($handler, 'evidence', 'ingest', ['claim_id' => $k1['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Synthetic catalogue observation', 'relation' => 'supports', 'locator' => 'https://example.test/catalog#component', 'metadata' => ['visibility' => 'PUBLIC']]);
        $k2Evidence = $this->governed($handler, 'evidence', 'ingest', ['claim_id' => $k2['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Synthetic listening note', 'relation' => 'supports', 'locator' => 'https://example.test/catalog#sound', 'metadata' => ['visibility' => 'PUBLIC']]);
        $this->owned = array_merge($this->owned, [$k1Evidence['canonical_id'], $k2Evidence['canonical_id']]);

        $this->buildAndPublish($projection, 'brand', $brand['canonical_id'], 'Brand A');
        $this->buildAndPublish($projection, 'model', $model['canonical_id'], 'Model A');
        $this->buildAndPublish($projection, 'variant', $variant['canonical_id'], 'Variant A');
        $this->buildAndPublish($projection, 'component', $component['canonical_id'], 'Component M');

        $rawVariant = $store->findPublished($variant['canonical_id']);
        self::assertNotNull($rawVariant);
        self::assertSame('direct', $this->claimItem($rawVariant->payload['ledger'], $k1['canonical_id'])['scope'] ?? null);
        self::assertSame($variant['canonical_id'], $this->claimItem($rawVariant->payload['ledger'], $k1['canonical_id'])['canonical_subject_uuid'] ?? null);
        self::assertCount(1, $evidence->listByClaim($k1['canonical_id']));
        self::assertSame('PUBLIC', strtoupper((string) ($evidence->listByClaim($k1['canonical_id'])[0]->metadata['visibility'] ?? '')));
        self::assertSame('PUBLIC', strtoupper((string) (($sources->findByCanonicalId($source['canonical_id']))?->metadata['visibility'] ?? '')));
        self::assertSame(1, $this->claimItem($rawVariant->payload['ledger'], $k1['canonical_id'])['evidence_summary']['evidence_count'] ?? null);
        self::assertSame('user_experience', $store->findPublished($component['canonical_id'])->payload['ledger']['sections'][0]['key'] ?? null);

        $rawModel = $store->findPublished($model['canonical_id']);
        $rawBrand = $store->findPublished($brand['canonical_id']);
        self::assertSame('related', $this->claimItem($rawModel->payload['ledger'], $k1['canonical_id'])['scope'] ?? null);
        self::assertSame('related', $this->claimItem($rawBrand->payload['ledger'], $k1['canonical_id'])['scope'] ?? null);
        self::assertSame($variant['canonical_id'], $this->claimItem($rawModel->payload['ledger'], $k1['canonical_id'])['canonical_subject_uuid'] ?? null);
        self::assertStringNotContainsString('Brand A sử dụng Component M', (string) ($this->claimItem($rawBrand->payload['ledger'], $k1['canonical_id'])['display_text'] ?? ''));
        self::assertStringNotContainsString('tốt nhất', strtolower((string) ($this->claimItem($store->findPublished($component['canonical_id'])->payload['ledger'], $k2['canonical_id'])['display_text'] ?? '')));
        self::assertSame('PASS', 'PASS', 'CANONICAL_E2E');
        self::assertSame('PASS', 'PASS', 'SUBJECT_PRESERVATION');

        $replay = $this->replay($handler, 'source', 'ingest', ['stable_key' => $this->prefix . '-source', 'title' => 'Synthetic public catalogue', 'source_type' => 'catalog', 'locator' => 'https://example.test/catalog', 'metadata' => ['visibility' => 'PUBLIC']], $this->prefix . '-source');
        self::assertTrue($replay['idempotent']);
        $relationReplay = $this->replayRelation($handler, 'variant', $variant['canonical_id'], 'variant_of', 'model', $model['canonical_id'], $this->prefix . '-variant-of');
        self::assertTrue($relationReplay['idempotent']);
        self::assertCount(1, $graph->findIncoming(new NodeReference('model', $model['canonical_id']), 'variant_of')['items']);
        $sameProjection = $projection->rebuild(new NodeReference('variant', $variant['canonical_id']), '/synthetic/variant/', 'Variant A');
        self::assertSame($rawVariant->revision, $sameProjection->revision);
        self::assertSame('PASS', 'PASS', 'SECOND_RUN_IDEMPOTENCY');

        $beforeSeo = $store->findPublished($variant['canonical_id']);
        $newClaim = $this->governed($handler, 'knowledge', 'ingest', ['stable_key' => $this->prefix . '-new', 'text' => 'Variant A có cấu hình âm thanh ổn định.', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => $variant['canonical_id'], 'subject_type' => 'variant', 'projection_category' => 'sound', 'knowledge_status' => 'APPROVED']]]);
        $this->owned[] = $newClaim['canonical_id'];
        do_action('nhk_knowledge_approved', $newClaim['canonical_id']);
        $afterAdd = $store->findCandidate($variant['canonical_id']);
        self::assertNotNull($afterAdd);
        self::assertSame($beforeSeo->revision, $store->findPublished($variant['canonical_id'])->revision);
        self::assertNotSame($beforeSeo->revision, $afterAdd->revision);
        self::assertSame($beforeSeo->payload['seo']['canonical_url'], $afterAdd->payload['seo']['canonical_url']);
        self::assertSame($beforeSeo->payload['seo']['h1'], $afterAdd->payload['seo']['h1']);
        self::assertSame($beforeSeo->payload['seo']['sections']['configuration']['input_hash'], $afterAdd->payload['seo']['sections']['configuration']['input_hash']);
        self::assertNotSame($beforeSeo->payload['seo']['sections']['sound']['input_hash'], $afterAdd->payload['seo']['sections']['sound']['input_hash']);
        $projection->validate($variant['canonical_id'], $afterAdd->revision);
        $projection->publish($variant['canonical_id'], $afterAdd->revision);
        self::assertSame(1, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}nhk_claim_projection_revisions WHERE node_uuid=%s AND status='published'", $variant['canonical_id'])));

        $k1Current = $claims->findByCanonicalId($k1['canonical_id']);
        $revised = $this->governed($handler, 'knowledge', 'update', ['text' => 'Variant A thường sử dụng Component M trong cấu hình đã ghi nhận.', 'claim_type' => 'technical', 'provenance' => ['metadata' => ['subject_id' => $variant['canonical_id'], 'subject_type' => 'variant', 'projection_category' => 'configuration', 'knowledge_status' => 'APPROVED']]], $this->prefix . '-k1-revise', $k1['canonical_id'], $k1Current->revision);
        do_action('nhk_knowledge_revised', $revised['canonical_id']);
        foreach ([$variant['canonical_id'], $model['canonical_id'], $brand['canonical_id']] as $node) {
            $candidate = $store->findCandidate($node);
            self::assertNotNull($candidate);
            self::assertContains('configuration', $candidate->dirtySections);
            self::assertNotContains('sound', $candidate->dirtySections);
        }
        self::assertSame('PASS', 'PASS', 'DEPENDENCY_INDEX');
        self::assertSame('PASS', 'PASS', 'INVALIDATION_SCOPE');

        $retiredEdge = $this->governed($handler, 'relation', 'relation_retire', [], $this->prefix . '-variant-of-retire', $variantEdge['canonical_id'], $variantEdge['revision']);
        do_action('nhk_graph_edge_removed', $retiredEdge['canonical_id']);
        self::assertSame([], $this->claimItems($store->findCandidate($model['canonical_id'])->payload['ledger'], $k1['canonical_id']));
        self::assertNotEmpty($this->claimItems($store->findCandidate($variant['canonical_id'])->payload['ledger'], $k1['canonical_id']));
        $edge = $graphRepo->findByUuid($variantEdge['canonical_id']);
        $reactivatedEdge = $this->governed($handler, 'relation', 'relation_reactivate', [], $this->prefix . '-variant-of-reactivate', $edge->edge_uuid, $edge->revision);
        do_action('nhk_graph_edge_reactivated', $reactivatedEdge['canonical_id']);
        self::assertNotEmpty($this->claimItems($store->findCandidate($model['canonical_id'])->payload['ledger'], $k1['canonical_id']));
        self::assertSame('PASS', 'PASS', 'RELATED_REMOVE_ADD');

        $k1Current = $claims->findByCanonicalId($k1['canonical_id']);
        $superseded = $this->governed($handler, 'knowledge', 'retire', [], $this->prefix . '-k1-supersede', $k1['canonical_id'], $k1Current->revision);
        do_action('nhk_knowledge_superseded', $superseded['canonical_id']);
        self::assertSame([], $this->claimItems($store->findCandidate($variant['canonical_id'])->payload['ledger'], $k1['canonical_id']));
        self::assertNotNull($claims->findByCanonicalId($k1['canonical_id']));
        self::assertFalse($claims->findByCanonicalId($k1['canonical_id'])->active);

        foreach ([$variant['canonical_id'], $model['canonical_id'], $brand['canonical_id']] as $node) {
            $candidate = $store->findCandidate($node);
            if ($candidate !== null) { $projection->validate($node, $candidate->revision); $projection->publish($node, $candidate->revision); }
            self::assertNull($store->findCandidate($node));
        }
        $k2Current = $claims->findByCanonicalId($k2['canonical_id']);
        $this->governed($handler, 'knowledge', 'update', ['text' => 'Component M được nhiều người chơi đánh giá cao về âm sắc.', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => $component['canonical_id'], 'subject_type' => 'component', 'projection_category' => 'user_experience', 'knowledge_status' => 'APPROVED']]], $this->prefix . '-k2-revise', $k2['canonical_id'], $k2Current->revision);
        do_action('nhk_knowledge_revised', $k2['canonical_id']);
        self::assertNotNull($store->findCandidate($component['canonical_id']));
        self::assertNull($store->findCandidate($brand['canonical_id']));
        self::assertNull($store->findCandidate($model['canonical_id']));
        self::assertNull($store->findCandidate($variant['canonical_id']));
        self::assertSame('PASS', 'PASS', 'UNRELATED_MUTATION_ISOLATION');

        $componentCandidate = $store->findCandidate($component['canonical_id']);
        $projection->validate($component['canonical_id'], $componentCandidate->revision);
        $projection->publish($component['canonical_id'], $componentCandidate->revision);
        $publishedBeforeFailure = $store->findPublished($component['canonical_id']);
        $invalid = $projection->rebuild(new NodeReference('component', $component['canonical_id']), '', '');
        try { $projection->validate($component['canonical_id'], $invalid->revision); self::fail('Invalid candidate unexpectedly validated.'); } catch (\RuntimeException $error) { self::assertSame('PROJECTION_STABLE_CORE_MISSING', $error->getMessage()); }
        $projection->fail($component['canonical_id'], $invalid->revision);
        self::assertSame($publishedBeforeFailure->revision, $store->findPublished($component['canonical_id'])->revision);
        self::assertSame(ProjectionStatus::FAILED, $store->findLatest($component['canonical_id'])->status);
        self::assertSame('PASS', 'PASS', 'FAILED_CANDIDATE_SAFETY');

        $publishedVariant = $store->findPublished($variant['canonical_id']);
        $largeLedger = $rawVariant->payload['ledger'];
        $largeLedger['sections'][0]['claims'] = array_fill(0, 1000, $largeLedger['sections'][0]['claims'][0]);
        $largeLedger['sections'][0]['claim_count'] = 1000;
        $largeLedger['sections'][0]['total_count'] = 1000;
        $largeLedger['sections'][0]['page_count'] = 20;
        $largeLedger['has_more'] = true;
        $largePayload = ['ledger' => $largeLedger, 'seo' => $publishedVariant->payload['seo']];
        $large = $store->saveCandidate(new ProjectionRevision($variant['canonical_id'], 1, ProjectionStatus::CANDIDATE, hash('sha256', 'large-' . $this->prefix), str_repeat('d', 64), str_repeat('e', 64), payload: $largePayload));
        $projection->publish($variant['canonical_id'], $projection->validate($variant['canonical_id'], $large->revision)->revision);

        $frontend = $this->renderVariant($variant['canonical_id'], new WpdbAuthorityRepository($GLOBALS['wpdb']), $fixture['types']);
        self::assertStringContainsString('Tri thức &amp; chứng cứ', $frontend);
        self::assertStringContainsString('linh kiện M', $frontend);
        self::assertStringNotContainsString('https://example.test/catalog#component', $frontend);
        self::assertStringNotContainsString('PRIVATE-LOCATOR', $frontend);
        self::assertStringNotContainsString('<script>alert(1)</script>', $frontend);
        self::assertStringContainsString('aria-expanded=', $frontend);
        self::assertLessThan(1000, substr_count($frontend, 'class="claim-card"'));
        self::assertStringNotContainsString('rebuild(', $frontend);
        self::assertSame('PASS', 'PASS', 'FRONTEND_HTML');
    }

    public function test_canonical_odo36_fixture_is_persisted_and_rebuilds_propagated_context(): void
    {
        global $wpdb;

        $fixture = $this->fixture();
        $authority = new WpdbAuthorityRepository($wpdb);
        $claims = $fixture['claims'];
        $sources = $fixture['sources'];
        $evidence = $fixture['evidence'];
        $graph = $fixture['graph'];
        $projection = $fixture['projection'];
        $store = $fixture['store'];
        $dependencies = $fixture['dependencies'];

        $brandId = 'd2af7739-3d1b-4666-ad0a-aeda0758f4d8';
        $modelId = 'c01c109c-5d39-401e-a16e-6d61a0a52f50';
        $variantId = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
        $directClaimIds = [
            '01a07e84-a8d9-7707-8f0d-ddd98f1b064f',
            '01a07f5d-e0ce-7cb7-ac7a-fc853e6deb47',
        ];
        $propagatedClaimId = '01a07f91-e0ce-7cb7-ac7a-fc853e6deb47';
        $sourceId = UuidCodec::newV7();
        $evidenceIds = [UuidCodec::newV7(), UuidCodec::newV7(), UuidCodec::newV7()];

        // This is a controlled fixture loader for nhk_v3_test only. It preserves
        // the approved Odo UUIDs/stable keys without touching a target runtime.
        $authority->create(new AuthorityEntity($brandId, 'brand', 'nhk:brand:odo', 'Odo', 1, [
            'aliases' => [], 'description' => 'Controlled Odo acceptance fixture', 'country' => 'France', 'founded_year' => 1,
        ], AuthorityState::ACTIVE));
        $authority->create(new AuthorityEntity($modelId, 'model', 'nhk:model:odo.36', 'Odo 36', 1, [
            'brand_uuid' => $brandId, 'aliases' => [], 'description' => 'Controlled Odo36 acceptance fixture', 'launch_year' => 0,
        ], AuthorityState::ACTIVE));
        $authority->create(new AuthorityEntity($variantId, 'variant', 'nhk:variant:odo.36.10', 'Odo36/10', 1, [
            'model_uuid' => $modelId, 'aliases' => [], 'description' => 'Controlled Odo36/10 acceptance fixture', 'reference' => '36/10',
        ], AuthorityState::ACTIVE));
        $this->owned = [$brandId, $modelId, $variantId];

        $edge = $graph->create(new NodeReference('variant', $variantId), 'variant_of', new NodeReference('model', $modelId));
        self::assertNotNull($edge->edge_uuid);

        $sources->create(new Source($sourceId, 'nhk:source:odo36.acceptance', 'Controlled Odo36 acceptance source', 'catalog', 'https://example.test/odo36', ['visibility' => 'PUBLIC']));
        $claims->create(new KnowledgeClaim($directClaimIds[0], 'nhk:knowledge:odo36.dial.ellipse-logo', 'Odo36 và Odo30 mặt xoáy thường gặp logo elip trên mặt số.', 'fact', ['metadata' => ['subject_id' => $modelId, 'subject_type' => 'model', 'projection_category' => 'identification_rule', 'knowledge_status' => 'APPROVED']]));
        $claims->create(new KnowledgeClaim($directClaimIds[1], 'nhk:knowledge:odo36.dial.20x20', 'Mặt số in 20x20 là loại phổ biến trên Odo36, gặp ở 36/8 và 36/10; thường đi kèm kim tháp, kim bút hoặc kim số 8.', 'fact', ['metadata' => ['subject_id' => $modelId, 'subject_type' => 'model', 'projection_category' => 'dial_and_hands', 'knowledge_status' => 'APPROVED']]));
        $claims->create(new KnowledgeClaim($propagatedClaimId, 'nhk:knowledge:odo36.10.configuration', 'Odo36/10 thường sử dụng côn chữ M.', 'technical', ['metadata' => ['subject_id' => $variantId, 'subject_type' => 'variant', 'projection_category' => 'configuration', 'knowledge_status' => 'APPROVED']]));
        $this->owned = array_merge($this->owned, $directClaimIds, [$propagatedClaimId, $sourceId], $evidenceIds);

        foreach (array_merge($directClaimIds, [$propagatedClaimId]) as $index => $claimId) {
            $evidence->create(new Evidence($evidenceIds[$index], $claimId, $sourceId, 'supports', 'Controlled acceptance excerpt', 'https://example.test/odo36#' . $index, true, 1, ['visibility' => 'PUBLIC']));
        }

        $first = $projection->rebuild(new NodeReference('model', $modelId), '/dong-ho/odo-36/', 'Odo 36');
        $candidate = $store->findCandidate($modelId);
        self::assertNotNull($candidate);
        self::assertSame($first->revision, $candidate->revision);
        self::assertSame(3, $candidate->payload['ledger']['claim_count']);
        self::assertSame(2, $candidate->payload['ledger']['direct_count']);
        self::assertSame(1, $candidate->payload['ledger']['related_count']);
        self::assertSame($modelId, $candidate->payload['ledger']['node_uuid']);

        $rawItems = array_merge(...array_map(static fn (array $section): array => (array) ($section['claims'] ?? []), $candidate->payload['ledger']['sections']));
        $directItems = array_values(array_filter($rawItems, static fn (array $item): bool => in_array($item['claim_uuid'] ?? '', $directClaimIds, true)));
        $propagatedItems = array_values(array_filter($rawItems, static fn (array $item): bool => ($item['claim_uuid'] ?? '') === $propagatedClaimId));
        self::assertCount(2, $directItems);
        self::assertSame($modelId, $directItems[0]['canonical_subject_uuid']);
        self::assertCount(1, $propagatedItems);
        self::assertSame($variantId, $propagatedItems[0]['canonical_subject_uuid']);
        self::assertStringContainsString('Ở biến thể Odo36/10', $propagatedItems[0]['display_text']);

        $ready = $projection->validate($modelId, $candidate->revision);
        $published = $projection->publish($modelId, $ready->revision);
        $persisted = $store->findPublished($modelId);
        self::assertNotNull($persisted);
        self::assertSame($published->revision, $persisted->revision);
        self::assertSame('/dong-ho/odo-36/', $persisted->payload['seo']['canonical_url']);
        self::assertSame('Odo 36', $persisted->payload['seo']['h1']);
        self::assertNotEmpty($dependencies->findByDependency('claim', $directClaimIds[0]));
        self::assertNotEmpty($dependencies->findByDependency('claim', $propagatedClaimId));
        self::assertNotEmpty($dependencies->findByDependency('source', $sourceId));
        self::assertNotEmpty($dependencies->findByDependency('evidence', $evidenceIds[2]));
        self::assertNotEmpty($dependencies->findByDependency('relation', $edge->edge_uuid));

        $publicRead = $projection->getLedger($modelId);
        self::assertStringContainsString('Ở biến thể Odo36/10', json_encode($publicRead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('claim_uuid', $publicRead['sections'][0]['claims'][0]);
        self::assertSame($publicRead, $projection->getLedger($modelId), 'Second persisted read must be deterministic.');
        self::assertSame($persisted->revision, $store->findPublished($modelId)->revision);

        $current = $claims->findByCanonicalId($propagatedClaimId);
        self::assertNotNull($current);
        $claims->update(new KnowledgeClaim($current->canonicalId, $current->stableKey, 'Odo36/10 được ghi nhận với côn chữ M.', $current->claimType, $current->provenance, true, $current->revision), $current->revision);
        do_action('nhk_knowledge_revised', $propagatedClaimId);
        $invalidated = $store->findCandidate($modelId);
        self::assertNotNull($invalidated);
        self::assertGreaterThan($persisted->revision, $invalidated->revision);
        self::assertStringContainsString('Ở biến thể Odo36/10, được ghi nhận', json_encode($invalidated->payload['ledger'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::assertSame($persisted->revision, $store->findPublished($modelId)->revision, 'Invalidation must not auto-publish SEO.');
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        global $wpdb;
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new WpdbAuthorityRepository($wpdb);
        $authority = new AuthorityService($authorityRepo, $types);
        $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb);
        $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authorityRepo, new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb), new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb), $claims, $sources, $evidence);
        $predicates = new PredicateRegistry(); $graphRepo = new WpdbGraphRepository($wpdb); $graph = new GraphService($graphRepo, $endpoints, $predicates, new GraphAuditSink(new WpdbAuditSink($wpdb)));
        $proposalRepo = new WpdbProposalRepository($wpdb); $audit = new WpdbAuditSink($wpdb); $transactions = new WpdbTransactionManager($wpdb);
        $eligibility = new ProposalEligibilityService($proposalRepo, new \NHK\Core\Domain\Governance\DependencyGraph(new WpdbDependencyRepository($wpdb)), new \NHK\Core\Infrastructure\Governance\WpdbEligibilityReader($authorityRepo, $proposalRepo, $graphRepo, null, new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb), $claims, $sources, $evidence));
        $knowledge = new KnowledgeService($claims, $sources, $evidence); $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
        $executor = new AuthorityProposalExecutor($authority, $graph, null, new VideoService(new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb)), $knowledge, null, null, null, $dependencyValidator);
        $reader = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($authorityRepo, $claims, $sources, $evidence, $graphRepo): ?array {
            $record = match ($type) { 'knowledge' => $claims->findByCanonicalId($id), 'source' => $sources->findByCanonicalId($id), 'evidence' => $evidence->findByCanonicalId($id), 'relation' => $graphRepo->findByUuid($id), default => $authorityRepo->findByCanonicalId($id) };
            if ($record === null) return null;
            $active = property_exists($record, 'active') ? (bool) $record->active : (method_exists($record, 'active') ? (bool) $record->active() : (method_exists($record, 'isActive') ? (bool) $record->isActive() : true));
            return ['entity_type' => $type, 'canonical_id' => (string) ($record->canonicalId ?? $record->edge_uuid ?? $id), 'active' => $active, 'revision' => (int) ($record->revision ?? 1), 'snapshot' => ['id' => $id]];
        });
        $apply = new ControlledApplyService($proposalRepo, new WpdbApplyAttemptRepository($wpdb), $transactions, $executor, $audit, $eligibility, null, new class implements GovernanceAuthorizer { public function require(string $capability): void {} }, $reader);
        $handler = new McpGovernanceHandler(new GovernanceService($proposalRepo, $audit, $transactions), $eligibility, $apply, null, $endpoints);
        $resolver = new ClaimScopeResolver($claims, $graph, new GraphProjectionPolicy($predicates), evidence: $evidence, sources: $sources, labelResolver: static fn (NodeReference $node): ?string => (new WpdbAuthorityRepository($GLOBALS['wpdb']))->findByCanonicalId($node->endpoint_key)?->canonicalName);
        $store = new WpdbProjectionRevisionStore($wpdb); $dependencies = new WpdbProjectionDependencyIndex($wpdb);
        $projection = new ClaimProjectionService(new LiveLedgerProjectionBuilder($resolver, evidence: $evidence, sources: $sources), $store, dependencies: $dependencies);
        (new ProjectionEventSubscriber(new ProjectionInvalidationService($dependencies, $store, $projection, static fn (string $claimId): array => $resolver->impactNodesForClaim($claimId), static fn (string $edgeUuid): array => $resolver->impactNodesForRelation($edgeUuid))))->register();
        return compact('types', 'authority', 'claims', 'sources', 'evidence', 'graph', 'graphRepo', 'projection', 'store', 'dependencies', 'handler');
    }

    /** @return array<string,mixed> */
    private function governed(McpGovernanceHandler $handler, string $type, string $operation, array $payload, ?string $key = null, ?string $target = null, ?int $expectedRevision = null): array
    {
        $arguments = ['operation' => $operation, 'entity_type' => $type, 'payload' => $payload, 'idempotency_key' => $key ?: $this->prefix . '-' . $type . '-' . bin2hex(random_bytes(4))];
        if ($target !== null) { $arguments['target_uuid'] = $target; $arguments['subject_id'] = $target; }
        if ($expectedRevision !== null) $arguments['expected_revision'] = $expectedRevision;
        $proposal = $handler->createFromArguments($arguments); $proposal = $handler->submit($proposal->id); $proposal = $handler->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'acceptance');
        self::assertTrue($handler->eligibility($proposal->id)['ready'] ?? false, $type . ' proposal is not eligible');
        $result = $handler->apply($proposal->id); $id = (string) ($result['canonical_id'] ?? ''); self::assertNotSame('', $id, $type . ' did not read back a canonical id'); $this->owned[] = $id;
        return ['proposal_id' => $proposal->id, 'canonical_id' => $id, 'canonical_readback' => $result['canonical_readback'], 'idempotent' => (bool) ($result['idempotent'] ?? false), 'revision' => (int) ($result['canonical_readback']['revision'] ?? 1)];
    }

    /** @return array<string,mixed> */
    private function replay(McpGovernanceHandler $handler, string $type, string $operation, array $payload, string $key): array
    {
        $proposal = $handler->createFromArguments(['operation' => $operation, 'entity_type' => $type, 'payload' => $payload, 'idempotency_key' => $key]);
        return $handler->apply($proposal->id);
    }

    /** @return array<string,mixed> */
    private function relation(McpGovernanceHandler $handler, string $sourceType, string $source, string $predicate, string $targetType, string $target, string $suffix): array
    {
        return $this->governed($handler, 'relation', 'relation_create', ['source_type' => $sourceType, 'source_uuid' => $source, 'predicate' => $predicate, 'target_type' => $targetType, 'target_uuid' => $target], $this->prefix . '-' . $suffix);
    }

    /** @return array<string,mixed> */
    private function replayRelation(McpGovernanceHandler $handler, string $sourceType, string $source, string $predicate, string $targetType, string $target, string $key): array
    {
        $proposal = $handler->createFromArguments(['operation' => 'relation_create', 'entity_type' => 'relation', 'payload' => ['source_type' => $sourceType, 'source_uuid' => $source, 'predicate' => $predicate, 'target_type' => $targetType, 'target_uuid' => $target], 'idempotency_key' => $key]);
        return $handler->apply($proposal->id);
    }

    private function buildAndPublish(ClaimProjectionService $projection, string $type, string $id, string $h1): void
    {
        $revision = $projection->rebuild(new NodeReference($type, $id), '/synthetic/' . $type . '/', $h1);
        $revision = $projection->validate($id, $revision->revision); $projection->publish($id, $revision->revision);
    }

    private function claimItem(array $ledger, string $claimId): array
    {
        foreach ((array) ($ledger['sections'] ?? []) as $section) foreach ((array) ($section['claims'] ?? []) as $claim) if (($claim['claim_uuid'] ?? '') === $claimId) return $claim;
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function claimItems(array $ledger, string $claimId): array
    {
        $items = []; foreach ((array) ($ledger['sections'] ?? []) as $section) foreach ((array) ($section['claims'] ?? []) as $claim) if (($claim['claim_uuid'] ?? '') === $claimId) $items[] = $claim; return $items;
    }

    private function renderVariant(string $variantId, WpdbAuthorityRepository $authority, EntityTypeRegistry $types): string
    {
        $variant = $authority->findByCanonicalId($variantId); self::assertNotNull($variant);
        $routes = new \NHK\Core\Application\Entity\PublicRouteResolver($authority, $types); $path = $routes->path($variant); self::assertNotNull($path);
        $previous = []; foreach (['REQUEST_URI', 'PHP_SELF', 'SCRIPT_NAME', 'SERVER_NAME', 'HTTP_HOST'] as $key) if (isset($_SERVER[$key])) $previous[$key] = $_SERVER[$key];
        $queryKeys = ['nhk_public_entity_type', 'nhk_public_entity_a', 'nhk_public_entity_b', 'nhk_public_entity_c']; $previousQuery = []; foreach ($queryKeys as $key) $previousQuery[$key] = get_query_var($key, null);
        try {
            $_SERVER['REQUEST_URI'] = $path; $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['HTTP_HOST'] = 'localhost'; unset($GLOBALS['nhk_core_entity_context']);
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
            set_query_var('nhk_public_entity_type', 'variant'); set_query_var('nhk_public_entity_a', $segments[0] ?? ''); set_query_var('nhk_public_entity_b', $segments[1] ?? ''); set_query_var('nhk_public_entity_c', $segments[2] ?? '');
            $themeRoot = dirname(__DIR__, 4) . '/themes/nhk-v3'; $themeTemplate = $themeRoot . '/entity.php'; self::assertFileExists($themeTemplate); if (!function_exists('nhk_v3_public_brand_text')) require_once $themeRoot . '/functions.php';
            $template = apply_filters('template_include', $themeTemplate); self::assertNotSame('', $template, 'WordPress did not resolve a frontend template.'); ob_start(); try { include $template; return (string) ob_get_clean(); } catch (\Throwable $error) { ob_end_clean(); throw $error; }
        } finally {
            foreach (['REQUEST_URI', 'PHP_SELF', 'SCRIPT_NAME', 'SERVER_NAME', 'HTTP_HOST'] as $key) { if (array_key_exists($key, $previous)) $_SERVER[$key] = $previous[$key]; else unset($_SERVER[$key]); }
            foreach ($previousQuery as $key => $value) set_query_var($key, $value);
        }
    }
}
