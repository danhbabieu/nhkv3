<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

final class ArticleSeoGate
{
    public function evaluate(array $preflight): array
    {
        $blockers = [];
        $warnings = [];
        foreach (['intent', 'subject', 'canonical_url', 'title', 'h1'] as $field) if (($preflight[$field] ?? null) === null || $preflight[$field] === '' || $preflight[$field] === []) $blockers[] = 'MISSING_' . strtoupper($field);
        if (($preflight['indexable'] ?? false) !== true) $blockers[] = 'INDEXABILITY_BLOCKED';
        $this->mediaDiagnostics($preflight, $blockers, $warnings);
        if (strtoupper((string) ($preflight['compliance'] ?? 'PASS')) === 'BLOCKED') $blockers[] = 'COMPLIANCE_BLOCKED';
        return ['ready' => $blockers === [], 'canonical_url' => $preflight['canonical_url'] ?? null, 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** @param array<string,mixed> $preflight @param list<string> $blockers @param list<string> $warnings */
    private function mediaDiagnostics(array $preflight, array &$blockers, array &$warnings): void
    {
        if (($preflight['media_complete'] ?? true) === true) return;

        $blueprintStatus = strtolower(trim((string) ($preflight['media_blueprint_status'] ?? '')));
        $blueprint = is_array($preflight['media_blueprint'] ?? null) ? $preflight['media_blueprint'] : [];
        if ($blueprintStatus === 'invalid' || ($blueprint !== [] && ($blueprint['valid'] ?? true) !== true)) {
            $blockers[] = 'INVALID_MEDIA_BLUEPRINT';
            return;
        }

        $pipelineStatus = strtolower(trim((string) ($preflight['media_pipeline_status'] ?? '')));
        if (in_array($pipelineStatus, ['failed', 'failure', 'error', 'unavailable'], true)) {
            $blockers[] = 'MEDIA_PIPELINE_FAILURE';
            return;
        }

        $requirement = strtoupper(trim((string) ($preflight['media_requirement'] ?? $preflight['visual_support_requirement'] ?? '')));
        if ($requirement === 'OPTIONAL_VISUAL_SUPPORT') {
            $warnings[] = 'OPTIONAL_MEDIA_MISSING';
            return;
        }

        $blockers[] = 'MEDIA_INCOMPLETE';
    }
}
