<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Bounded, deterministic Claim discovery and selection over owner read ports. */
final class ClaimRetrievalEngine
{
    /** @param callable(array<string,mixed>):array $neighborhood @param callable(array<string,mixed>,array<string,mixed>):array $claims */
    public function __construct(private $neighborhood, private $claims, private int $maxHops = 2, private int $limit = 50) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function retrieve(array $context): array
    {
        $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
        $subjects = is_array($resolution['subjects'] ?? null) ? $resolution['subjects'] : [];
        $intent = strtolower((string) ($context['raw_input'] ?? $context['interpretation']['article_intent'] ?? ''));
        $all = [];
        $blockers = [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) continue;
            $neighborhood = ($this->neighborhood)($subject);
            if (!is_array($neighborhood) || ($neighborhood['status'] ?? 'available') !== 'available') {
                $blockers[] = 'GRAPH_RESEARCH_UNAVAILABLE';
                continue;
            }
            $rows = ($this->claims)($subject, $neighborhood);
            if (!is_array($rows)) { $blockers[] = 'CLAIM_RETRIEVAL_UNAVAILABLE'; continue; }
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $candidate = $this->candidate($row, $subject, $neighborhood, $intent);
                $key = $candidate['claim_id'] . ':' . $candidate['claim_revision'];
                if (!isset($all[$key]) || $candidate['score'] > $all[$key]['score']) $all[$key] = $candidate;
            }
        }
        $items = array_values($all);
        usort($items, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            return $score !== 0 ? $score : strcmp($left['claim_id'], $right['claim_id']);
        });
        $items = array_slice($items, 0, min($this->limit, 200));
        $selected = array_values(array_filter($items, static fn (array $item): bool => $item['decision'] === 'include'));
        return ['status' => $blockers === [] ? 'available' : 'partial', 'items' => $items, 'selected_claims' => $selected, 'blockers' => array_values(array_unique($blockers))];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $subject @param array<string,mixed> $neighborhood @return array<string,mixed> */
    private function candidate(array $row, array $subject, array $neighborhood, string $intent): array
    {
        $id = trim((string) ($row['id'] ?? $row['claim_id'] ?? ''));
        $revision = max(1, (int) ($row['revision'] ?? $row['claim_revision'] ?? 1));
        $claimSubject = (string) ($row['subject_id'] ?? '');
        $subjectId = (string) ($subject['id'] ?? '');
        $path = is_array($row['relation_path'] ?? null) ? $row['relation_path'] : (is_array($row['path'] ?? null) ? $row['path'] : (is_array($row['best_path'] ?? null) ? $row['best_path'] : $this->pathForClaim($claimSubject, $neighborhood)));
        $hop = max(0, count($path));
        $scope = (string) ($row['scope'] ?? 'unknown');
        $provenance = (string) ($row['provenance'] ?? '');
        $evidence = (string) ($row['evidence_status'] ?? 'NO_EVIDENCE');
        $text = (string) ($row['text'] ?? $row['claim_text'] ?? '');
        $score = (float) ($row['relevance'] ?? 0.0);
        $score += $claimSubject !== '' && $claimSubject === $subjectId ? 5.0 : 0.0;
        $score += $hop === 0 ? 3.0 : max(0.0, 2.0 - $hop);
        $score += $evidence === 'SUPPORTED_WITHIN_SCOPE' ? 3.0 : ($evidence === 'INSUFFICIENT_EVIDENCE' ? -1.0 : -2.0);
        $score += $intent !== '' ? $this->overlap($intent, strtolower($text)) : 0.0;
        $decision = 'include';
        $reason = 'subject, scope and evidence are usable within the bounded retrieval policy';
        $warnings = [];
        if ($id === '' || $text === '') { $decision = 'exclude'; $reason = 'claim identity or text is missing'; }
        elseif ($scope === 'specimen-only' && ($subject['type'] ?? '') !== 'specimen') { $decision = 'exclude'; $reason = 'specimen-scoped Claim cannot generalize to this subject'; $warnings[] = 'SPECIMEN_SCOPE_LIMIT'; }
        elseif ($evidence !== 'SUPPORTED_WITHIN_SCOPE') { $decision = 'review'; $reason = 'evidence is absent or insufficient for direct prose'; $warnings[] = 'EVIDENCE_SCOPE_REVIEW'; }
        elseif ($provenance === '') { $decision = 'review'; $reason = 'provenance is unavailable'; $warnings[] = 'PROVENANCE_UNAVAILABLE'; }
        return [
            'claim_id' => $id, 'claim_revision' => $revision, 'text' => $text, 'subject_id' => $claimSubject,
            'scope' => $scope, 'provenance' => $provenance, 'evidence_status' => $evidence,
            'relation_path' => $path, 'hop_count' => $hop, 'score' => round($score, 6),
            'decision' => $decision, 'reason' => $reason, 'warnings' => $warnings,
        ];
    }

    private function overlap(string $left, string $right): float
    {
        $leftWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $left) ?: []));
        $rightWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $right) ?: []));
        if ($leftWords === [] || $rightWords === []) return 0.0;
        return min(2.0, count(array_intersect(array_unique($leftWords), array_unique($rightWords))) / max(1, count(array_unique($leftWords))));
    }

    /** @param array<string,mixed> $neighborhood @return list<array<string,mixed>> */
    private function pathForClaim(string $claimSubject, array $neighborhood): array
    {
        foreach ((array) ($neighborhood['items'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['target_entity_id'] ?? '') === $claimSubject && is_array($item['best_path'] ?? null)) return $item['best_path'];
        }
        return [];
    }
}
