<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

use NHK\Core\Contracts\Snapshot\CanonicalSnapshotSource;

final class CanonicalSnapshotExportService
{
    public function export(CanonicalSnapshotSource $source, ?string $exportedAt = null): CanonicalSnapshot
    {
        $migration = $source->migrationLevel();
        if ((int) ($migration['current'] ?? -1) !== (int) ($migration['target'] ?? -2)) throw new \RuntimeException('SNAPSHOT_MIGRATIONS_NOT_CURRENT');
        return $this->build($source, $migration, $exportedAt, null, null);
    }

    /**
     * Export the exact source state immediately before one registered UP migration.
     *
     * This is intentionally separate from export(): a normal snapshot remains
     * unavailable while migrations are pending, and this path cannot be used
     * for an arbitrary or multi-step migration gap.
     */
    public function exportPreMigration(CanonicalSnapshotSource $source, int $targetMigration, ?string $exportedAt = null): CanonicalSnapshot
    {
        $migration = $source->migrationLevel();
        $current = (int) ($migration['current'] ?? -1);
        $target = (int) ($migration['target'] ?? -2);
        if ($targetMigration <= 0 || $current !== ($targetMigration - 1) || $target !== $targetMigration) {
            throw new \RuntimeException('SNAPSHOT_PRE_MIGRATION_LEVEL_INVALID');
        }
        if (!$source->environment()->isStaging()) throw new \RuntimeException('SNAPSHOT_PRE_MIGRATION_SOURCE_NOT_STAGING');
        return $this->build($source, $migration, $exportedAt, 'pre_migration', $targetMigration);
    }

    private function build(CanonicalSnapshotSource $source, array $migration, ?string $exportedAt, ?string $exportMode, ?int $preMigrationTarget): CanonicalSnapshot
    {
        if (!$source->isReadOnly()) throw new \RuntimeException('SNAPSHOT_SOURCE_NOT_READ_ONLY');
        if (!$source->schemaConsistent()) throw new \RuntimeException('SNAPSHOT_SCHEMA_INCONSISTENT');
        if ($source->schemaVersion() !== SnapshotCollectionRegistry::SCHEMA_VERSION) throw new \RuntimeException('SNAPSHOT_SCHEMA_UNSUPPORTED');
        $collections = SnapshotCanonicalizer::collections($source->collections());
        $unknown = array_values(array_diff(array_keys($collections), SnapshotCollectionRegistry::COLLECTIONS));
        if ($unknown !== []) throw new \RuntimeException('SNAPSHOT_COLLECTION_UNSUPPORTED:' . implode(',', $unknown));
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $name) if (!array_key_exists($name, $collections)) throw new \RuntimeException('SNAPSHOT_COLLECTION_MISSING:' . $name);
        $historicalConflicts = SnapshotHistoricalConflictPolicy::detect($collections);
        $manifest = SnapshotManifestBuilder::build(
            $source->environment(), $source->documentationVersion(), $source->buildIdentity(), $migration,
            $source->repositoryInventory(), $collections, $exportedAt ?? gmdate('c'),
            $historicalConflicts,
            $exportMode,
            $preMigrationTarget,
        );
        return new CanonicalSnapshot($manifest, $collections);
    }
}
