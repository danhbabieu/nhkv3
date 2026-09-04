<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\Seo\SeoReadinessResult;

final class SeoReadinessPolicy
{
    /** @param array<string,mixed> $snapshot */
    public function evaluate(array $snapshot): SeoReadinessResult
    {
        if (($snapshot['runtime_available'] ?? true) !== true) return new SeoReadinessResult(SeoReadinessResult::UNAVAILABLE, ['RUNTIME_UNAVAILABLE']);
        if (($snapshot['applicable'] ?? true) !== true) return new SeoReadinessResult(SeoReadinessResult::NOT_APPLICABLE, []);

        $reasons = [];
        if (($snapshot['public_identity'] ?? true) !== true) $reasons[] = 'MISSING_PUBLIC_IDENTITY';
        if (($snapshot['canonical_identity'] ?? true) !== true) $reasons[] = 'AMBIGUOUS_CANONICAL_SUBJECT';
        if (($snapshot['canonical_url'] ?? '') === '') $reasons[] = 'MISSING_PUBLIC_IDENTITY';
        if (($snapshot['content_sufficient'] ?? true) !== true) $reasons[] = 'INSUFFICIENT_PUBLIC_CONTENT';
        if (($snapshot['public_eligible'] ?? true) !== true) $reasons[] = 'COMPLIANCE_BLOCKED';
        if (strtoupper((string) ($snapshot['compliance'] ?? 'PASS')) === 'BLOCKED') $reasons[] = 'COMPLIANCE_BLOCKED';
        $reasons = array_values(array_unique($reasons));
        $status = $reasons === [] ? SeoReadinessResult::READY : (($snapshot['public_eligible'] ?? true) !== true || ($snapshot['canonical_identity'] ?? true) !== true ? SeoReadinessResult::BLOCKED : SeoReadinessResult::INCOMPLETE);
        $structured = ($snapshot['structured_data_applicable'] ?? true) === false ? ['STRUCTURED_DATA_INAPPLICABLE'] : [];
        return new SeoReadinessResult($status, $reasons, $structured);
    }
}
