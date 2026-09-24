<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

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
                'coverage_status' => self::string(($content['pack']['diagnostics']['coverage_status'] ?? $content['coverage_status'] ?? '')),
                'exact_count' => self::boundedInt($content['pack']['diagnostics']['exact_selected_count'] ?? 0),
                'relaxed_count' => self::boundedInt($content['pack']['diagnostics']['relaxed_selected_count'] ?? 0),
                'contextual_count' => self::boundedInt($content['pack']['diagnostics']['contextual_selected_count'] ?? $content['pack']['diagnostics']['non_exact_selected_count'] ?? 0),
                'uncovered_count' => self::boundedInt($content['pack']['diagnostics']['uncovered_count'] ?? 0),
                'selected_unit_count' => self::boundedInt($content['pack']['diagnostics']['selected_unit_count'] ?? 0),
            ],
            'retrieval' => [
                'status' => self::string($retrieval['status'] ?? ''),
                'candidate_count' => self::boundedInt($retrievalDiagnostics['candidate_count'] ?? count((array) ($retrieval['items'] ?? []))),
                'selected_count' => self::boundedInt($retrievalDiagnostics['selected_count'] ?? count((array) ($content['selected_claims'] ?? []))),
                'stop_reason' => self::string($retrievalDiagnostics['stop_reason'] ?? ''),
                'needs' => $needDiagnostics,
            ],
            'repair' => [
                'decision' => self::string($result['quality_decision'] ?? ''),
                'rounds' => self::boundedInt($result['repair_rounds'] ?? 0),
                're_evaluation_count' => min(20, count((array) ($result['quality_re_evaluations'] ?? []))),
            ],
            'terminal' => ['codes' => $codes],
        ];
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
