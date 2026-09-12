<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

use NHK\Core\Contracts\Snapshot\CanonicalSnapshotSource;

final class CanonicalSnapshotExportService
{
    public function export(CanonicalSnapshotSource $source, ?string $exportedAt = null): CanonicalSnapshot
    {
        if (!$source->isReadOnly()) throw new \RuntimeException('SNAPSHOT_SOURCE_NOT_READ_ONLY');
        $migration = $source->migrationLevel();
        if ((int) ($migration['current'] ?? -1) !== (int) ($migration['target'] ?? -2)) throw new \RuntimeException('SNAPSHOT_MIGRATIONS_NOT_CURRENT');
        if (!$source->schemaConsistent()) throw new \RuntimeException('SNAPSHOT_SCHEMA_INCONSISTENT');
        if ($source->schemaVersion() !== SnapshotCollectionRegistry::SCHEMA_VERSION) throw new \RuntimeException('SNAPSHOT_SCHEMA_UNSUPPORTED');
        $collections = SnapshotCanonicalizer::collections($source->collections());
        $unknown = array_values(array_diff(array_keys($collections), SnapshotCollectionRegistry::COLLECTIONS));
        if ($unknown !== []) throw new \RuntimeException('SNAPSHOT_COLLECTION_UNSUPPORTED:' . implode(',', $unknown));
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $name) if (!array_key_exists($name, $collections)) throw new \RuntimeException('SNAPSHOT_COLLECTION_MISSING:' . $name);
        $manifest = SnapshotManifestBuilder::build(
            $source->environment(), $source->documentationVersion(), $source->buildIdentity(), $migration,
            $source->repositoryInventory(), $collections, $exportedAt ?? gmdate('c'),
        );
        return new CanonicalSnapshot($manifest, $collections);
    }
}
