<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Snapshot\{CanonicalSnapshotExportService, CanonicalSnapshotImportService, RecoveryRuntimeGuard, SnapshotCollectionRegistry, SnapshotEnvironment};
use NHK\Core\Infrastructure\Snapshot\{WpdbCanonicalSnapshotSource, WpdbCanonicalSnapshotWriter};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbSnapshotAdapterTest extends TestCase
{
    public function test_source_is_read_only_and_exposes_every_logical_collection(): void
    {
        $source = new WpdbCanonicalSnapshotSource($this->sourceDb(), $this->sourceEnvironment(), null, null, static fn (): array => ['current' => 20, 'target' => 20]);
        self::assertTrue($source->isReadOnly());
        self::assertSame([], array_intersect(['create', 'update', 'save', 'delete', 'apply'], get_class_methods($source)));
        self::assertSame(SnapshotCollectionRegistry::COLLECTIONS, array_keys($source->collections()));
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $source->collections()['videos'][0]['uuid']);
    }

    public function test_export_is_deterministic_and_adds_graph_reference_aliases(): void
    {
        $source = new WpdbCanonicalSnapshotSource($this->sourceDb(), $this->sourceEnvironment(), null, null, static fn (): array => ['current' => 20, 'target' => 20]);
        $service = new CanonicalSnapshotExportService();
        $first = $service->export($source, '2026-09-12T00:00:00+00:00');
        $second = $service->export($source, '2026-09-12T00:00:00+00:00');
        self::assertSame($first->manifest['manifest_hash'], $second->manifest['manifest_hash']);
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $first->collections['graph_edges'][0]['source_uuid']);
        self::assertSame('852da54d-457a-4397-a16d-52d9452ba766', $first->collections['graph_edges'][0]['target_uuid']);
    }

    public function test_source_to_recovery_writer_round_trip_preserves_golden_identity(): void
    {
        $snapshot = (new CanonicalSnapshotExportService())->export(new WpdbCanonicalSnapshotSource($this->sourceDb(), $this->sourceEnvironment(), null, null, static fn (): array => ['current' => 20, 'target' => 20]), '2026-09-12T00:00:00+00:00');
        $targetDb = new FakeSnapshotWpdb('nhk_v3_video_recovery', [], $this->sourceDb()->schemas);
        $writer = new WpdbCanonicalSnapshotWriter($targetDb, $this->recoveryEnvironment(), ['nhk_v3_video_recovery']);
        $receipt = (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_video_recovery'])))->import($snapshot, $writer, true);
        self::assertSame('imported', $receipt['status']);
        $collections = $writer->readBackCollections();
        self::assertSame('01a094df-6e43-7226-a3a5-78a6c4c05c7e', $collections['captures'][0]['uuid']);
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $collections['videos'][0]['uuid']);
        self::assertSame('so-372-odo-36-8-con-nguyen-ban-am-thanh-hay', $collections['public_identities'][0]['current_slug']);
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $collections['graph_edges'][0]['source_uuid']);
    }

    public function test_recovery_writer_rejects_staging_and_production(): void
    {
        $db = new FakeSnapshotWpdb('nhk_v3_video_recovery', [], $this->sourceDb()->schemas);
        $this->expectExceptionMessage('SNAPSHOT_WRITER_RECOVERY_ONLY');
        new WpdbCanonicalSnapshotWriter($db, new SnapshotEnvironment('staging', 'https://demo.1945.vn/', 'nhk_v3_video_recovery', 'staging'), ['nhk_v3_video_recovery']);
    }

    private function sourceEnvironment(): SnapshotEnvironment { return new SnapshotEnvironment('staging', 'https://demo.1945.vn/', 'demo_db', 'staging'); }
    private function recoveryEnvironment(): SnapshotEnvironment { return new SnapshotEnvironment('v3-video-recovery-1309', 'http://127.0.0.1:8090/', 'nhk_v3_video_recovery', 'recovery'); }

    private function sourceDb(): FakeSnapshotWpdb
    {
        $id = '01a094df-6ff2-7872-9bbe-ca4e843a68ef';
        $variant = '852da54d-457a-4397-a16d-52d9452ba766';
        $capture = '01a094df-6e43-7226-a3a5-78a6c4c05c7e';
        $source = '11111111-1111-4111-8111-111111111111';
        $claim = '22222222-2222-4222-8222-222222222222';
        $evidence = '33333333-3333-4333-8333-333333333333';
        $edge = '44444444-4444-4444-8444-444444444444';
        $identity = '55555555-5555-4555-8555-555555555555';
        return new FakeSnapshotWpdb('demo_db', [
            'nhk_editorial_captures' => [['id' => 1, 'capture_uuid' => $capture, 'idempotency_key' => 'capture-372', 'request_fingerprint' => str_repeat('a', 64), 'wp_post_id' => 454, 'revision' => 3]],
            'nhk_entities' => [['id' => 10, 'canonical_uuid' => $variant, 'entity_type' => 'variant', 'stable_key' => 'nhk:variant:odo.36.8', 'canonical_name' => 'Đồng hồ Odo 36/8', 'revision' => 1]],
            'nhk_knowledge_claims' => [['id' => 20, 'canonical_uuid' => $claim, 'stable_key' => 'nhk:knowledge:odo-36-8', 'claim_text' => 'Bối cảnh kỹ thuật được canonical hóa.', 'claim_type' => 'technical', 'provenance_json' => '{}', 'state' => 1, 'revision' => 2]],
            'nhk_sources' => [['id' => 30, 'canonical_uuid' => $source, 'stable_key' => 'nhk:source:video-372', 'title' => 'Video 372', 'source_type' => 'youtube', 'metadata_json' => '{}', 'state' => 1, 'revision' => 1]],
            'nhk_evidence' => [['id' => 40, 'evidence_uuid' => $evidence, 'claim_uuid' => $claim, 'source_uuid' => $source, 'relation_type' => 'supports', 'excerpt' => 'Xác nhận từ nguồn.', 'state' => 1, 'revision' => 1]],
            'nhk_videos' => [['id' => 50, 'canonical_uuid' => $id, 'platform' => 'youtube', 'external_video_id' => 'iqbGOL967t4', 'canonical_url' => 'https://www.youtube.com/watch?v=iqbGOL967t4', 'title' => 'Số 372 – Odo 36/8 côn nguyên bản – âm thanh hay', 'metadata_json' => '{"completion":{"status":"CONTENT_COMPLETE"}}', 'state' => 1, 'revision' => 2]],
            'nhk_graph_nodes' => [['id' => 60, 'endpoint_type' => 'video', 'endpoint_key' => $id], ['id' => 61, 'endpoint_type' => 'variant', 'endpoint_key' => $variant]],
            'nhk_graph_predicates' => [['id' => 70, 'predicate_key' => 'about']],
            'nhk_graph_edges' => [['id' => 80, 'edge_uuid' => $edge, 'source_node_id' => 60, 'predicate_id' => 70, 'target_node_id' => 61, 'state' => 1, 'revision' => 1]],
            'nhk_public_identities' => [['id' => 90, 'identity_uuid' => $identity, 'owner_kind' => 'video', 'owner_uuid' => $id, 'route_type' => 'video', 'current_slug' => 'so-372-odo-36-8-con-nguyen-ban-am-thanh-hay', 'collision_scope' => 'video', 'route_policy_version' => 'v1', 'revision' => 1, 'idempotency_key' => 'identity-372']],
            'posts' => [['ID' => 454, 'post_status' => 'draft', 'post_type' => 'post', 'post_title' => 'Số 372']],
        ]);
    }
}

