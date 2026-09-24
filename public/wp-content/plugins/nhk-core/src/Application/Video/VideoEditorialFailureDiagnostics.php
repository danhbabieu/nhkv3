<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Semantic\EditorialContextPack;

/**
 * Bounded, machine-readable diagnostics for a failed Video editorial attempt.
 * This is a read model only: it never changes quality or lifecycle decisions.
 */
final class VideoEditorialFailureDiagnostics
{
    /** @param array<string,mixed> $result @return array<string,mixed> */
    public static function project(array $result): array
    {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $retrieval = is_array($content['retrieval'] ?? null) ? $content['retrieval'] : [];
        $retrievalDiagnostics = is_array($retrieval['retrieval_diagnostics'] ?? null)
            ? $retrieval['retrieval_diagnostics']
            : (is_array($retrieval['diagnostics'] ?? null) ? $retrieval['diagnostics'] : []);
        $pack = $content['pack'] ?? null;
        $packDiagnostics = $pack instanceof EditorialContextPack ? $pack->diagnostics : [];
        $quality = is_array($result['quality_report'] ?? null) ? $result['quality_report'] : [];
        $findings = array_values(array_filter((array) ($result['constraint_findings'] ?? []), 'is_array'));

        $needs = [];
        foreach (array_slice((array) ($content['semantic_needs'] ?? []), 0, 20) as $need) {
            if (!is_array($need)) continue;
            $needs[] = array_filter([
                'need_id' => self::string($need['need_id'] ?? ''),
                'facet_key' => self::string($need['facet_key'] ?? $need['facet'] ?? ''),
                'concept_key' => self::string($need['concept_key'] ?? $need['concept'] ?? ''),
                'scope' => self::string($need['scope'] ?? ''),
            ], static fn (mixed $value): bool => $value !== '');
        }

        $needDiagnostics = [];
        foreach (array_slice((array) ($retrievalDiagnostics['need_diagnostics'] ?? []), 0, 20) as $diagnostic) {
            if (!is_array($diagnostic)) continue;
            $needDiagnostics[] = array_filter([
                'need_id' => self::string($diagnostic['need_id'] ?? ''),
                'coverage_kind' => self::string($diagnostic['coverage_kind'] ?? ''),
                'candidate_count' => self::boundedInt($diagnostic['candidate_count'] ?? 0),
                'selected_count' => self::boundedInt($diagnostic['selected_count'] ?? 0),
                'rejected_count' => self::boundedInt($diagnostic['rejected_count'] ?? 0),
            ], static fn (mixed $value): bool => $value !== '');
        }

        $codes = [];
        foreach ((array) ($quality['blockers'] ?? []) as $code) {
            $code = self::string($code);
            if ($code !== '') $codes[] = $code;
        }
        foreach ($findings as $finding) {
            if (!in_array(strtoupper(self::string($finding['severity'] ?? '')), ['BLOCK', 'HARD_BLOCK'], true)) continue;
            $code = self::string($finding['code'] ?? '');
            if ($code !== '') $codes[] = $code;
        }
        $codes = array_values(array_unique(array_slice($codes, 0, 30)));

        return [
            'quality' => [
                'readiness' => self::string($quality['readiness'] ?? ''),
                'blockers' => self::codes($quality['blockers'] ?? []),
                'warnings' => self::codes($quality['warnings'] ?? []),
                'dimensions' => self::dimensionSummary($quality['dimensions'] ?? []),
            ],
            'semantic' => [
                'need_count' => count($needs),
                'needs' => $needs,
                'coverage_status' => self::string($packDiagnostics['coverage_status'] ?? $content['coverage_status'] ?? ''),
                'exact_count' => self::boundedInt($packDiagnostics['exact_selected_count'] ?? 0),
                'relaxed_count' => self::boundedInt($packDiagnostics['relaxed_selected_count'] ?? 0),
                'contextual_count' => self::boundedInt($packDiagnostics['contextual_selected_count'] ?? $packDiagnostics['non_exact_selected_count'] ?? 0),
                'uncovered_count' => self::boundedInt($packDiagnostics['uncovered_count'] ?? 0),
                'selected_unit_count' => self::boundedInt($packDiagnostics['selected_unit_count'] ?? 0),
            ],
            'retrieval' => [
                'status' => self::string($retrieval['status'] ?? ''),
                'candidate_count' => self::boundedInt($retrievalDiagnostics['candidate_count'] ?? count((array) ($retrieval['items'] ?? []))),
                'selected_count' => self::boundedInt($retrievalDiagnostics['selected_count'] ?? count((array) ($content['selected_claims'] ?? []))),
                'stop_reason' => self::string($retrievalDiagnostics['stop_reason'] ?? ''),
                'needs' => $needDiagnostics,
            ],
            'trace' => self::trace($content, $pack),
            'repair' => [
                'decision' => self::string($result['quality_decision'] ?? ''),
                'rounds' => self::boundedInt($result['repair_rounds'] ?? 0),
                're_evaluation_count' => min(20, count((array) ($result['quality_re_evaluations'] ?? []))),
            ],
            'terminal' => ['codes' => $codes],
        ];
    }

