<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

/**
 * Defines the narrow import-only exception for proven historical proposal
 * references whose canonical target no longer exists.
 *
 * This policy never creates an owner and is deliberately stricter than live
 * proposal eligibility. It is snapshot diagnostics, not Governance state.
 */
final class SnapshotHistoricalConflictPolicy
{
    public const TYPE = 'HISTORICAL_REFERENCE_MISSING';
    public const CLASSIFICATION = 'ENTITY_TRULY_MISSING_HISTORICAL_REFERENCE';

    /** @param array<string,list<array<string,mixed>>> $collections @return list<array<string,mixed>> */
    public static function detect(array $collections): array
    {
        $index = self::valueIndex($collections);
        $conflicts = [];
        foreach ($collections['proposals'] ?? [] as $proposal) {
            $missingUuid = self::stringValue($proposal['target_uuid'] ?? null);
            if ($missingUuid === '' || isset($index[$missingUuid])) continue;

            $command = self::arrayValue($proposal['command_json'] ?? ($proposal['payload'] ?? null));
            $expectedType = strtolower(self::stringValue($proposal['target_type'] ?? ($command['target_type'] ?? null)));
            $proposalState = self::proposalState($proposal['state'] ?? null);
            $proposalId = self::proposalId($proposal);
            $approvalCount = self::relatedCount($collections['proposal_approvals'] ?? [], $proposal);
            $attemptCount = self::relatedCount($collections['apply_attempts'] ?? [], $proposal);
            $downstream = self::hasValueOutsideProposalHistory($collections, $missingUuid);

            if ($expectedType !== 'model' || $proposalState !== 'submitted' || $proposalId === '' || $approvalCount !== 0 || $attemptCount !== 0 || $downstream || self::isApplied($proposal)) continue;

            $conflicts[] = [
                'conflict_type' => self::TYPE,
                'owner_type' => 'proposal',
                'proposal_id' => $proposalId,
                'field' => 'target_uuid',
                'missing_uuid' => $missingUuid,
                'expected_type' => $expectedType,
                'proposal_state' => $proposalState,
                'proposal_revision' => (int) ($proposal['revision'] ?? 0),
                'approvals' => 0,
                'apply_attempts' => 0,
                'downstream_resource' => false,
                'classification' => self::CLASSIFICATION,
            ];
        }
        usort($conflicts, static fn (array $left, array $right): int => SnapshotCanonicalizer::encode($left) <=> SnapshotCanonicalizer::encode($right));
        return $conflicts;
    }

    /** @param array<string,list<array<string,mixed>>> $collections @return list<array<string,mixed>> */
    public static function validate(array $collections, mixed $declared): array
    {
        if (!is_array($declared)) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICTS_INVALID');
        $detected = self::detect($collections);
        if (count($detected) !== count($declared)) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICT_SET_MISMATCH');

        $declaredByKey = [];
        foreach ($declared as $conflict) {
            if (!is_array($conflict)) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICT_INVALID');
            $key = self::conflictKey($conflict);
            if ($key === '' || isset($declaredByKey[$key])) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICT_INVALID');
            $declaredByKey[$key] = $conflict;
        }
        foreach ($detected as $conflict) {
            $key = self::conflictKey($conflict);
            if (!isset($declaredByKey[$key])) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICT_UNDECLARED');
            foreach (['conflict_type', 'owner_type', 'proposal_id', 'field', 'missing_uuid', 'expected_type', 'proposal_state', 'proposal_revision', 'approvals', 'apply_attempts', 'downstream_resource', 'classification'] as $field) {
                if (($declaredByKey[$key][$field] ?? null) !== $conflict[$field]) throw new \RuntimeException('SNAPSHOT_HISTORICAL_CONFLICT_MISMATCH:' . $field);
            }
        }
        return $detected;
    }

    /** @param array<string,list<array<string,mixed>>> $collections @return array<string,bool> */
    private static function valueIndex(array $collections): array
    {
        $index = [];
        foreach ($collections as $rows) foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach (['uuid', 'canonical_id', 'stable_key', 'id'] as $field) {
                $value = self::stringValue($row[$field] ?? null);
                if ($value !== '') $index[$value] = true;
            }
        }
        return $index;
    }

    /** @param array<string,list<array<string,mixed>>> $collections */
    private static function hasValueOutsideProposalHistory(array $collections, string $value): bool
    {
        foreach ($collections as $name => $rows) {
            if (in_array($name, ['proposals', 'proposal_approvals', 'proposal_audit_history', 'apply_attempts', 'idempotency_state'], true)) continue;
            foreach ($rows as $row) if (self::containsValue($row, $value)) return true;
        }
        return false;
    }

    private static function containsValue(mixed $haystack, string $needle): bool
    {
        if (is_array($haystack)) foreach ($haystack as $value) if (self::containsValue($value, $needle)) return true;
        return is_scalar($haystack) && (string) $haystack === $needle;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $proposal */
    private static function relatedCount(array $rows, array $proposal): int
    {
        $ids = array_filter(array_unique([
            self::stringValue($proposal['uuid'] ?? null),
            self::stringValue($proposal['proposal_uuid'] ?? null),
            self::stringValue($proposal['id'] ?? null),
        ]), static fn (string $id): bool => $id !== '');
        $count = 0;
        foreach ($rows as $row) {
            foreach (['proposal_id', 'proposal_uuid'] as $field) {
                if (in_array(self::stringValue($row[$field] ?? null), $ids, true)) { $count++; break; }
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $proposal */
    private static function isApplied(array $proposal): bool
    {
        return self::proposalState($proposal['state'] ?? null) === 'applied'
            || in_array(strtolower(self::stringValue($proposal['applied'] ?? null)), ['1', 'true', 'yes'], true)
            || self::stringValue($proposal['applied_at'] ?? null) !== '' && !str_starts_with(self::stringValue($proposal['applied_at'] ?? null), '0000-00-00');
    }

    /** @param array<string,mixed> $proposal */
    private static function proposalId(array $proposal): string
    {
        foreach (['uuid', 'proposal_uuid', 'canonical_id', 'id'] as $field) {
            $value = self::stringValue($proposal[$field] ?? null);
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function proposalState(mixed $value): string
    {
        $state = strtolower(self::stringValue($value));
        return match ($state) {
            '2' => 'submitted',
            '3' => 'approved',
            '7' => 'applied',
            default => $state,
        };
    }

    /** @return array<string,mixed> */
    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return []; }
        return is_array($decoded) ? $decoded : [];
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string,mixed> $conflict */
    private static function conflictKey(array $conflict): string
    {
        return self::stringValue($conflict['proposal_id'] ?? null) . '|' . self::stringValue($conflict['field'] ?? null) . '|' . self::stringValue($conflict['missing_uuid'] ?? null);
    }
}
