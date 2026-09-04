<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\Seo\SeoRuntimeReadbackResult;

final class SeoRuntimeReadback
{
    public function verify(array $expected, callable $reader): SeoRuntimeReadbackResult
    {
        try { $observed = $reader(); } catch (\Throwable) { return new SeoRuntimeReadbackResult('ENVIRONMENT_BLOCKED'); }
        if (!is_array($observed)) return new SeoRuntimeReadbackResult('MISMATCH', [], ['INVALID_RUNTIME_RESPONSE']);
        $mismatches = [];
        foreach ($expected as $field => $value) if (($observed[$field] ?? null) !== $value) $mismatches[] = strtoupper((string) $field) . '_MISMATCH';
        return new SeoRuntimeReadbackResult($mismatches === [] ? 'PASS' : 'MISMATCH', $observed, $mismatches);
    }
}
