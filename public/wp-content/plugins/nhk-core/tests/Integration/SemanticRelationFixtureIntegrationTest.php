<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{CanonicalApplyReadBackVerifier, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Graph\{GraphService, SemanticEnrichmentRelationRegistry, SemanticRelationGovernanceAdapter};
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeService};
use NHK\Core\Contracts\Governance\GovernanceAuthorizer;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink as GraphAuditSink, WpdbGraphRelationContextRepository, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, GovernanceMigration003, GraphMigration001, GraphRelationContextMigration002, KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class SemanticRelationFixtureIntegrationTest extends TestCase
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
        (new GraphRelationContextMigration002())->up();
        $this->prefix = 'semantic-relation-fixture-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        TestDatabaseGuard::requireTestDatabase();
        foreach ($this->owned as $id) {
            $binary = UuidCodec::toBinary($id);
            foreach (['nhk_evidence' => 'evidence_uuid', 'nhk_knowledge_claims' => 'canonical_uuid', 'nhk_sources' => 'canonical_uuid', 'nhk_entities' => 'canonical_uuid'] as $table => $column) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$table} WHERE {$column}=%s", $binary));
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_relation_context WHERE edge_uuid=%s", $binary));
        }
        if ($this->owned !== []) {
            $placeholders = implode(',', array_fill(0, count($this->owned), '%s'));
            $nodeIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN ({$placeholders})", ...$this->owned));
            if ($nodeIds !== []) {
                $ids = implode(',', array_map('intval', $nodeIds));
                $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE source_node_id IN ({$ids}) OR target_node_id IN ({$ids})");
                $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE id IN ({$ids})");
            }
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_proposals WHERE idempotency_key LIKE %s", $this->prefix . '%'));
        foreach (['nhk_sources' => 'stable_key', 'nhk_knowledge_claims' => 'stable_key', 'nhk_entities' => 'stable_key'] as $table => $column) {
            self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} LIKE %s", $this->prefix . '%')));
        }
    }

    public function test_governed_source_claim_evidence_supports_semantic_relation_lifecycle_without_new_evidence_on_cleanup(): void
    {
        global $wpdb;
        [$authority, $apply, $governance, $endpoints, $graph] = $this->fixture();
        $brand = $authority->create('brand', $this->prefix . '-brand', 'Synthetic relation brand');
        $component = $authority->create('component', $this->prefix . '-component', 'Synthetic relation component');
        $this->owned[] = $brand->canonicalId;
        $this->owned[] = $component->canonicalId;

        $source = $this->runGoverned($governance, $apply, 'source', ['stable_key' => $this->prefix . '-source', 'title' => 'Synthetic governed source', 'source_type' => 'catalog', 'locator' => 'https://example.test/synthetic-source', 'metadata' => ['visibility' => 'PUBLIC']]);
        $claim = $this->runGoverned($governance, $apply, 'knowledge', ['stable_key' => $this->prefix . '-claim', 'text' => 'Synthetic governed claim.', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['knowledge_status' => 'APPROVED']]]);
        $evidence = $this->runGoverned($governance, $apply, 'evidence', ['claim_id' => $claim['canonical_id'], 'source_id' => $source['canonical_id'], 'excerpt' => 'Synthetic governed evidence.', 'relation' => 'supports', 'locator' => 'https://example.test/synthetic-source#evidence', 'metadata' => ['visibility' => 'PUBLIC']]);

        $authorityRepo = new WpdbAuthorityRepository($wpdb);
        $endpointState = static function (string $type, string $id) use ($authorityRepo): array {
            $entity = $authorityRepo->findByCanonicalId($id);
            return $entity === null ? ['exists' => false] : ['exists' => true, 'active' => $entity->active(), 'revision' => $entity->revision];
        };
        $contexts = new WpdbGraphRelationContextRepository($wpdb);
        $adapter = new SemanticRelationGovernanceAdapter(
            (new SemanticEnrichmentRelationRegistry())->version(),
            (new SemanticEnrichmentRelationRegistry())->hash(),
            $endpointState,
            static fn (): array => [],
            $graph,
            $contexts,
            new WpdbTransactionManager($wpdb),
        );
        $input = ['operation' => 'ADD', 'source' => ['type' => 'component', 'id' => $component->canonicalId], 'target' => ['type' => 'brand', 'id' => $brand->canonicalId], 'predicate' => 'associated_with', 'scope_code' => 'TEST_SYNTHETIC_RELATION', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_refs' => [['evidence_id' => $evidence['canonical_id']]], 'idempotency_key' => $this->prefix . '-relation'];
        $preview = $adapter->preview($input);
        self::assertSame('READY', $preview['status']);
        $added = $adapter->apply($preview['plan'], $preview['plan_fingerprint'], $input['idempotency_key']);
        self::assertSame('READ_BACK_VERIFIED', $added['status']);
        $edgeUuid = (string) $added['edge'];
        $read = $adapter->read(['edge_uuid' => $edgeUuid]);
        self::assertSame('available', $read['status']);

        $retireInput = array_merge($input, ['operation' => 'RETIRE', 'evidence_refs' => [], 'edge_uuid' => $edgeUuid, 'expected_edge_revision' => 1, 'idempotency_key' => $this->prefix . '-relation-retire']);
        $retirePreview = $adapter->preview($retireInput);
        self::assertSame('READY', $retirePreview['status']);
        self::assertSame('READ_BACK_VERIFIED', $adapter->apply($retirePreview['plan'], $retirePreview['plan_fingerprint'], $this->prefix . '-relation-retire')['status']);
        self::assertSame('RETIRED', $adapter->read(['edge_uuid' => $edgeUuid])['edge']['state']);

        $reactivateInput = array_merge($input, ['operation' => 'REACTIVATE', 'evidence_refs' => [], 'edge_uuid' => $edgeUuid, 'expected_edge_revision' => 2, 'idempotency_key' => $this->prefix . '-relation-reactivate']);
        $reactivatePreview = $adapter->preview($reactivateInput);
        self::assertSame('READY', $reactivatePreview['status']);
        self::assertSame('READ_BACK_VERIFIED', $adapter->apply($reactivatePreview['plan'], $reactivatePreview['plan_fingerprint'], $this->prefix . '-relation-reactivate')['status']);
        self::assertSame('ACTIVE', $adapter->read(['edge_uuid' => $edgeUuid])['edge']['state']);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_graph_relation_context WHERE edge_uuid=%s AND state=1", UuidCodec::toBinary($edgeUuid))));
        $this->owned[] = $edgeUuid;
    }

    private function fixture(): array
    {
        global $wpdb;
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new WpdbAuthorityRepository($wpdb);
        $authority = new AuthorityService($authorityRepo, $types);
        $claims = new WpdbKnowledgeRepository($wpdb);
        $sources = new WpdbSourceRepository($wpdb);
        $evidence = new WpdbEvidenceRepository($wpdb);
        $endpoints = new EndpointTypeRegistry();
        $videos = new WpdbVideoRepository($wpdb);
        CoreEndpointResolverRegistrar::register($endpoints, $types, $authorityRepo, new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb), $videos, $claims, $sources, $evidence);
        $graph = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, new PredicateRegistry(), new GraphAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
        $proposals = new WpdbProposalRepository($wpdb);
        $eligibility = new ProposalEligibilityService($proposals, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authorityRepo, $proposals, new WpdbGraphRepository($wpdb), null, $videos, $claims, $sources, $evidence));
        $knowledge = new KnowledgeService($claims, $sources, $evidence);
        $executor = new \NHK\Core\Application\Governance\AuthorityProposalExecutor($authority, $graph, null, new \NHK\Core\Application\Video\VideoService($videos), $knowledge, null, null, null, new CanonicalDependencyValidator($claims, $sources, $evidence));
        $reader = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($claims, $sources, $evidence, $authorityRepo, $graph): ?array {
            $entity = match ($type) { 'source' => $sources->findByCanonicalId($id), 'knowledge' => $claims->findByCanonicalId($id), 'evidence' => $evidence->findByCanonicalId($id), 'relation' => $graph->findByUuid($id), default => $authorityRepo->findByCanonicalId($id) };
            if ($entity === null) return null;
            $active = method_exists($entity, 'active') ? (bool) $entity->active() : (property_exists($entity, 'active') ? (bool) $entity->active : true);
            return ['entity_type' => $type, 'canonical_id' => $id, 'active' => $active, 'revision' => property_exists($entity, 'revision') ? $entity->revision : 1, 'snapshot' => ['id' => $id]];
        });
        $apply = new ControlledApplyService($proposals, new WpdbApplyAttemptRepository($wpdb), new WpdbTransactionManager($wpdb), $executor, new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb), $eligibility, null, new class implements GovernanceAuthorizer { public function require(string $capability): void {} }, $reader);
        return [$authority, $apply, new GovernanceService($proposals, new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb), new WpdbTransactionManager($wpdb)), $endpoints, $graph];
    }

    private function runGoverned(GovernanceService $governance, ControlledApplyService $apply, string $type, array $payload): array
    {
        $proposal = $governance->create(new Proposal(UuidCodec::newV7(), $type, 'ingest', $payload, hash('sha256', json_encode($payload)), null, hash('sha256', $type), ProposalState::DRAFT, idempotencyKey: $this->prefix . '-' . $type . '-' . count($this->owned), entityType: $type));
        $proposal = $governance->submit($proposal->id);
        $proposal = $governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'test-policy');
        $result = $apply->apply($proposal->id);
        self::assertNotNull($result['canonical_readback']);
        $id = (string) $result['canonical_id'];
        $this->owned[] = $id;
        return ['canonical_id' => $id];
    }
}
