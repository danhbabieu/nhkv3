<?php
declare(strict_types=1);

namespace NHK\Core\Application\Compliance;

/**
 * Conservative public-copy guard for generated Video packages.
 *
 * This class classifies meaning-bearing superiority markers; it never changes
 * Source provenance and it does not decide whether a canonical Evidence chain
 * legally supports a claim. Without that chain, generated copy falls back to
 * neutral descriptive wording.
 */
final class PublicClaimCopyPolicy
{
    public function containsUnsupportedSuperiority(string $copy): bool
    {
        $copy = function_exists('mb_strtolower') ? mb_strtolower($copy) : strtolower($copy);
        return preg_match('/(?:\b(?:tốt|đẹp|hiếm|độc|ưa chuộng|phổ biến|hay)\s+nhất\b|\bsố\s*1\b|\bchưa\s+từng\s+có\b|\b(?:best|number\s+one|most\s+popular|the\s+best)\b)/u', $copy) === 1;
    }

    public function safe(string $copy, string $fallback): string
    {
        return $this->containsUnsupportedSuperiority($copy) ? $fallback : trim($copy);
    }
}
