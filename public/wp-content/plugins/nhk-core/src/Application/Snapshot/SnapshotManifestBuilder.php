<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotManifestBuilder
{
    /** @param array<string,array<string,mixed>> $repositoryInventory @param array<string,list<array<string,mixed>>> $collections */
    public static function build(
        SnapshotEnvironment $environment,
        string $documentationVersion,
        string $buildIdentity,
        array $migrationLevel,
        array $repositoryInventory,
        array $collections,
        string $exportedAt,
    ): array {
        $inventory = [];
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $name) {
            $rows = $collections[$name] ?? null;
            if (!is_array($rows)) throw new \RuntimeException('SNAPSHOT_COLLECTION_MISSING:' . $name);
            $inventory[$name] = ['count' => count($rows), 'sha256' => SnapshotCanonicalizer::hashRecords($rows)];
        }
        $manifest = [
            'schema_version' => SnapshotCollectionRegistry::SCHEMA_VERSION,
            'documentation_version' => $documentationVersion,
            'manifest_hash' => '',
            'source_environment' => $environment->name,
            'source_site' => $environment->site,
            'source_database_identity' => $environment->database,
            'runtime_mode' => $environment->runtimeMode,
            'exported_at' => $exportedAt,
            'migration_level' => ['current' => (int) ($migrationLevel['current'] ?? -1), 'target' => (int) ($migrationLevel['target'] ?? -1)],
            'object_counts' => array_map(static fn (array $item): int => $item['count'], $inventory),
            'content_hashes' => array_map(static fn (array $item): string => $item['sha256'], $inventory),
            'repositories' => $repositoryInventory,
        ];
        $hashInput = $manifest;
        unset($hashInput['manifest_hash'], $hashInput['exported_at']);
        $manifest['manifest_hash'] = hash('sha256', SnapshotCanonicalizer::encode($hashInput));
        return $manifest;
    }
}
