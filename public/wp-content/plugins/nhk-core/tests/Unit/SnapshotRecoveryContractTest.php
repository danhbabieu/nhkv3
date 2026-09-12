<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Snapshot\{CanonicalSnapshot, CanonicalSnapshotExportService, CanonicalSnapshotImportService, RecoveryRuntimeGuard, SnapshotArtifactCodec, SnapshotCollectionRegistry, SnapshotEnvironment};
use NHK\Core\Contracts\Snapshot\{CanonicalSnapshotSource, CanonicalSnapshotWriter};
use PHPUnit\Framework\TestCase;

final class SnapshotRecoveryContractTest extends TestCase
{
    public function test_export_preserves_uuid_and_revision_round_trip(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $roundTrip = SnapshotArtifactCodec::decode(SnapshotArtifactCodec::encode($snapshot));
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $roundTrip->collections['videos'][0]['uuid']);
        self::assertSame(7, $roundTrip->collections['videos'][0]['revision']);
        self::assertSame($snapshot->manifest['manifest_hash'], $roundTrip->manifest['manifest_hash']);
    }

    public function test_export_preserves_proposal_lifecycle_and_apply_attempts(): void
    {
        $collections = $this->goldenCollections();
        $collections['proposals'] = [['uuid' => 'proposal-372', 'state' => 'APPLIED', 'revision' => 4]];
        $collections['apply_attempts'] = [['uuid' => 'attempt-372', 'proposal_id' => 'proposal-372', 'state' => 'SUCCEEDED']];
        $snapshot = $this->export($collections);
        self::assertSame('APPLIED', $snapshot->collections['proposals'][0]['state']);
        self::assertSame('attempt-372', $snapshot->collections['apply_attempts'][0]['uuid']);
    }

    public function test_import_rejects_staging_target(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $this->expectExceptionMessage('SNAPSHOT_IMPORT_STAGING_FORBIDDEN');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, new SnapshotTestWriter(new SnapshotEnvironment('staging', 'https://staging.example', 'nhk_v3_recovery', 'staging')), true);
    }

    public function test_import_rejects_production_target(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $this->expectExceptionMessage('SNAPSHOT_IMPORT_PRODUCTION_FORBIDDEN');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, new SnapshotTestWriter(new SnapshotEnvironment('production', 'https://production.example', 'nhk_v3_recovery', 'production')), true);
    }

    public function test_import_rejects_missing_explicit_recovery_mode(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $writer = new SnapshotTestWriter($this->target());
        $this->expectExceptionMessage('RECOVERY_MODE_CONFIRMATION_REQUIRED');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, $writer, false);
    }

    public function test_export_rejects_incomplete_source_migrations(): void
    {
        $this->expectExceptionMessage('SNAPSHOT_MIGRATIONS_NOT_CURRENT');
        $source = new class implements CanonicalSnapshotSource {
            public function environment(): SnapshotEnvironment { return new SnapshotEnvironment('staging', 'https://demo.1945.vn', 'demo_db', 'staging'); }
            public function schemaVersion(): string { return SnapshotCollectionRegistry::SCHEMA_VERSION; }
            public function documentationVersion(): string { return 'docs-test'; }
            public function buildIdentity(): string { return 'build-test'; }
            public function migrationLevel(): array { return ['current' => 19, 'target' => 20]; }
            public function schemaConsistent(): bool { return true; }
            public function isReadOnly(): bool { return true; }
            public function repositoryInventory(): array { return []; }
            public function collections(): array { return array_fill_keys(SnapshotCollectionRegistry::COLLECTIONS, []); }
        };
        (new CanonicalSnapshotExportService())->export($source);
    }

    public function test_export_removes_secret_records_and_fields(): void
    {
        $collections = $this->goldenCollections();
        $collections['editorial_post_meta'] = [
            ['option_name' => 'NHK_RECOVERY_SECRET', 'option_value' => 'do-not-export'],
            ['meta_key' => 'safe', 'meta_value' => 'kept', 'api_token' => 'do-not-export'],
        ];
        $snapshot = $this->export($collections);
        $encoded = SnapshotArtifactCodec::encode($snapshot);
        self::assertStringNotContainsString('do-not-export', $encoded);
        self::assertStringContainsString('kept', $encoded);
    }

    public function test_import_rejects_source_database_collision(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $target = new SnapshotEnvironment('v3-video-recovery-1309', 'https://video-recovery.local', 'demo_db', 'recovery');
        $this->expectExceptionMessage('SNAPSHOT_IMPORT_SOURCE_DATABASE_FORBIDDEN');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['demo_db'])))->import($snapshot, new SnapshotTestWriter($target), true);
    }

    public function test_import_rejects_non_empty_namespace_without_manifest(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $writer = new SnapshotTestWriter($this->target());
        $writer->collections['videos'] = [['uuid' => 'unrelated']];
        $this->expectExceptionMessage('SNAPSHOT_TARGET_NAMESPACE_NOT_EMPTY');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, $writer, true);
    }

    public function test_import_rejects_manifest_tampering_before_write(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $tampered = new CanonicalSnapshot(array_replace($snapshot->manifest, ['manifest_hash' => str_repeat('0', 64)]), $snapshot->collections);
        $this->expectExceptionMessage('SNAPSHOT_MANIFEST_HASH_MISMATCH');
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($tampered, new SnapshotTestWriter($this->target()), true);
    }

    public function test_import_preserves_evidence_graph_and_public_identity(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $writer = new SnapshotTestWriter($this->target());
        $receipt = (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, $writer, true);
        self::assertSame('imported', $receipt['status']);
        self::assertSame('evidence-372', $writer->collections['evidence'][0]['uuid']);
        self::assertSame('edge-372', $writer->collections['graph_edges'][0]['uuid']);
        self::assertSame('identity-372', $writer->collections['public_identities'][0]['uuid']);
    }

    public function test_reimport_same_manifest_is_idempotent_and_different_manifest_is_rejected(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $writer = new SnapshotTestWriter($this->target());
        $service = new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery']));
        self::assertSame('imported', $service->import($snapshot, $writer, true)['status']);
        self::assertSame('already_imported', $service->import($snapshot, $writer, true)['status']);
        $different = $this->export(array_replace($this->goldenCollections(), ['videos' => [['uuid' => 'other-video', 'revision' => 1]]]));
        $this->expectExceptionMessage('SNAPSHOT_TARGET_ALREADY_RESTORED');
        $service->import($different, $writer, true);
    }

    public function test_golden_372_round_trip_preserves_all_identity_anchors(): void
    {
        $snapshot = $this->export($this->goldenCollections());
        $writer = new SnapshotTestWriter($this->target());
        (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard(['nhk_v3_recovery'])))->import($snapshot, $writer, true);
        self::assertSame('01a094df-6e43-7226-a3a5-78a6c4c05c7e', $writer->collections['captures'][0]['uuid']);
        self::assertSame('01a094df-6ff2-7872-9bbe-ca4e843a68ef', $writer->collections['videos'][0]['uuid']);
        self::assertContains('852da54d-457a-4397-a16d-52d9452ba766', array_column($writer->collections['graph_nodes'], 'uuid'));
        self::assertSame('/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/', $writer->collections['public_identities'][0]['path']);
    }

    private function export(array $collections): \NHK\Core\Application\Snapshot\CanonicalSnapshot
    {
        $source = new class($collections) implements CanonicalSnapshotSource {
            public function __construct(private array $data) {}
            public function environment(): SnapshotEnvironment { return new SnapshotEnvironment('staging', 'https://demo.1945.vn', 'demo_db', 'staging'); }
            public function schemaVersion(): string { return SnapshotCollectionRegistry::SCHEMA_VERSION; }
            public function documentationVersion(): string { return 'docs-test'; }
            public function buildIdentity(): string { return 'build-test'; }
            public function migrationLevel(): array { return ['current' => 20, 'target' => 20]; }
            public function schemaConsistent(): bool { return true; }
            public function isReadOnly(): bool { return true; }
            public function repositoryInventory(): array { return ['capture' => ['adapter' => 'test'], 'video' => ['adapter' => 'test']]; }
            public function collections(): array { return $this->data; }
        };
        return (new CanonicalSnapshotExportService())->export($source, '2026-09-12T00:00:00+00:00');
    }

    private function target(): SnapshotEnvironment
    {
        return new SnapshotEnvironment('v3-video-recovery-1309', 'https://video-recovery.local', 'nhk_v3_recovery', 'recovery');
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function goldenCollections(): array
    {
        $data = array_fill_keys(SnapshotCollectionRegistry::COLLECTIONS, []);
        $data['captures'] = [['uuid' => '01a094df-6e43-7226-a3a5-78a6c4c05c7e', 'post_id' => 454, 'revision' => 3]];
        $data['editorial_posts'] = [['id' => 454, 'post_status' => 'draft']];
        $data['authority_entities'] = [['uuid' => '852da54d-457a-4397-a16d-52d9452ba766', 'stable_key' => 'nhk:variant:odo.36.8']];
        $data['sources'] = [['uuid' => 'source-372', 'revision' => 2]];
        $data['claims'] = [['uuid' => 'claim-372', 'revision' => 2]];
        $data['evidence'] = [['uuid' => 'evidence-372', 'source_id' => 'source-372', 'claim_id' => 'claim-372']];
        $data['videos'] = [['uuid' => '01a094df-6ff2-7872-9bbe-ca4e843a68ef', 'revision' => 7, 'evidence_id' => 'evidence-372']];
        $data['graph_nodes'] = [
            ['uuid' => '01a094df-6ff2-7872-9bbe-ca4e843a68ef', 'entity_type' => 'video'],
            ['uuid' => '852da54d-457a-4397-a16d-52d9452ba766', 'entity_type' => 'variant'],
        ];
        $data['graph_edges'] = [['uuid' => 'edge-372', 'source_id' => '01a094df-6ff2-7872-9bbe-ca4e843a68ef', 'target_id' => '852da54d-457a-4397-a16d-52d9452ba766', 'predicate' => 'about', 'state' => 'ACTIVE']];
        $data['public_identities'] = [['uuid' => 'identity-372', 'owner_id' => '01a094df-6ff2-7872-9bbe-ca4e843a68ef', 'path' => '/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/']];
        return $data;
    }
}

final class SnapshotTestWriter implements CanonicalSnapshotWriter
{
    /** @var array<string,list<array<string,mixed>>> */
    public array $collections = [];
    private bool $inTransaction = false;

    public function __construct(private SnapshotEnvironment $target, private ?string $existing = null) {}
    public function environment(): SnapshotEnvironment { return $this->target; }
    public function schemaVersion(): string { return SnapshotCollectionRegistry::SCHEMA_VERSION; }
    public function schemaConsistent(): bool { return true; }
    public function existingManifestHash(): ?string { return $this->existing; }
    public function readBackManifestHash(): ?string { return $this->existing; }
    public function readBackCollections(): array { return $this->collections; }
    public function isEmpty(): bool { return $this->collections === []; }
    public function begin(): void { $this->inTransaction = true; }
    public function importCollection(string $collection, array $records): void { if (!$this->inTransaction) throw new \RuntimeException('TRANSACTION_REQUIRED'); $this->collections[$collection] = $records; }
    public function commit(string $manifestHash): void { $this->existing = $manifestHash; $this->inTransaction = false; }
    public function rollback(): void { $this->collections = []; $this->inTransaction = false; }
}
