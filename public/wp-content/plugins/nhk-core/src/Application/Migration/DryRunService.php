<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

use NHK\Core\Shared\Uuid\UuidCodec;

final class DryRunService
{
    private const SUPPORTED_TYPES = ['wp_post', 'category', 'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'media', 'video', 'knowledge', 'source', 'evidence', 'legacy_media_asset'];

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
            if ($result['reason'] === 'URL_MAPPING_READY') $report['url_mapping']++;
        }
        return $report;
    }

    /** @param array<string,mixed> $record @param array<string,list<string>> $checksums */
    private function inspect(array $record, array &$checksums): array
    {
        $type = (string) ($record['type'] ?? '');
        if ($type === 'url') return trim((string) ($record['source_path'] ?? '')) !== '' && trim((string) ($record['target_path'] ?? '')) !== '' ? ['status' => 'mapped', 'reason' => 'URL_MAPPING_READY'] : ['status' => 'skipped', 'reason' => 'INVALID_URL_MAPPING'];
        if ($type === 'relation') {
            if ((string) ($record['source_key'] ?? '') === '' || (string) ($record['target_key'] ?? '') === '') return ['status' => 'skipped', 'reason' => 'INVALID_RELATION'];
            if (!empty($record['source_missing']) || !empty($record['target_missing'])) return ['status' => 'skipped', 'reason' => 'MISSING_ENDPOINT'];
            return ['status' => 'mapped', 'reason' => 'RELATION_READY'];
        }
        if (!in_array($type, self::SUPPORTED_TYPES, true)) return ['status' => 'skipped', 'reason' => 'UNSUPPORTED_LEGACY_TYPE'];
        if (!empty($record['conflict'])) return ['status' => 'conflict', 'reason' => 'CONFLICT_REQUIRES_REVIEW'];
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
}
