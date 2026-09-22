<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * Shared read-only seam for editorial Claim discovery and eligibility.
 *
 * It deliberately returns an application/read model. It does not persist
 * Claims, Graph paths, Evidence or editorial selections.
 */
final class EditorialClaimRetrievalService
{
    public function __construct(private ClaimRetrievalEngine $engine, private int $defaultLimit = 50)
    {
    }

    /**
     * @param array<string,mixed> $resolvedSubject
     * @param array<string,mixed> $hints
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function retrieve(array $resolvedSubject, string $topic, array $hints = [], array $profile = []): array
    {
        $subjectId = trim((string) ($resolvedSubject['id'] ?? $resolvedSubject['canonical_subject_id'] ?? ''));
        $subjectType = strtolower(trim((string) ($resolvedSubject['type'] ?? $resolvedSubject['entity_type'] ?? '')));
        if ($subjectId === '' || $subjectType === '') {
            return [
                'status' => 'review',
                'items' => [],
                'eligible_claims' => [],
                'blockers' => ['RESOLVED_SUBJECT_REQUIRED'],
                'diagnostics' => ['result_limit' => 0, 'profile' => $profile],
            ];
        }

        $limit = max(1, min(200, (int) ($profile['result_limit'] ?? $this->defaultLimit)));
        $raw = $this->engine->retrieve([
            'raw_input' => trim($topic),
            'subject_resolution' => ['subjects' => [['id' => $subjectId, 'type' => $subjectType] + $resolvedSubject]],
            'contextual_hints' => array_slice($hints, 0, 12, true),
            'profile' => $profile,
            'result_limit' => $limit,
        ]);

        $items = [];
        foreach (array_slice((array) ($raw['items'] ?? []), 0, $limit) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $claimSubjectId = (string) ($item['subject_id'] ?? '');
            $claimSubjectType = (string) ($item['subject_type'] ?? '');
            $eligible = ($item['decision'] ?? '') === 'include';
            $items[] = $item + [
                'original_subject' => ['id' => $claimSubjectId, 'type' => $claimSubjectType],
                'resolved_primary_subject' => ['id' => $subjectId, 'type' => $subjectType],
                'graph_path' => (array) ($item['relation_path'] ?? []),
                'retrieval_origin' => $claimSubjectId === $subjectId ? 'direct' : 'neighborhood',
                'eligibility' => $eligible ? 'eligible' : 'ineligible',
                'scope_compatibility' => $this->scopeStatus($item),
                'provenance_references' => [
                    'source_ids' => array_values((array) ($item['source_ids'] ?? [])),
                    'evidence_ids' => array_values((array) ($item['evidence_ids'] ?? [])),
                ],
                'evidence' => [
                    'status' => $this->evidenceStatus((string) ($item['evidence_status'] ?? '')),
                    'raw_status' => (string) ($item['evidence_status'] ?? ''),
                ],
                'exclusion_reasons' => $eligible ? [] : $this->exclusionReasons($item),
            ];
        }

        $eligible = array_values(array_filter($items, static fn (array $item): bool => ($item['eligibility'] ?? '') === 'eligible'));
        return [
            'status' => (string) ($raw['status'] ?? 'available'),
            'items' => $items,
            'eligible_claims' => $eligible,
            'blockers' => array_values((array) ($raw['blockers'] ?? [])),
            'diagnostics' => [
                'result_limit' => $limit,
                'candidate_count' => count($items),
                'eligible_count' => count($eligible),
                'profile' => $profile,
                'bounded_hints' => count(array_slice($hints, 0, 12, true)),
            ],
        ];
    }

    private function scopeStatus(array $item): string
    {
        return in_array('SEMANTIC_SCOPE_NOT_APPLICABLE', (array) ($item['warnings'] ?? []), true)
            || in_array('SPECIMEN_SCOPE_LIMIT', (array) ($item['warnings'] ?? []), true)
            ? 'incompatible'
            : 'compatible';
    }

    private function evidenceStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'SUPPORTED_WITHIN_SCOPE', 'VERIFIED', 'APPROVED' => 'eligible',
            'NOT_REQUIRED' => 'not_required',
            'UNAVAILABLE' => 'unavailable',
            'INCOMPATIBLE_SCOPE', 'EVIDENCE_SCOPE_INCOMPATIBLE' => 'incompatible',
            default => 'missing',
        };
    }

    /** @return list<string> */
    private function exclusionReasons(array $item): array
    {
        $reasons = [];
        foreach ((array) ($item['warnings'] ?? []) as $warning) {
            $reasons[] = (string) $warning;
        }
        if (($item['decision'] ?? '') === 'review' && $this->evidenceStatus((string) ($item['evidence_status'] ?? '')) === 'missing') {
            $reasons[] = 'EVIDENCE_MISSING';
        }
        if (($item['decision'] ?? '') === 'exclude' && ($item['reason'] ?? '') === 'Claim is not relevant to the editorial topic') {
            $reasons[] = 'TOPIC_IRRELEVANT';
        }
        return array_values(array_unique($reasons));
    }
}
