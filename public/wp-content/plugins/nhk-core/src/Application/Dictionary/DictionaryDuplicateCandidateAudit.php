<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryDuplicateAuditReader;

final class DictionaryDuplicateCandidateAudit
{
    public function __construct(private DictionaryDuplicateAuditReader $reader) {}

    public function run(int $limit = 1000, ?string $cursor = null): array
    {
        $limit = max(1, min(10000, $limit));
        try {
            if (method_exists($this->reader, 'readPage')) {
                $page = $this->reader->readPage($limit, $cursor);
                if (!is_array($page) || !array_key_exists('rows', $page) || !is_array($page['rows']) || !array_key_exists('next_cursor', $page)) throw new \RuntimeException('DICTIONARY_DUPLICATE_AUDIT_RESPONSE_INVALID');
                if ($page['next_cursor'] !== null && (!is_string($page['next_cursor']) || $page['next_cursor'] === '')) throw new \RuntimeException('DICTIONARY_DUPLICATE_AUDIT_RESPONSE_INVALID');
                $rows = $page['rows'];
                $nextCursor = $page['next_cursor'];
                $complete = $nextCursor === null;
            } else {
                $rows = $this->reader->read($limit);
                $nextCursor = null;
                $complete = true;
            }
            if (!is_array($rows)) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE', 'clusters' => []];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'reason' => 'DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE', 'clusters' => []];
        }

        $groups = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $normalized = trim((string) ($row['normalized_form'] ?? ''));
            if ($normalized === '') continue;
            $candidate = $this->candidate($row);
            $identity = implode('|', [$candidate['entry_id'], $candidate['form_id'], $candidate['sense_id']]);
            if (isset($seen[$identity])) continue;
            $seen[$identity] = true;
            $groups[$normalized][] = $candidate;
        }

        $clusters = [];
        foreach ($groups as $normalized => $candidates) {
            if (count($candidates) < 2) continue;
            $clusters[] = [
                'cluster_key' => $normalized,
                'normalized_form' => $normalized,
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
                'reasons' => $this->reasons($candidates),
                'review_status' => 'REVIEW_REQUIRED',
                'diagnostics' => ['read_only' => true, 'automatic_repair' => false],
            ];
        }
        usort($clusters, static fn (array $left, array $right): int => strcmp($left['cluster_key'], $right['cluster_key']));
        return ['status' => 'available', 'clusters' => $clusters, 'count' => count($clusters), 'rows_read' => count($rows), 'read_only' => true, 'complete' => $complete, 'next_cursor' => $nextCursor];
    }

    private function candidate(array $row): array
    {
        return [
            'entry_id' => trim((string) ($row['entry_id'] ?? '')),
            'form_id' => trim((string) ($row['form_id'] ?? (($row['normalized_form'] ?? '') . ':' . ($row['form_text'] ?? '')))),
            'sense_id' => trim((string) ($row['sense_id'] ?? '')),
            'form_text' => trim((string) ($row['form_text'] ?? '')),
            'normalized_form' => trim((string) ($row['normalized_form'] ?? '')),
            'entry_status' => strtoupper(trim((string) ($row['entry_status'] ?? 'UNKNOWN'))),
            'sense_status' => strtoupper(trim((string) ($row['sense_status'] ?? 'UNKNOWN'))),
            'entry_revision' => (int) ($row['entry_revision'] ?? 0),
            'sense_revision' => (int) ($row['sense_revision'] ?? 0),
            'context' => is_array($row['context'] ?? null) ? $row['context'] : [],
            'destination_type' => ($row['destination_type'] ?? null) !== null ? trim((string) $row['destination_type']) : null,
            'destination_id' => ($row['destination_id'] ?? null) !== null ? trim((string) $row['destination_id']) : null,
            'state' => (int) ($row['state'] ?? 0),
        ];
    }

    private function reasons(array $candidates): array
    {
        $reasons = ['EXACT_NORMALIZED_FORM_COLLISION'];
        $entryIds = array_values(array_unique(array_map(static fn (array $candidate): string => $candidate['entry_id'], $candidates)));
        $senseIds = array_values(array_unique(array_map(static fn (array $candidate): string => $candidate['sense_id'], $candidates)));
        if (count($entryIds) > 1) $reasons[] = 'DUPLICATE_ENTRY_CANDIDATE';
        if (count($entryIds) === 1 && count($senseIds) > 1) $reasons[] = 'MULTI_SENSE_SINGLE_ENTRY';
        $contexts = array_values(array_unique(array_map(fn (array $candidate): string => $this->canonical($candidate['context']), $candidates)));
        if (count($contexts) > 1) { $reasons[] = 'DIVERGENT_CONTEXT'; $reasons[] = 'CONTEXTUAL_HOMOGRAPH'; }
        $owners = array_values(array_unique(array_filter(array_map(static fn (array $candidate): string => ($candidate['destination_type'] ?? '') . ':' . ($candidate['destination_id'] ?? ''), $candidates), static fn (string $owner): bool => $owner !== ':')));
        if (count($owners) === 1) $reasons[] = 'SAME_SEMANTIC_OWNER';
        if (count($senseIds) > 1) $reasons[] = 'MULTIPLE_SENSES';
        foreach ($candidates as $candidate) {
            if ($candidate['state'] !== 1 || $candidate['entry_status'] !== 'APPROVED' || $candidate['sense_status'] !== 'APPROVED') {
                $reasons[] = 'INACTIVE_CANDIDATE';
                break;
            }
        }
        return array_values(array_unique($reasons));
    }

    private function canonical(array $value): string
    {
        ksort($value, SORT_STRING);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
