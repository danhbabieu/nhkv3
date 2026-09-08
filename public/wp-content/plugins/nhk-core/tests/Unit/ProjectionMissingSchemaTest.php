<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Projection\{ClaimProjectionService, ClaimScopeResolver, GraphProjectionPolicy, LiveLedgerProjectionBuilder, ProjectionInvalidationService};
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Infrastructure\Projection\{InMemoryProjectionRevisionStore, WpdbProjectionDependencyIndex, WpdbProjectionRevisionStore, WpdbProjectionSchema};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class ProjectionMissingSchemaTest extends TestCase
{
    public function test_pre_migration_entity_projection_is_unavailable_without_querying_missing_tables(): void
    {
        $wpdb = new ProjectionWpdbStub([]);
        $store = new WpdbProjectionRevisionStore($wpdb);
        $service = new ClaimProjectionService($this->neverUsedBuilder(), $store);
        $node = UuidCodec::newV7();

        self::assertSame('unavailable', $service->getLedger($node)['status']);
        self::assertSame('MIGRATION_016_SCHEMA_UNAVAILABLE', $service->getLedger($node)['reason']);
        self::assertNull($service->getPublishedSeoProjection($node));
        $status = $service->getProjectionStatus($node);
        self::assertSame('unavailable', $status['status']);
        self::assertSame('MIGRATION_016_SCHEMA_UNAVAILABLE', $status['reason']);
        self::assertNull($status['claim_count']);
        self::assertCount(2, $wpdb->showTableQueries, 'The cached schema probe should run once per projection table.');
        self::assertSame([], $wpdb->dataQueries, 'No SELECT/INSERT/UPDATE may target a pre-migration projection table.');
    }

    public function test_existing_projection_schema_still_reads_published_seo_projection(): void
    {
        $wpdb = new ProjectionWpdbStub([
            'wp_nhk_claim_projection_revisions',
            'wp_nhk_claim_projection_dependencies',
        ], [
            'node_uuid' => 'node-1',
            'projection_revision' => '1',
            'status' => 'published',
            'input_hash' => str_repeat('a', 64),
            'claim_set_hash' => str_repeat('b', 64),
            'graph_hash' => str_repeat('c', 64),
            'policy_revision' => '1',
            'template_revision' => '1',
            'payload_json' => '{"seo":{"canonical_url":"/node-1/","h1":"Node 1"}}',
            'dirty_sections_json' => '[]',
            'generated_at' => '2026-01-01 00:00:00.000000',
            'published_at' => '2026-01-01 00:00:00.000000',
        ]);
        $store = new WpdbProjectionRevisionStore($wpdb);

        self::assertSame('/node-1/', $store->findPublished('node-1')->payload['seo']['canonical_url']);
        self::assertCount(2, $wpdb->showTableQueries);
        self::assertCount(1, $wpdb->dataQueries);
    }

    public function test_missing_candidate_table_is_not_reported_as_an_empty_projection(): void
    {
        $wpdb = new ProjectionWpdbStub(['wp_nhk_claim_projection_dependencies']);
        $store = new WpdbProjectionRevisionStore($wpdb);
        $service = new ClaimProjectionService($this->neverUsedBuilder(), $store);

        $result = $service->getLedger(UuidCodec::newV7());
        $status = $service->getProjectionStatus(UuidCodec::newV7());

        self::assertSame('unavailable', $result['status']);
        self::assertSame('MIGRATION_016_SCHEMA_UNAVAILABLE', $result['reason']);
        self::assertSame('unavailable', $status['status']);
        self::assertSame(['wp_nhk_claim_projection_revisions'], $status['missing_tables']);
        self::assertSame([], $wpdb->dataQueries);
    }

    public function test_missing_dependency_projection_schema_degrades_invalidation_without_querying_dependency_table(): void
    {
        $wpdb = new ProjectionWpdbStub([]);
        $schema = new WpdbProjectionSchema($wpdb);
        $dependencies = new WpdbProjectionDependencyIndex($wpdb, $schema);
        $store = new InMemoryProjectionRevisionStore();

        $result = (new ProjectionInvalidationService($dependencies, $store))->invalidateClaim('claim-1');

        self::assertSame('unavailable', $result['status']);
        self::assertSame('MIGRATION_016_SCHEMA_UNAVAILABLE', $result['reason']);
        self::assertSame([], $result['impacted']);
        self::assertSame([], $wpdb->dataQueries);
    }

    private function neverUsedBuilder(): LiveLedgerProjectionBuilder
    {
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { throw new \LogicException('The ledger builder must not run when projection storage is unavailable.'); }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new class implements EndpointResolver {
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'variant'; }
            public function exists(NodeReference $reference): bool { return true; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
        });
        return new LiveLedgerProjectionBuilder(new ClaimScopeResolver($claims, new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink()), new GraphProjectionPolicy()));
    }
}

final class ProjectionWpdbStub
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $showTableQueries = [];
    /** @var list<string> */
    public array $dataQueries = [];

    /** @param list<string> $tables */
    public function __construct(private array $tables, private ?array $row = null) {}
    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) $query = preg_replace('/%[sd]/', (string) $arg, $query, 1) ?? $query;
        return $query;
    }
    public function get_var(string $query): ?string
    {
        if (str_starts_with($query, 'SHOW TABLES')) {
            $this->showTableQueries[] = $query;
            foreach ($this->tables as $table) if (str_contains($query, $table)) return $table;
            return null;
        }
        $this->dataQueries[] = $query;
        throw new \LogicException('Data query was issued by the schema probe.');
    }
    public function get_row(string $query, mixed $output): ?array { $this->dataQueries[] = $query; return $this->row; }
    public function query(string $query): int|false { $this->dataQueries[] = $query; return 1; }
}
