<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only inventory and explicit-mapping backfill planner for legacy rows. */
final class ClassificationFamilyAudit
{
    public function __construct(private AuthorityRepository $authority) {}

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $rows = [];
        foreach ($this->authority->listByType('classification', true) as $entity) {
            $family = trim((string) ($entity->payload['family'] ?? ''));
            $rows[] = ['canonical_uuid' => $entity->canonicalId, 'stable_key' => $entity->stableKey, 'canonical_name' => $entity->canonicalName, 'revision' => $entity->revision, 'state' => $entity->state->name, 'family' => $family !== '' ? $family : null, 'status' => $family !== '' ? 'RESOLVED' : 'UNRESOLVED'];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['stable_key'], $right['stable_key']));
        return ['status' => $rows === [] ? 'EMPTY' : 'AUDITED', 'records' => $rows, 'unresolved_count' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'UNRESOLVED'))];
    }

    /** @param array<string,string> $explicitStableKeyToFamily @return array<string,mixed> */
    public function planBackfill(array $explicitStableKeyToFamily): array
    {
        $updates = []; $unresolved = []; $collisions = [];
        $rows = $this->authority->listByType('classification', true);
        usort($rows, static fn (AuthorityEntity $left, AuthorityEntity $right): int => strcmp($left->stableKey, $right->stableKey));
        foreach ($rows as $entity) {
            $current = trim((string) ($entity->payload['family'] ?? ''));
            if ($current !== '') continue;
            $family = trim((string) ($explicitStableKeyToFamily[$entity->stableKey] ?? ''));
            if ($family === '') { $unresolved[] = ['code' => 'CLASSIFICATION_FAMILY_UNRESOLVED', 'canonical_uuid' => $entity->canonicalId, 'stable_key' => $entity->stableKey]; continue; }
            $updates[] = ['candidate_id' => 'family-' . substr(hash('sha256', $entity->canonicalId . '|' . $entity->revision . '|' . $family), 0, 20), 'operation' => 'update', 'canonical_uuid' => $entity->canonicalId, 'stable_key' => $entity->stableKey, 'expected_revision' => $entity->revision, 'family' => $family, 'payload_patch' => ['family' => $family]];
        }
        return ['status' => $unresolved === [] ? 'READY_FOR_GOVERNED_APPLY' : 'CLASSIFICATION_FAMILY_UNRESOLVED', 'updates' => $updates, 'unresolved' => $unresolved, 'collisions' => $collisions, 'idempotent' => true];
    }
}
