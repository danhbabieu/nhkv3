<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

use NHK\Core\Shared\Uuid\UuidCodec;

final class DryRunService
{
    private const SUPPORTED_TYPES = ['wp_post', 'category', 'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'media', 'video', 'knowledge', 'source', 'evidence', 'legacy_media_asset', 'legacy_semantic_projection'];

    /** @param list<array<string,mixed>> $records */
    public function run(array $records): array
    {
        $report = ['source_count' => count($records), 'source_counts' => [], 'mapped' => 0, 'mapped_by_type' => [], 'skipped' => 0, 'skipped_by_reason' => [], 'conflict' => 0, 'duplicate_candidate' => 0, 'invalid_relation' => 0, 'missing_endpoint' => 0, 'url_mapping' => 0, 'items' => []];
        $checksums = [];
        foreach ($records as $index => $record) {
            $result = is_array($record) ? $this->inspect($record, $checksums) : ['status' => 'skipped', 'reason' => 'INVALID_RECORD'];
            $type = is_array($record) ? (string) ($record['type'] ?? '__missing__') : '__invalid__';
            $report['source_counts'][$type] = ($report['source_counts'][$type] ?? 0) + 1;
            $report['items'][] = ['index' => $index, ...$result];
            if ($result['status'] === 'mapped') { $report['mapped']++; $report['mapped_by_type'][$type] = ($report['mapped_by_type'][$type] ?? 0) + 1; }
            if ($result['status'] === 'skipped') { $report['skipped']++; $report['skipped_by_reason'][$result['reason']] = ($report['skipped_by_reason'][$result['reason']] ?? 0) + 1; }
            if ($result['status'] === 'conflict') $report['conflict']++;
            if ($result['reason'] === 'DUPLICATE_CANDIDATE') $report['duplicate_candidate']++;
            if ($result['reason'] === 'INVALID_RELATION') $report['invalid_relation']++;
            if ($result['reason'] === 'MISSING_ENDPOINT') $report['missing_endpoint']++;
            if (in_array($result['reason'], ['URL_MAPPING_READY', 'READY_NOOP'], true)) $report['url_mapping']++;
        }
        return $report;
    }

    /** @param array<string,mixed> $record @param array<string,list<string>> $checksums */
    private function inspect(array $record, array &$checksums): array
    {
        $type = (string) ($record['type'] ?? '');
        if (!empty($record['conflict'])) return ['status' => 'conflict', 'reason' => 'CONFLICT_REQUIRES_REVIEW'];
        if ($type === 'url') {
            $sourcePath = trim((string) ($record['source_path'] ?? ''));
            $targetPath = trim((string) ($record['target_path'] ?? ''));
            if ($sourcePath === '') return ['status' => 'skipped', 'reason' => 'INVALID_URL_MAPPING'];
            if ($sourcePath === '/' && $targetPath === '') return ['status' => 'mapped', 'reason' => 'READY_NOOP'];
            if ($targetPath !== '') {
                if (!str_starts_with($sourcePath, '/') || !str_starts_with($targetPath, '/') || str_contains($sourcePath, '..') || str_contains($targetPath, '..')) return ['status' => 'skipped', 'reason' => 'INVALID_URL_MAPPING'];
                $entityType = trim((string) ($record['target_entity_type'] ?? ''));
                $entityId = trim((string) ($record['target_entity_id'] ?? ''));
                $entityKey = trim((string) ($record['target_entity_key'] ?? ''));
                if ($entityType !== '' || $entityId !== '' || $entityKey !== '') {
                    $types = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'knowledge'];
                    if (!in_array($entityType, $types, true) || preg_match('/^[0-9a-f-]{36}$/i', $entityId) !== 1 || $entityKey === '') return ['status' => 'skipped', 'reason' => 'INVALID_URL_MAPPING'];
                }
                return ['status' => 'mapped', 'reason' => 'URL_MAPPING_READY'];
            }
            $targetReason = strtoupper((string) ($record['target_reason'] ?? ''));
            if (in_array($targetReason, ['DOMAIN_TARGETED', 'UNSUPPORTED_MEDIA_REFERENCE', 'RETIRED_LEGACY_GARBAGE'], true)) return ['status' => 'skipped', 'reason' => $targetReason];
            return ['status' => 'skipped', 'reason' => 'INVALID_URL_MAPPING'];
        }
        if ($type === 'relation') {
            if ((string) ($record['source_key'] ?? '') === '' || (string) ($record['target_key'] ?? '') === '') return ['status' => 'skipped', 'reason' => 'INVALID_RELATION'];
            if (!empty($record['source_missing']) || !empty($record['target_missing'])) return ['status' => 'skipped', 'reason' => 'MISSING_ENDPOINT'];
            $predicate = (string) ($record['predicate'] ?? $record['relation_type'] ?? '');
            if (!in_array($predicate, ['about', 'depicts'], true)) return ['status' => 'skipped', 'reason' => 'UNSUPPORTED_LEGACY_TYPE'];
            if (!$this->validNodeReference((string) ($record['source_type'] ?? ''), (string) ($record['source_key'] ?? '')) || !$this->validNodeReference((string) ($record['target_type'] ?? ''), (string) ($record['target_key'] ?? ''))) return ['status' => 'skipped', 'reason' => 'INVALID_RELATION'];
            return ['status' => 'mapped', 'reason' => 'RELATION_READY'];
        }
        if ($type === 'legacy_semantic_projection') {
            if (array_key_exists('body', $record) || array_key_exists('content', $record) || array_key_exists('post_content', $record)) return ['status' => 'skipped', 'reason' => 'PROJECTION_BODY_FORBIDDEN'];
            foreach (['stable_key', 'canonical_object_id', 'canonical_object_type', 'legacy_type'] as $field) if (trim((string) ($record[$field] ?? '')) === '') return ['status' => 'skipped', 'reason' => 'INVALID_PROJECTION_CONTEXT'];
            return ['status' => 'mapped', 'reason' => 'READ_ONLY_CONTEXT_READY'];
        }
        if ($type === 'wp_post') {
            $legacyType = (string) ($record['legacy_type'] ?? '');
            if ($legacyType === 'attachment') return ['status' => 'skipped', 'reason' => 'UNSUPPORTED_MEDIA_REFERENCE'];
            if ($legacyType === 'wp_global_styles') return ['status' => 'skipped', 'reason' => 'RETIRED_LEGACY_GARBAGE'];
            if (!in_array($legacyType, ['nhk_article', 'post', 'page'], true)) return ['status' => 'skipped', 'reason' => 'DOMAIN_TARGETED'];
        }
        if ($type === 'category' && (string) ($record['taxonomy'] ?? '') !== 'category') return ['status' => 'skipped', 'reason' => 'UNSUPPORTED_LEGACY_TYPE'];
        if (!in_array($type, self::SUPPORTED_TYPES, true)) return ['status' => 'skipped', 'reason' => 'UNSUPPORTED_LEGACY_TYPE'];
        if (isset($record['canonical_uuid'])) {
            try { UuidCodec::toBinary((string) $record['canonical_uuid']); } catch (\Throwable) { return ['status' => 'skipped', 'reason' => 'INVALID_IDENTITY']; }
        }
        if ((string) ($record['stable_key'] ?? '') === '') return ['status' => 'skipped', 'reason' => 'INVALID_IDENTITY'];
        $checksum = strtolower((string) ($record['checksum'] ?? ''));
        if ($checksum !== '' && preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) return ['status' => 'skipped', 'reason' => 'INVALID_IDENTITY'];
        if ($checksum !== '') {
            if (isset($checksums[$checksum]) && !in_array((string) ($record['stable_key'] ?? ''), $checksums[$checksum], true)) return ['status' => 'mapped', 'reason' => 'DUPLICATE_CANDIDATE'];
            $checksums[$checksum][] = (string) ($record['stable_key'] ?? '');
        }
        return ['status' => 'mapped', 'reason' => 'READY'];
    }

    private function validNodeReference(string $type, string $key): bool
    {
        $map = ['article' => 'wp_post', 'wp_post' => 'wp_post', 'brand' => 'brand', 'model' => 'model', 'variant' => 'variant', 'movement' => 'movement', 'music' => 'music', 'component' => 'component', 'classification' => 'classification', 'specimen' => 'specimen', 'product' => 'product', 'media' => 'media', 'knowledge' => 'knowledge'];
        if (!isset($map[$type])) return false;
        return $map[$type] === 'wp_post'
            ? preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $key) === 1
            : preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) === 1;
    }
}
