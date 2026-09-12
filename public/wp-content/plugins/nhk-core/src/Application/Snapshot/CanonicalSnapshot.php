<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class CanonicalSnapshot
{
    /** @param array<string,mixed> $manifest @param array<string,list<array<string,mixed>>> $collections */
    public function __construct(public readonly array $manifest, public readonly array $collections)
    {
        if (($manifest['manifest_hash'] ?? '') === '' || ($manifest['schema_version'] ?? '') !== SnapshotCollectionRegistry::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('SNAPSHOT_MANIFEST_INVALID');
        }
        $unknown = array_values(array_diff(array_keys($collections), SnapshotCollectionRegistry::COLLECTIONS));
        if ($unknown !== []) throw new \InvalidArgumentException('SNAPSHOT_COLLECTION_UNSUPPORTED:' . implode(',', $unknown));
    }

    public function toArray(): array
    {
        return ['manifest' => $this->manifest, 'collections' => $this->collections];
    }
}
