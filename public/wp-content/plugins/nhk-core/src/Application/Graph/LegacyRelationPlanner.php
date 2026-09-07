<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Classifies legacy canonical rows without promoting names or keys to identity. */
final class LegacyRelationPlanner
{
    /** @param array<string,array<string,string>> $canonicalByType */
    public function __construct(private readonly array $canonicalByType = []) {}

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
        if ($formula['target_type'] !== '' && is_string($target) && trim($target) !== '') {
            $target = trim($target);
            if (!$this->canonicalTargetExists($formula['target_type'], $target)) {
                return ['status' => 'RELATION_PENDING', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => 'TARGET_NOT_CANONICAL', 'expected_relation' => $expected];
            }
            return $this->candidateResult($uuid, $stableKey, $type, $formula['predicate'], $formula['target_type'], $target, 'STRUCTURED_RELATION_METADATA', $expected + ['target_uuid' => $target]);
        }

        $stable = $this->stableKeyTarget($type, $stableKey);
        if ($stable['status'] === 'MISSING_DETERMINISTIC') {
            return $this->candidateResult($uuid, $stableKey, $type, $formula['predicate'], $stable['target_type'], $stable['target_uuid'], 'STABLE_KEY_HIERARCHY', $expected + ['target_type' => $stable['target_type'], 'target_uuid' => $stable['target_uuid']]);
        }
        return ['status' => 'RELATION_PENDING', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => $stable['reason'], 'expected_relation' => $expected];
    }

    /** @return array{status:string,target_type?:string,target_uuid?:string,reason?:string} */
    private function stableKeyTarget(string $type, string $stableKey): array
    {
        if ($stableKey === '' || $this->canonicalByType === []) return ['status' => 'RELATION_PENDING', 'reason' => 'MISSING_RELATION_METADATA'];
        if ($type === 'model' && preg_match('/^nhk:model:([a-z0-9][a-z0-9._-]*)$/', $stableKey, $match) === 1) {
            $namespace = explode('.', $match[1], 2)[0];
            $brandKey = 'nhk:brand:' . $namespace;
            if (isset($this->canonicalByType['brand'][$brandKey])) return ['status' => 'MISSING_DETERMINISTIC', 'target_type' => 'brand', 'target_uuid' => $this->canonicalByType['brand'][$brandKey]];
            return ['status' => 'RELATION_PENDING', 'reason' => 'NO_CANONICAL_TARGET'];
        }
        if ($type === 'variant' && preg_match('/^nhk:variant:([a-z0-9][a-z0-9._-]*)$/', $stableKey, $match) === 1) {
            return $this->longestModelPrefix($match[1]);
        }
        if ($type === 'knowledge' && preg_match('/^nhk:knowledge:([a-z0-9][a-z0-9._:-]*)$/', $stableKey, $match) === 1) {
            return $this->longestCanonicalPrefix($match[1]);
        }
        return ['status' => 'RELATION_PENDING', 'reason' => 'MISSING_RELATION_METADATA'];
    }

    /** @return array{status:string,target_type?:string,target_uuid?:string,reason?:string} */
    private function longestModelPrefix(string $suffix): array
    {
        $matches = [];
        foreach ($this->canonicalByType['model'] ?? [] as $key => $uuid) {
            $modelSuffix = substr($key, strlen('nhk:model:'));
            if ($suffix !== $modelSuffix && !str_starts_with($suffix, $modelSuffix . '.')) continue;
            $matches[$modelSuffix] = $uuid;
        }
        return $this->selectLongest('model', $matches);
    }

    /** @return array{status:string,target_type?:string,target_uuid?:string,reason?:string} */
    private function longestCanonicalPrefix(string $suffix): array
    {
        $matches = [];
        foreach (['variant', 'model', 'brand', 'classification', 'music', 'component'] as $type) {
            foreach ($this->canonicalByType[$type] ?? [] as $key => $uuid) {
                $targetSuffix = substr($key, strlen('nhk:' . $type . ':'));
                if ($suffix !== $targetSuffix && !str_starts_with($suffix, $targetSuffix . '.')) continue;
                $matches[] = [$type, $uuid, strlen($targetSuffix)];
            }
        }
        if ($matches === []) return ['status' => 'RELATION_PENDING', 'reason' => 'NO_CANONICAL_TARGET'];
        $longest = max(array_column($matches, 2));
        $matches = array_values(array_filter($matches, static fn (array $match): bool => $match[2] === $longest));
        $unique = [];
        foreach ($matches as $match) $unique[$match[0] . ':' . $match[1]] = $match;
        if (count($unique) !== 1) return ['status' => 'RELATION_PENDING', 'reason' => 'AMBIGUOUS_CANONICAL_PREFIX'];
        $match = array_values($unique)[0];
        return ['status' => 'MISSING_DETERMINISTIC', 'target_type' => $match[0], 'target_uuid' => $match[1]];
    }

    /** @param array<string,string> $matches @return array{status:string,target_type?:string,target_uuid?:string,reason?:string} */
    private function selectLongest(string $targetType, array $matches): array
    {
        if ($matches === []) return ['status' => 'RELATION_PENDING', 'reason' => 'NO_CANONICAL_TARGET'];
        $longest = max(array_map('strlen', array_keys($matches)));
        $matches = array_filter($matches, static fn (string $uuid, string $suffix): bool => strlen($suffix) === $longest, ARRAY_FILTER_USE_BOTH);
        if (count($matches) !== 1) return ['status' => 'RELATION_PENDING', 'reason' => 'AMBIGUOUS_CANONICAL_PREFIX'];
        return ['status' => 'MISSING_DETERMINISTIC', 'target_type' => $targetType, 'target_uuid' => (string) array_values($matches)[0]];
    }

    private function canonicalTargetExists(string $type, string $uuid): bool
    {
        return $this->canonicalByType === [] || in_array($uuid, $this->canonicalByType[$type] ?? [], true);
    }

    /** @return array<string,mixed> */
    private function candidateResult(string $uuid, string $stableKey, string $type, string $predicate, string $targetType, string $targetUuid, string $reason, array $expected): array
    {
        $candidate = new RelationBackfillCandidate($uuid, $stableKey, $type, $type, $uuid, $predicate, $targetType, $targetUuid, $reason);
        return ['status' => 'MISSING_DETERMINISTIC', 'record_uuid' => $uuid, 'record_type' => $type, 'reason' => $reason, 'candidate' => $candidate->toArray(), 'expected_relation' => $expected];
    }
}