final class FakeSnapshotWpdb
{
    public string $prefix = 'wp_';
    public array $schemas;
    private array $tables;
    private array $prepared = [];
    private ?array $transactionBackup = null;

    public function __construct(private string $database, array $tables, ?array $schemas = null)
    {
        $all = WpdbCanonicalSnapshotSource::physicalTables();
        $this->tables = array_fill_keys($all, []);
        foreach ($tables as $table => $rows) $this->tables[$table] = $rows;
        $this->schemas = $schemas ?? [];
        foreach ($all as $table) $this->schemas[$table] ??= $this->schemaFor($this->tables[$table]);
        foreach ($this->tables as $table => $rows) foreach ($rows as $rowIndex => $row) foreach ($this->schemas[$table] as $column => $definition) {
            if (!array_key_exists($column, $row) || $row[$column] === null) continue;
            if (preg_match('/^binary\(16\)/i', (string) $definition['Type']) === 1 && UuidCodec::isValid((string) $row[$column])) $this->tables[$table][$rowIndex][$column] = UuidCodec::toBinary((string) $row[$column]);
            if (preg_match('/^binary\(32\)/i', (string) $definition['Type']) === 1 && preg_match('/^[0-9a-f]{64}$/i', (string) $row[$column]) === 1) $this->tables[$table][$rowIndex][$column] = hex2bin((string) $row[$column]);
        }
    }