    /** @return array<string,mixed> */
    private static function trace(array $content, mixed $pack): array
    {
        $selected = $pack instanceof EditorialContextPack ? $pack->selectedClaims : (array) ($content['selected_claims'] ?? []);
        $excluded = $pack instanceof EditorialContextPack ? $pack->excludedCandidates : [];
        $units = [];
        if ($pack instanceof EditorialContextPack) {
            foreach (array_slice($pack->knowledgeUnits, 0, 20) as $unit) {
                if (!is_object($unit) || !method_exists($unit, 'toArray')) continue;
                $data = $unit->toArray();
                $claim = is_array($data['claim'] ?? null) ? $data['claim'] : [];
                $units[] = [
                    'unit_id' => self::string($data['unit_id'] ?? ''),
                    'claim' => self::claimMetadata($claim),
                    'supporting_claims' => array_values(array_map(static fn (mixed $item): array => self::claimMetadata(is_array($item) ? $item : []), array_slice((array) ($data['supporting_claims'] ?? []), 0, 20))),
                    'coverage_aspects' => array_values(array_map([self::class, 'string'], array_slice((array) ($data['coverage_aspects'] ?? []), 0, 20))),
                    'publicly_composable' => ($data['publicly_composable'] ?? false) === true,
                ];
            }
        }

        $editorial = is_array($content['editorial_trace'] ?? null) ? $content['editorial_trace'] : [];
        return [
            'selected_claims' => array_values(array_map(static fn (mixed $claim): array => self::claimMetadata(is_array($claim) ? $claim : []), array_slice($selected, 0, 20))),
            'excluded_claims' => array_values(array_map(static fn (mixed $claim): array => self::claimMetadata(is_array($claim) ? $claim : []), array_slice($excluded, 0, 20))),
            'knowledge_units' => $units,
            'journey' => array_slice((array) ($editorial['journey'] ?? []), 0, 20),
            'composer' => array_values(array_map(static fn (mixed $trace): array => self::claimMetadata(is_array($trace) ? $trace : []), array_slice((array) ($editorial['composer'] ?? []), 0, 20))),
        ];
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    private static function claimMetadata(array $claim): array
    {
        $subject = is_array($claim['original_subject'] ?? null) ? $claim['original_subject'] : [];
        $target = is_array($claim['resolved_primary_subject'] ?? $claim['target_subject'] ?? null) ? ($claim['resolved_primary_subject'] ?? $claim['target_subject']) : [];
        return array_filter([
            'claim_id' => self::string($claim['claim_id'] ?? $claim['id'] ?? ''),
            'claim_revision' => self::boundedInt($claim['claim_revision'] ?? $claim['revision'] ?? 0),
            'canonical_subject_id' => self::string($target['id'] ?? ''),
            'claim_subject_id' => self::string($subject['id'] ?? $claim['subject_id'] ?? ''),
            'claim_subject_type' => self::string($subject['type'] ?? $claim['subject_type'] ?? ''),
            'scope' => self::string($claim['scope'] ?? ''),
            'facet' => self::string($claim['facet'] ?? $claim['knowledge_facet'] ?? ''),
            'eligibility' => self::string($claim['eligibility'] ?? ''),
            'evidence_status' => self::string($claim['evidence']['status'] ?? $claim['evidence_status'] ?? ''),
            'retrieval_origin' => self::string($claim['retrieval_origin'] ?? ''),
            'applicability' => self::string($claim['applicability'] ?? ''),
            'retrieval_tier' => self::string($claim['retrieval_tier'] ?? ''),
            'coverage_kind' => self::string($claim['coverage_kind'] ?? ''),
            'editorial_treatment' => self::string($claim['editorial_treatment'] ?? ''),
            'semantic_context_only' => ($claim['semantic_context_only'] ?? false) === true,
            'broader_context_only' => ($claim['broader_context_only'] ?? false) === true,
            'publicly_composable' => ($claim['publicly_composable'] ?? true) === true,
            'state' => self::string($claim['state'] ?? ''),
            'exclusion_reasons' => self::codes($claim['exclusion_reasons'] ?? []),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /** @param mixed $value */
    private static function string(mixed $value): string
    {
        return trim((string) $value);
    }

    /** @param mixed $value */
    private static function boundedInt(mixed $value): int
    {
        return min(100000, max(0, (int) $value));
    }

    /** @param mixed $codes @return list<string> */
    private static function codes(mixed $codes): array
    {
        return array_values(array_unique(array_slice(array_values(array_filter(array_map([self::class, 'string'], (array) $codes))), 0, 30)));
    }

    /** @param mixed $dimensions @return array<string,array{status:string,severity:string,reasons:list<string>}> */
    private static function dimensionSummary(mixed $dimensions): array
    {
        $result = [];
        foreach (array_slice((array) $dimensions, 0, 20, true) as $name => $dimension) {
            if (!is_array($dimension)) continue;
            $result[(string) $name] = [
                'status' => self::string($dimension['status'] ?? ''),
                'severity' => self::string($dimension['severity'] ?? ''),
                'reasons' => self::codes($dimension['reasons'] ?? []),
            ];
        }
        return $result;
    }
}
