<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Builds deterministic transient KnowledgeUnits without changing canonical Claims. */
final class KnowledgeUnitBuilder
{
    /** @param list<array<string,mixed>> $candidates @param array<string,mixed> $subject @param array<string,mixed> $profile @param array<string,mixed> $inputContext */
    public function build(array $candidates, array $subject, string $topic, array $profile, array $inputContext = []): KnowledgeUnitBuildResult
    {
        $units = [];
        $grounding = [];
        $excluded = [];
        $groups = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $role = strtoupper(trim((string) ($candidate['semantic_role'] ?? '')));
            $claimType = strtolower(trim((string) ($candidate['claim_type'] ?? '')));
            if (in_array($role, ['GROUNDING', 'PROVENANCE_ONLY', 'CONTROL_ONLY'], true) || $claimType === 'provenance') {
                $grounding[] = $candidate;
                continue;
            }
            if (($candidate['eligibility'] ?? '') !== 'eligible') {
                $excluded[] = $this->exclude($candidate, 'INELIGIBLE');
                continue;
            }
            if (($candidate['publicly_composable'] ?? true) !== true) {
                $excluded[] = $this->exclude($candidate, 'NOT_PUBLICLY_COMPOSABLE');
                continue;
            }
            $text = trim((string) ($candidate['text'] ?? $candidate['claim_text'] ?? ''));
            if ($text === '') {
                $excluded[] = $this->exclude($candidate, 'EMPTY_PROPOSITION');
                continue;
            }
            $fingerprint = $this->fingerprint($candidate, $text);
            $nearKey = $this->nearKey($candidate, $text);
            $groupKey = $this->groupKey($candidate) . '|' . $nearKey;
            if (!isset($groups[$groupKey])) $groups[$groupKey] = ['candidate' => $candidate, 'fingerprint' => $fingerprint, 'supporting' => []];
            $groups[$groupKey]['supporting'][] = $candidate;
        }

        foreach ($groups as $group) {
            $representative = $group['candidate'];
            $supporting = $group['supporting'];
            $aspects = $this->aspects($representative, $topic);
            $evidenceRefs = [];
            $provenanceTrace = [];
            foreach ($supporting as $claim) {
                foreach ((array) ($claim['source_ids'] ?? ($claim['provenance_references']['source_ids'] ?? [])) as $id) $evidenceRefs['source:' . (string) $id] = true;
                foreach ((array) ($claim['evidence_ids'] ?? ($claim['provenance_references']['evidence_ids'] ?? [])) as $id) $evidenceRefs['evidence:' . (string) $id] = true;
                $provenanceTrace[] = ['claim_id' => (string) ($claim['claim_id'] ?? ''), 'claim_revision' => max(1, (int) ($claim['claim_revision'] ?? 1)), 'source_ids' => array_values((array) ($claim['source_ids'] ?? ($claim['provenance_references']['source_ids'] ?? []))), 'evidence_ids' => array_values((array) ($claim['evidence_ids'] ?? ($claim['provenance_references']['evidence_ids'] ?? [])))];
            }
            $units[] = new KnowledgeUnit($representative, array_values($supporting), (string) $group['fingerprint'], $aspects, array_keys($evidenceRefs), $provenanceTrace, $this->utility($representative, $topic), true);
        }
        $status = $units === [] ? 'THIN' : (($this->coveredAspects($units) === [] || count($units) === 1) ? 'PARTIAL' : 'SUFFICIENT');
        $diagnostics = [
            'candidate_count' => count($candidates),
            'grounding_count' => count($grounding),
            'excluded_count' => count($excluded),
            'unit_count' => count($units),
            'coverage_aspects' => $this->coveredAspects($units),
            'coverage_status' => $status,
            'deduplication' => 'subject-scope-facet-role-plus-normalized-proposition',
        ];
        return new KnowledgeUnitBuildResult($units, $grounding, $excluded, $diagnostics);
    }

    /** @param array<string,mixed> $candidate */
    private function groupKey(array $candidate): string
    {
        $subject = is_array($candidate['resolved_primary_subject'] ?? null) ? $candidate['resolved_primary_subject'] : ($candidate['original_subject'] ?? []);
        return implode('|', [(string) ($subject['id'] ?? ''), strtolower((string) ($candidate['scope'] ?? ($subject['type'] ?? ''))), strtolower((string) ($candidate['facet'] ?? '')), strtoupper((string) ($candidate['semantic_role'] ?? 'READER_FACT'))]);
    }

    /** @param array<string,mixed> $candidate */
    private function fingerprint(array $candidate, string $text): string
    {
        return hash('sha256', $this->groupKey($candidate) . '|' . implode(' ', $this->tokens($text)));
    }

    /** @param array<string,mixed> $candidate */
    private function nearKey(array $candidate, string $text): string
    {
        $tokens = $this->tokens($text);
        sort($tokens, SORT_STRING);
        return implode(' ', $tokens);
    }

    /** @param array<string,mixed> $candidate */
    private function aspects(array $candidate, string $topic): array
    {
        $facet = trim((string) ($candidate['facet'] ?? $candidate['knowledge_facet'] ?? ''));
        if ($facet !== '') return [$this->aspectKey($facet)];
        $tokens = $this->tokens((string) ($candidate['text'] ?? $candidate['claim_text'] ?? ''));
        if ($tokens === []) {
            $tokens = $this->tokens($topic);
        }
        if ($tokens === []) return [];
        sort($tokens, SORT_STRING);
        return ['proposition:' . substr(hash('sha256', implode(' ', $tokens)), 0, 16)];
    }

    private function aspectKey(string $value): string { return 'facet:' . implode('_', $this->tokens($value)); }

    /** @param list<KnowledgeUnit> $units */
    private function coveredAspects(array $units): array
    {
        $aspects = [];
        foreach ($units as $unit) foreach ((array) ($unit->toArray()['coverage_aspects'] ?? []) as $aspect) $aspects[$aspect] = true;
        return array_keys($aspects);
    }

    /** @param array<string,mixed> $candidate */
    private function utility(array $candidate, string $topic): float
    {
        $text = $this->tokens((string) ($candidate['text'] ?? ''));
        $topicTokens = $this->tokens($topic);
        return round(count(array_intersect($text, $topicTokens)) / max(1, count($topicTokens)) + (($candidate['evidence']['status'] ?? '') === 'eligible' ? 1.0 : 0.0), 6);
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function exclude(array $candidate, string $reason): array
    {
        $candidate['exclusion_reasons'] = array_values(array_unique(array_merge((array) ($candidate['exclusion_reasons'] ?? []), [$reason])));
        return $candidate;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $raw = preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [];
        $stop = ['của', 'và', 'là', 'có', 'một', 'được', 'the', 'of', 'a'];
        return array_values(array_unique(array_filter($raw, static fn (string $token): bool => $token !== '' && !in_array($token, $stop, true))));
    }
}

final readonly class KnowledgeUnitBuildResult
{
    /** @param list<KnowledgeUnit> $units @param list<array<string,mixed>> $grounding @param list<array<string,mixed>> $excluded @param array<string,mixed> $diagnostics */
    public function __construct(public array $units, public array $grounding, public array $excluded, public array $diagnostics) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['units' => array_map(static fn (KnowledgeUnit $unit): array => $unit->toArray(), $this->units), 'grounding' => $this->grounding, 'excluded' => $this->excluded, 'diagnostics' => $this->diagnostics];
    }
}
