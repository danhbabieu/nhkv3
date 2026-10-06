<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

use NHK\Core\Application\Dictionary\DictionaryDuplicateCandidateAudit;

/** Reuses the canonical Dictionary duplicate audit without reimplementing it. */
final class DictionaryDuplicateAuditAdapter
{
    public function __construct(private DictionaryDuplicateCandidateAudit $audit) {}

    /** @return array{status:string,clusters:list<array<string,mixed>>,next_cursor:?string,complete:bool,rows_read:int} */
    public function run(int $limit, ?string $cursor): array
    {
        $result = $this->audit->run($limit, $cursor);
        if (($result['status'] ?? '') !== 'available') {
            return [
                'status' => 'BLOCKED',
                'clusters' => [],
                'next_cursor' => null,
                'complete' => false,
                'rows_read' => 0,
                'diagnostics' => ['code' => (string) ($result['reason'] ?? 'DICTIONARY_AUDIT_UNAVAILABLE')],
            ];
        }

        $clusters = [];
        foreach ((array) ($result['clusters'] ?? []) as $cluster) {
            if (!is_array($cluster)) continue;
            $candidates = array_values(array_filter((array) ($cluster['candidates'] ?? []), 'is_array'));
            $clusters[] = [
                'owner' => 'Dictionary',
                'cluster_id' => 'dictionary:' . (string) ($cluster['cluster_key'] ?? ''),
                'classification' => 'REVIEW_REQUIRED',
                'confidence_class' => 'HIGH',
                'canonical_ids' => array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['entry_id'] ?? ''), $candidates), static fn (string $id): bool => $id !== ''))),
                'active_states' => array_values(array_map(static fn (array $row): string => ((int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED'), $candidates)),
                'revisions' => array_values(array_map(static fn (array $row): array => ['canonical_id' => (string) ($row['entry_id'] ?? ''), 'entry_revision' => (int) ($row['entry_revision'] ?? 0), 'sense_revision' => (int) ($row['sense_revision'] ?? 0)], $candidates)),
                'identity_signals' => ['normalized_form' => (string) ($cluster['normalized_form'] ?? ''), 'candidate_count' => count($candidates)],
                'conflicting_signals' => array_values(array_filter((array) ($cluster['reasons'] ?? []), static fn (mixed $reason): bool => in_array($reason, ['DIVERGENT_CONTEXT', 'CONTEXTUAL_HOMOGRAPH', 'INACTIVE_CANDIDATE'], true))),
                'reasons' => array_values(array_map('strval', (array) ($cluster['reasons'] ?? []))),
                'recommended_review_action' => 'REVIEW_DICTIONARY_SENSE_AND_OWNER_MAPPING;DO_NOT_MERGE_AUTOMATICALLY',
                'cursor_page_provenance' => ['cursor' => $cursor, 'source' => 'DictionaryDuplicateCandidateAudit', 'read_only' => true],
            ];
        }

        return [
            'status' => ($result['complete'] ?? true) ? 'COMPLETE' : 'PARTIAL',
            'clusters' => $clusters,
            'next_cursor' => isset($result['next_cursor']) ? (string) $result['next_cursor'] : null,
            'complete' => (bool) ($result['complete'] ?? true),
            'rows_read' => (int) ($result['rows_read'] ?? 0),
            'diagnostics' => ['source' => 'DictionaryDuplicateCandidateAudit', 'read_only' => true],
        ];
    }
}
