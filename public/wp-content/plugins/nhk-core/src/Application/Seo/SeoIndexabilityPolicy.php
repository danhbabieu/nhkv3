<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\Seo\{SeoIndexabilityResult, SeoReadinessResult};

final class SeoIndexabilityPolicy
{
    /** @param array<string,mixed> $snapshot */
    public function evaluate(array $snapshot): SeoIndexabilityResult
    {
        $status = strtoupper((string) ($snapshot['readiness'] ?? ''));
        if ($status === SeoReadinessResult::UNAVAILABLE) return new SeoIndexabilityResult(false, ['RUNTIME_UNAVAILABLE']);
        if (($snapshot['canonical_url'] ?? null) === null || ($snapshot['canonical_url'] ?? '') === '') return new SeoIndexabilityResult(false, ['MISSING_PUBLIC_IDENTITY']);
        if (($snapshot['rendered_url'] ?? $snapshot['canonical_url']) !== $snapshot['canonical_url']) return new SeoIndexabilityResult(false, ['CANONICAL_URL_MISMATCH']);
        if (($snapshot['public_eligible'] ?? false) !== true) return new SeoIndexabilityResult(false, ['COMPLIANCE_BLOCKED']);
        if ($status !== SeoReadinessResult::READY) return new SeoIndexabilityResult(false, is_array($snapshot['reasons'] ?? null) ? array_values(array_unique(array_map('strval', $snapshot['reasons']))): ['INSUFFICIENT_PUBLIC_CONTENT']);
        return new SeoIndexabilityResult(true);
    }
}
