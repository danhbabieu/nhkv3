<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Snapshot;

use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use NHK\Core\Application\Snapshot\{SnapshotCollectionRegistry, SnapshotCanonicalizer, SnapshotEnvironment};
use NHK\Core\Contracts\Snapshot\CanonicalSnapshotSource;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only WPDB projection of the canonical V3 stores.
 *
 * The logical snapshot shape is owned by the application contract; this class
 * is the only place that knows the current WordPress table layout. It has no
 * repository mutation dependency and never exposes a write operation.
 */
final class WpdbCanonicalSnapshotSource implements CanonicalSnapshotSource
{
    /** @var array<string,list<string>> */
    public const TABLES = [
        'captures' => ['nhk_editorial_captures'],
        'capture_addenda' => ['nhk_editorial_capture_addenda'],
        'editorial_posts' => ['posts'],
        'editorial_post_meta' => ['postmeta'],
        'post_taxonomy' => ['terms', 'term_taxonomy', 'termmeta', 'term_relationships'],
        'authority_entities' => ['nhk_entities'],
        'knowledge' => ['nhk_knowledge_claims'],
        'sources' => ['nhk_sources'],
        'claims' => [], // Claims are the canonical knowledge rows in V3.
        'evidence' => ['nhk_evidence'],
        'proposals' => ['nhk_proposals', 'nhk_proposal_dependencies'],
        'proposal_approvals' => ['nhk_proposal_approvals'],
        'proposal_audit_history' => ['nhk_audit_events'],
        'apply_attempts' => ['nhk_apply_attempts'],
        'media' => ['nhk_media'],
        'media_assets' => ['nhk_media_assets'],
        'media_usages' => ['nhk_media_usages'],
        'videos' => ['nhk_videos'],
        'graph_nodes' => ['nhk_graph_nodes'],
        'graph_predicates' => ['nhk_graph_predicates'],
        'graph_edges' => ['nhk_graph_edges'],
        'public_identities' => ['nhk_public_identities', 'nhk_public_identity_history'],
        'completion_state' => [],
        'idempotency_state' => [],
    ];

    public function __construct(
        private object $database,
        private SnapshotEnvironment $runtime,
        private ?McpDocumentationRegistry $documentation = null,
        private ?MigrationStatus $migrations = null,
        private ?\Closure $migrationReader = null,
    ) {}

    public function environment(): SnapshotEnvironment { return $this->runtime; }
    public function schemaVersion(): string { return SnapshotCollectionRegistry::SCHEMA_VERSION; }
    public function documentationVersion(): string { return (string) $this->documentation()->bootstrap()['documentation_version']; }
    public function buildIdentity(): string { return (string) $this->documentation()->bootstrap()['build_identity']; }
    public function migrationLevel(): array { return $this->migrationReader !== null ? ($this->migrationReader)() : ($this->migrations ??= new MigrationStatus())->status(); }
    public function isReadOnly(): bool { return true; }

    public function schemaConsistent(): bool
    {
        foreach (self::physicalTables() as $suffix) {
            if (!$this->tableExists($suffix)) return false;
            if ($this->columns($suffix) === []) return false;
        }
        return true;
    }

