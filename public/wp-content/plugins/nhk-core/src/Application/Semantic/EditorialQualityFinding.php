<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Internal, redacted evidence for a public editorial quality finding. */
final readonly class EditorialQualityFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $repairability,
        public string $ruleId,
        public string $surface,
        public string $field,
        public int $round,
        public string $packageFingerprint,
        public string $originKind,
        public string $originComponent,
        public string $originRole,
        public string $matchKind,
        public string $offendingSpan,
        public string $offendingFingerprint,
        public string $message,
        public string $findingSource,
        public string $attemptId = '',
        public int $attemptNo = 0,
        public string $dimension = 'EDITORIAL_QUALITY',
    ) {
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $context @return array<string,mixed> */
    public static function normalize(array $finding, array $context = []): array
    {
        $span = trim((string) ($finding['offending_span'] ?? $finding['phrase'] ?? ''));
        $code = trim((string) ($finding['code'] ?? 'PUBLIC_INTERNAL_JARGON_LEAK'));
        $severity = trim((string) ($finding['severity'] ?? 'BLOCK'));
        $repairability = trim((string) ($finding['repairability'] ?? match ($severity) {
            'REPAIRABLE' => 'REPAIRABLE',
            'HARD_BLOCK', 'BLOCK' => 'NOT_REPAIRABLE',
            default => 'UNKNOWN',
        }));
        return (new self(
            $code,
            $severity,
            $repairability,
            trim((string) ($finding['rule_id'] ?? $context['rule_id'] ?? 'editorial_quality.unknown')),
            trim((string) ($finding['surface'] ?? $context['surface'] ?? 'VIDEO_PUBLIC_COPY')),
            trim((string) ($finding['field'] ?? '')),
            (int) ($finding['round'] ?? $context['round'] ?? 0),
            trim((string) ($finding['package_fingerprint'] ?? $context['package_fingerprint'] ?? '')),
            trim((string) ($context['origin_kind'] ?? $finding['origin_kind'] ?? 'UNKNOWN')) ?: 'UNKNOWN',
            trim((string) ($context['origin_component'] ?? $finding['origin_component'] ?? '')),
            trim((string) ($context['origin_role'] ?? $finding['origin_role'] ?? 'NONE')) ?: 'NONE',
            trim((string) ($finding['match_kind'] ?? $context['match_kind'] ?? 'INTERNAL_LANGUAGE')),
            $span,
            trim((string) ($finding['offending_fingerprint'] ?? ($span === '' ? '' : hash('sha256', $span)))),
            trim((string) ($finding['message'] ?? $finding['reason'] ?? $code)),
            trim((string) ($finding['finding_source'] ?? $context['finding_source'] ?? 'UNKNOWN')) ?: 'UNKNOWN',
            trim((string) ($finding['attempt_id'] ?? $context['attempt_id'] ?? '')),
            (int) ($finding['attempt_no'] ?? $context['attempt_no'] ?? 0),
            strtoupper(trim((string) ($finding['dimension'] ?? $context['dimension'] ?? self::dimensionFor($code)))),
        ))->toArray();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'repairability' => $this->repairability,
            'rule_id' => $this->ruleId,
            'surface' => $this->surface,
            'field' => $this->field,
            'round' => $this->round,
            'package_fingerprint' => $this->packageFingerprint,
            'origin_kind' => $this->originKind,
            'origin_component' => $this->originComponent,
            'origin_role' => $this->originRole,
            'match_kind' => $this->matchKind,
            'offending_span' => $this->offendingSpan,
            'offending_fingerprint' => $this->offendingFingerprint,
            'message' => $this->message,
            'finding_source' => $this->findingSource,
            'attempt_id' => $this->attemptId,
            'attempt_no' => $this->attemptNo,
            'dimension' => $this->dimension,
        ];
    }

    private static function dimensionFor(string $code): string
    {
        if (in_array($code, ['UNTRACEABLE_FACTUAL_ASSERTION', 'INELIGIBLE_CLAIM_USED', 'INELIGIBLE_SELECTED_CLAIM', 'CLAIM_EVIDENCE_NOT_ELIGIBLE', 'STALE_CLAIM_REVISION', 'EDITORIAL_SCOPE_WIDENED', 'INAPPLICABLE_NEIGHBOR_SELECTED'], true)) return 'FACTUAL_SAFETY';
        if (in_array($code, ['PUBLIC_INTERNAL_JARGON_LEAK', 'UNSUPPORTED_PROMOTIONAL_CLAIM', 'SEO_NOT_READY', 'INVALID_PUBLIC_INTERNAL_LINK', 'VISUAL_SUPPORT_UNRESOLVED'], true)) return 'PUBLICATION_QUALITY';
        return 'EDITORIAL_QUALITY';
    }
}
