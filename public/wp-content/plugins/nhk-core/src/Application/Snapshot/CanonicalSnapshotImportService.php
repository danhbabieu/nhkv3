<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

use NHK\Core\Contracts\Snapshot\CanonicalSnapshotWriter;

final class CanonicalSnapshotImportService
{
    public function __construct(private readonly RecoveryRuntimeGuard $guard) {}

    /** @return array{status:string,manifest_hash:string,collections_imported:int} */
    public function import(CanonicalSnapshot $snapshot, CanonicalSnapshotWriter $writer, bool $explicitRecoveryMode): array
    {
        SnapshotIntegrityVerifier::assertValid($snapshot);
        $source = new SnapshotEnvironment(
            (string) $snapshot->manifest['source_environment'], (string) $snapshot->manifest['source_site'],
            (string) $snapshot->manifest['source_database_identity'], (string) ($snapshot->manifest['runtime_mode'] ?? 'unknown'),
        );
        $this->guard->assertImportAllowed($source, $writer->environment(), $explicitRecoveryMode);
        if (!$writer->schemaConsistent() || $writer->schemaVersion() !== SnapshotCollectionRegistry::SCHEMA_VERSION) throw new \RuntimeException('SNAPSHOT_TARGET_SCHEMA_UNSUPPORTED');
        $manifestHash = (string) $snapshot->manifest['manifest_hash'];
        $existing = $writer->existingManifestHash();
        if ($existing !== null) {
            if (hash_equals($existing, $manifestHash)) {
                self::assertReadBack($snapshot, $writer, $manifestHash);
                return ['status' => 'already_imported', 'manifest_hash' => $manifestHash, 'collections_imported' => 0];
            }
            throw new \RuntimeException('SNAPSHOT_TARGET_ALREADY_RESTORED');
        }
        if (!$writer->isEmpty()) throw new \RuntimeException('SNAPSHOT_TARGET_NAMESPACE_NOT_EMPTY');
        $writer->begin();
        try {
            $count = 0;
            foreach (SnapshotCollectionRegistry::importOrder() as $collection) {
                $writer->importCollection($collection, $snapshot->collections[$collection]);
                $count++;
            }
            $writer->commit($manifestHash);
        } catch (\Throwable $error) {
            $writer->rollback();
            throw $error;
        }
        self::assertReadBack($snapshot, $writer, $manifestHash);
        return ['status' => 'imported', 'manifest_hash' => $manifestHash, 'collections_imported' => $count];
    }

    private static function assertReadBack(CanonicalSnapshot $snapshot, CanonicalSnapshotWriter $writer, string $manifestHash): void
    {
        if (!hash_equals($manifestHash, (string) $writer->readBackManifestHash())) throw new \RuntimeException('SNAPSHOT_READBACK_MANIFEST_MISMATCH');
        $actual = SnapshotCanonicalizer::collections($writer->readBackCollections());
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $collection) {
            if (!array_key_exists($collection, $actual) || SnapshotCanonicalizer::hashRecords($snapshot->collections[$collection]) !== SnapshotCanonicalizer::hashRecords($actual[$collection])) {
                throw new \RuntimeException('SNAPSHOT_READBACK_CONTENT_MISMATCH:' . $collection);
            }
        }
    }
}