    /** @return array<string,array<string,mixed>> */
    public function repositoryInventory(): array
    {
        $inventory = [];
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $collection) {
            $tables = self::TABLES[$collection] ?? [];
            $inventory[$collection] = [
                'adapter' => self::class,
                'mode' => 'read_only',
                'tables' => array_values(array_map(fn (string $table): string => $this->table($table), $tables)),
                'logical_alias' => $collection === 'claims' ? 'knowledge' : null,
                'derived' => in_array($collection, ['completion_state', 'idempotency_state'], true),
            ];
            foreach ($tables as $table) $inventory[$collection]['schema_sha256'][$table] = hash('sha256', SnapshotCanonicalizer::encode($this->columns($table)));
        }
        ksort($inventory);
        return $inventory;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function collections(): array
    {
        if (!$this->schemaConsistent()) throw new \RuntimeException('SNAPSHOT_SCHEMA_INCONSISTENT');
        $posts = $this->referencedPostIds();
        $collections = [];
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $collection) {
            $collections[$collection] = match ($collection) {
                'editorial_posts' => $this->readTable('posts', $posts),
                'editorial_post_meta' => $this->readTable('postmeta', $posts, 'post_id'),
                'post_taxonomy' => $this->readTaxonomy($posts),
                'graph_nodes' => $this->readGraphNodes(),
                'graph_edges' => $this->readGraphEdges(),
                'completion_state' => $this->completionState(),
                'idempotency_state' => $this->idempotencyState(),
                'claims' => [],
                default => $this->readCollectionTables(self::TABLES[$collection] ?? []),
            };
        }
        return $collections;
    }

    /** @return list<string> */
    public static function physicalTables(): array
    {
        $tables = [];
        foreach (self::TABLES as $collection => $items) {
            if (in_array($collection, ['claims', 'completion_state', 'idempotency_state'], true)) continue;
            foreach ($items as $item) $tables[$item] = true;
        }
        return array_keys($tables);
    }

    /** @return list<array<string,mixed>> */
    public function rowsForTable(string $suffix, ?array $ids = null, ?string $idColumn = null): array
    {
        return $this->readTable($suffix, $ids, $idColumn);
    }

    /** @return list<array<string,mixed>> */
    private function readCollectionTables(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            foreach ($this->readTable($table) as $row) {
                if (count($tables) > 1) $row['_physical_table'] = $table;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function readTable(string $suffix, ?array $ids = null, ?string $idColumn = null): array
    {
        $table = $this->table($suffix);
        $query = 'SELECT * FROM ' . $table;
        $args = [];
        if ($ids !== null && $ids !== []) {
            $idColumn ??= 'ID';
            $query .= ' WHERE ' . $idColumn . ' IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $args = array_map('intval', $ids);
        } elseif ($ids === []) {
            return [];
        }
        $columns = $this->columns($suffix);
        $orderColumns = $this->orderColumns($columns);
        $query .= ' ORDER BY ' . implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $orderColumns));
        $prepared = $args === [] ? $query : $this->database->prepare($query, ...$args);
        $rows = $this->database->get_results($prepared, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A') ?: [];
        return array_map(fn (array $row): array => $this->normalizeRow($row, $columns), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function readGraphNodes(): array
    {
        return array_map(static function (array $row): array {
            $row['uuid'] = (string) ($row['endpoint_key'] ?? '');
            return $row;
        }, $this->readTable('nhk_graph_nodes'));
    }

    /** @return list<array<string,mixed>> */
    private function readGraphEdges(): array
    {
        $nodes = [];
        foreach ($this->readTable('nhk_graph_nodes') as $node) $nodes[(int) ($node['id'] ?? 0)] = (string) ($node['endpoint_key'] ?? '');
        $predicates = [];
        foreach ($this->readTable('nhk_graph_predicates') as $predicate) $predicates[(int) ($predicate['id'] ?? 0)] = (string) ($predicate['predicate_key'] ?? '');
        return array_map(static function (array $row) use ($nodes, $predicates): array {
            $row['source_uuid'] = $nodes[(int) ($row['source_node_id'] ?? 0)] ?? '';
            $row['target_uuid'] = $nodes[(int) ($row['target_node_id'] ?? 0)] ?? '';
            $row['predicate'] = $predicates[(int) ($row['predicate_id'] ?? 0)] ?? '';
            return $row;
        }, $this->readTable('nhk_graph_edges'));
    }

    /** @return list<array<string,mixed>> */
    private function readTaxonomy(array $posts): array
    {
        if ($posts === []) return [];
        $relationships = $this->readTable('term_relationships', $posts, 'object_id');
        $taxonomyIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['term_taxonomy_id'] ?? 0), $relationships)));
        $taxonomy = $this->readTable('term_taxonomy', $taxonomyIds, 'term_taxonomy_id');
        $termIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['term_id'] ?? 0), $taxonomy)));
        $terms = $this->readTable('terms', $termIds, 'term_id');
        $rows = [];
        foreach (['terms' => $terms, 'term_taxonomy' => $taxonomy, 'termmeta' => $this->readTable('termmeta', $termIds, 'term_id'), 'term_relationships' => $relationships] as $table => $items) {
            foreach ($items as $row) { $row['_physical_table'] = $table; $rows[] = $row; }
        }
        return $rows;
    }

    /** @return list<int> */
    private function referencedPostIds(): array
    {
        $rows = $this->readTable('nhk_editorial_captures');
        $ids = [];
        foreach ($rows as $row) if ((int) ($row['wp_post_id'] ?? 0) > 0) $ids[] = (int) $row['wp_post_id'];
        sort($ids);
        return array_values(array_unique($ids));
    }

    /** @return list<array<string,mixed>> */
    private function completionState(): array
    {
        $result = [];
        foreach ($this->readTable('nhk_videos') as $video) {
            $metadata = $this->jsonValue($video['metadata_json'] ?? null, 'nhk_videos.metadata_json');
            $completion = $metadata['completion'] ?? ($metadata['completeness'] ?? []);
            $result[] = ['owner_type' => 'video', 'owner_uuid' => $video['canonical_uuid'], 'state' => is_array($completion) ? $completion : [], 'revision' => (int) ($video['revision'] ?? 1)];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function idempotencyState(): array
    {
        $result = [];
        foreach ($this->readTable('nhk_editorial_captures') as $row) $result[] = ['owner_type' => 'capture', 'owner_uuid' => $row['capture_uuid'], 'idempotency_key' => $row['idempotency_key'], 'request_fingerprint' => $row['request_fingerprint']];
        foreach ($this->readTable('nhk_proposals') as $row) $result[] = ['owner_type' => 'proposal', 'owner_uuid' => $row['proposal_uuid'], 'idempotency_key' => $row['idempotency_key'], 'fingerprint' => $row['fingerprint'], 'dependency_fingerprint' => $row['dependency_fingerprint']];
        return $result;
    }

    /** @param array<string,mixed> $row @param array<string,array<string,mixed>> $columns */
    public function normalizeRow(array $row, array $columns): array
    {
        foreach ($columns as $name => $column) {
            if (!array_key_exists($name, $row) || $row[$name] === null) continue;
            $type = strtolower((string) ($column['Type'] ?? ''));
            if (preg_match('/^binary\(16\)/', $type) === 1) {
                try { $row[$name] = UuidCodec::fromBinary((string) $row[$name]); } catch (\Throwable) { throw new \RuntimeException('SNAPSHOT_UUID_INVALID:' . $name); }
            } elseif (preg_match('/^binary\(32\)/', $type) === 1) {
                $row[$name] = bin2hex((string) $row[$name]);
            } elseif (str_ends_with($name, '_json')) {
                $row[$name] = $this->canonicalJson($row[$name], $name);
            }
        }
        foreach (['addendum_uuid', 'approval_uuid', 'attempt_uuid', 'event_uuid', 'evidence_uuid', 'asset_uuid', 'usage_uuid', 'edge_uuid', 'identity_uuid', 'canonical_uuid', 'capture_uuid', 'proposal_uuid'] as $identityColumn) {
            if (isset($row[$identityColumn]) && !isset($row['uuid'])) { $row['uuid'] = $row[$identityColumn]; break; }
        }
        return $row;
    }

    /** @return array<string,array<string,mixed>> */
    public function columns(string $suffix): array
    {
        $rows = $this->database->get_results('SHOW COLUMNS FROM ' . $this->table($suffix), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A') ?: [];
        $result = [];
        foreach ($rows as $row) if (isset($row['Field'])) $result[(string) $row['Field']] = $row;
        return $result;
    }

    /** @param array<string,array<string,mixed>> $columns @return list<string> */
    private function orderColumns(array $columns): array
    {
        if (isset($columns['id'])) return ['id'];
        $primary = array_keys(array_filter($columns, static fn (array $column): bool => (string) ($column['Key'] ?? '') === 'PRI'));
        if ($primary !== []) return $primary;
        return array_slice(array_keys($columns), 0, 2);
    }

    public function tableExists(string $suffix): bool
    {
        $table = $this->table($suffix);
        return (string) $this->database->get_var($this->database->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function table(string $suffix): string { return $this->database->prefix . $suffix; }

    private function documentation(): McpDocumentationRegistry { return $this->documentation ??= new McpDocumentationRegistry(); }

    /** @param mixed $value */
    private function jsonValue(mixed $value, string $field): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        try { $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('SNAPSHOT_JSON_INVALID:' . $field); }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $value */
    private function canonicalJson(mixed $value, string $field): mixed
    {
        if ($value === null || $value === '') return $value;
        $decoded = $this->jsonValue($value, $field);
        return SnapshotCanonicalizer::encode(SnapshotCanonicalizer::sanitize($decoded));
    }
}