    public function schemas(): array { return $this->schemas; }
    public function prepare(string $query, mixed ...$args): string { $id = count($this->prepared); $this->prepared[$id] = [$query, $args]; return '__prepared_' . $id . '__'; }
    public function get_var(string $query): mixed
    {
        [$query, $args] = $this->resolve($query);
        if (trim($query) === 'SELECT DATABASE()') return $this->database;
        if (str_starts_with($query, 'SHOW TABLES LIKE')) return isset($args[0]) && isset($this->schemas[$this->suffix((string) $args[0])]) ? (string) $args[0] : null;
        if (preg_match('/SELECT COUNT\(\*\) FROM (wp_[a-z0-9_]+)/i', $query, $match) === 1) return count($this->tables[$this->suffix($match[1])] ?? []);
        return null;
    }
    public function get_results(string $query, mixed $output = null): array
    {
        [$query] = $this->resolve($query);
        if (preg_match('/SHOW COLUMNS FROM (wp_[a-z0-9_]+)/i', $query, $match) === 1) return $this->schemas[$this->suffix($match[1])] ?? [];
        if (preg_match('/SELECT \* FROM (wp_[a-z0-9_]+)/i', $query, $match) === 1) return array_values($this->tables[$this->suffix($match[1])] ?? []);
        return [];
    }
    public function query(string $query): int|false
    {
        [$query, $args] = $this->resolve($query);
        if ($query === 'START TRANSACTION') { $this->transactionBackup = $this->tables; return 1; }
        if ($query === 'COMMIT') { $this->transactionBackup = null; return 1; }
        if ($query === 'ROLLBACK') { if ($this->transactionBackup !== null) $this->tables = $this->transactionBackup; $this->transactionBackup = null; return 1; }
        if (preg_match('/INSERT INTO (wp_[a-z0-9_]+) \(`([^`]+)`(?:,`([^`]+)`)*\)/i', $query, $match) !== 1) return 1;
        preg_match_all('/`([^`]+)`/', $match[0], $fields);
        $row = [];
        foreach ($fields[1] as $index => $field) $row[$field] = $args[$index] ?? null;
        $table = $this->suffix($match[1]);
        if (!isset($row['id']) && !isset($row['ID'])) $row[isset($this->schemas[$table]['ID']) ? 'ID' : 'id'] = count($this->tables[$table]) + 1;
        $this->tables[$table][] = $row;
        return 1;
    }

    /** @return array{0:string,1:array} */
    private function resolve(string $query): array
    {
        if (preg_match('/^__prepared_(\d+)__$/', $query, $match) === 1) return $this->prepared[(int) $match[1]];
        return [$query, []];
    }
    private function suffix(string $table): string { return preg_replace('/^wp_/', '', $table) ?: $table; }
    private function schemaFor(array $rows): array
    {
        $keys = ['id'];
        foreach ($rows as $row) foreach (array_keys($row) as $key) if (!in_array($key, $keys, true)) $keys[] = $key;
        $schema = [];
        foreach ($keys as $key) {
            $type = in_array($key, ['canonical_uuid', 'capture_uuid', 'evidence_uuid', 'claim_uuid', 'source_uuid', 'edge_uuid', 'identity_uuid', 'owner_uuid'], true) || str_ends_with($key, '_uuid') ? 'binary(16)' : (str_contains($key, 'fingerprint') ? 'binary(32)' : 'longtext');
            $schema[$key] = ['Field' => $key, 'Type' => $type, 'Key' => $key === 'id' || $key === 'ID' ? 'PRI' : ''];
        }
        return $schema;
    }
}
