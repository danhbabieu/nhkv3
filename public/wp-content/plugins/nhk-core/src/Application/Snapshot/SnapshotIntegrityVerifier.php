<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotIntegrityVerifier
{
    public static function assertValid(CanonicalSnapshot $snapshot): void
    {
        $manifest = $snapshot->manifest;
        $hashInput = $manifest;
        $actualManifestHash = (string) ($hashInput['manifest_hash'] ?? '');
        unset($hashInput['manifest_hash'], $hashInput['exported_at']);
        if (!hash_equals($actualManifestHash, hash('sha256', SnapshotCanonicalizer::encode($hashInput)))) throw new \RuntimeException('SNAPSHOT_MANIFEST_HASH_MISMATCH');
        foreach (SnapshotCollectionRegistry::COLLECTIONS as $name) {
            if (!array_key_exists($name, $snapshot->collections)) throw new \RuntimeException('SNAPSHOT_COLLECTION_MISSING:' . $name);
            $rows = $snapshot->collections[$name];
            if ((int) ($manifest['object_counts'][$name] ?? -1) !== count($rows)) throw new \RuntimeException('SNAPSHOT_COUNT_MISMATCH:' . $name);
            if (!hash_equals((string) ($manifest['content_hashes'][$name] ?? ''), SnapshotCanonicalizer::hashRecords($rows))) throw new \RuntimeException('SNAPSHOT_CONTENT_HASH_MISMATCH:' . $name);
            self::assertUniqueIdentities($name, $rows);
        }
        self::assertReferences($snapshot->collections);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function assertUniqueIdentities(string $collection, array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $identity = (string) ($row['uuid'] ?? $row['canonical_id'] ?? $row['id'] ?? $row['stable_key'] ?? '');
            if ($identity === '') continue;
            if (isset($seen[$identity])) throw new \RuntimeException('SNAPSHOT_DUPLICATE_IDENTITY:' . $collection . ':' . $identity);
            $seen[$identity] = true;
        }
    }

    /** @param array<string,list<array<string,mixed>>> $collections */
    private static function assertReferences(array $collections): void
    {
        $index = [];
        foreach ($collections as $collection => $rows) foreach ($rows as $row) {
            foreach (['uuid', 'canonical_id', 'id', 'stable_key'] as $key) if (isset($row[$key]) && (string) $row[$key] !== '') $index[(string) $row[$key]] = true;
        }
        $rules = [
            'capture_addenda' => ['capture_id', 'capture_uuid'],
            'proposal_approvals' => ['proposal_id', 'proposal_uuid'],
            'proposal_audit_history' => ['proposal_id', 'proposal_uuid'],
            'apply_attempts' => ['proposal_id', 'proposal_uuid'],
            'evidence' => ['source_id', 'source_uuid', 'claim_id', 'claim_uuid'],
            'graph_edges' => ['source_id', 'source_uuid', 'target_id', 'target_uuid'],
            'public_identities' => ['owner_id', 'owner_uuid'],
        ];
        foreach ($rules as $collection => $keys) foreach ($collections[$collection] as $row) foreach ($keys as $key) {
            if (isset($row[$key]) && (string) $row[$key] !== '' && !isset($index[(string) $row[$key]])) {
                throw new \RuntimeException('SNAPSHOT_REFERENCE_MISSING:' . $collection . ':' . $key . ':' . $row[$key]);
            }
        }
    }
}
