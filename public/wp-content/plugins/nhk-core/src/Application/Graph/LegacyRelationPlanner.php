<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Classifies legacy canonical rows without promoting names or keys to identity. */
final class LegacyRelationPlanner
{
    /** @return array<string,mixed> */
    public function resolve(array $record): array
    {
        $type = trim((string) ($record['type'] ?? ''));
        $uuid = trim((string) ($record['uuid'] ?? ''));
        $stableKey = trim((string) ($record['stable_key'] ?? ''));
        $metadata = is_array($record['provenance'] ?? null) ? $record['provenance'] : [];

        if ($type === 'classification') {
            return ['status' => 'REGISTRY_GAP', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => 'CLASSIFIED_AS_NOT_REGISTERED'];
        }

        $formula = match ($type) {
            'model' => ['predicate' => 'model_of', 'target_type' => 'brand', 'field' => 'brand_uuid'],
            'variant' => ['predicate' => 'variant_of', 'target_type' => 'model', 'field' => 'model_uuid'],
            'knowledge' => ['predicate' => 'about', 'target_type' => (is_string($metadata['subject_type'] ?? null) ? trim($metadata['subject_type']) : ''), 'field' => 'subject_uuid'],
            'video' => ['predicate' => 'about', 'target_type' => (is_string($metadata['target_type'] ?? null) ? trim($metadata['target_type']) : ''), 'field' => 'target_uuid'],
            default => null,
        };
        if ($formula === null) return ['status' => 'NOT_APPLICABLE', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => 'NO_REGISTERED_REQUIRED_RELATION'];

        $expected = ['predicate' => $formula['predicate'], 'source_type' => $type, 'source_uuid' => $uuid, 'target_type' => $formula['target_type'] ?: 'UNKNOWN_UNTIL_REVIEW'];
        $target = $metadata[$formula['field']] ?? null;
        if ($formula['target_type'] === '' || !is_string($target) || trim($target) === '') {
            return ['status' => 'RELATION_PENDING', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => 'MISSING_RELATION_METADATA', 'expected_relation' => $expected];
        }

        $target = trim($target);
        $candidate = new RelationBackfillCandidate($uuid, $stableKey, $type, $type, $uuid, $formula['predicate'], $formula['target_type'], $target);
        return ['status' => 'MISSING_DETERMINISTIC', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => 'STRUCTURED_RELATION_METADATA', 'candidate' => $candidate->toArray(), 'expected_relation' => $expected + ['target_uuid' => $target]];
    }
}
