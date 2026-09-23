<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

/** Keeps public failure semantics aligned with the shared editorial decision. */
final class VideoEditorialOutcome
{
    /** @param array<string,mixed> $result */
    public static function failureCode(array $result): string
    {
        $qualityReport = is_object($result['quality_report'] ?? null)
            ? $result['quality_report']
            : null;
        $readiness = strtoupper(trim((string) ($qualityReport?->readiness ?? $result['quality_readiness'] ?? '')));
        $decision = strtoupper(trim((string) ($result['quality_decision'] ?? '')));
        if ($readiness === 'READY') {
            foreach ((array) ($result['constraint_findings'] ?? []) as $finding) {
                if (!is_array($finding)) continue;
                $code = trim((string) ($finding['code'] ?? ''));
                if ($code !== '') return $code;
            }
            foreach ((array) ($qualityReport?->blockers ?? []) as $blocker) {
                $blocker = trim((string) $blocker);
                if ($blocker !== '') return $blocker;
            }
            return 'VIDEO_EDITORIAL_REVIEW_REQUIRED';
        }
        return 'VIDEO_EDITORIAL_QUALITY_BLOCKED';
    }
}
