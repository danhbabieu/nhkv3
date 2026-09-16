<?php
declare(strict_types=1);

namespace NHK\Core\Application\Compliance;

/** Fail-closed boundary between internal orchestration packets and public prose. */
final class PublicEditorialCopyGuard
{
    /** @return string The unchanged, verified copy. */
    public function assertSafe(string $copy): string
    {
        $copy = trim($copy);
        $patterns = [
            '/\b(?:SOURCE_FACT|USER_HINT|CANONICAL_CONTEXT)\b/i',
            '/\bsubject_resolution_packet\b/i',
            '/\bstable_key\b/i',
            '/\bcanonical\s+UUID\b/i',
            '/\bEvidence\s+pending\b/i',
            '/\bKnowledge\s+candidate\b/i',
            '/\bKnowledge\b/i',
            '/\bGraph\s+relation\s+diagnostics?\b/i',
            '/\bcanonical\s+(?:Variant|Model)\b/i',
            '/\b(?:canonical\s+)?(?:Variant|Model)\s+(?:theo|scope|relation|diagnostic)/iu',
            '/\bSource\/Evidence\b/i',
            '/Trong bối cảnh hồ sơ đã được kiểm chứng/ui',
            '/\[trong phạm vi đã kiểm chứng\]/ui',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
        ];
        foreach ($patterns as $pattern) if (preg_match($pattern, $copy) === 1) throw new \RuntimeException('PUBLIC_INTERNAL_JARGON_LEAK');
        return $copy;
    }

    /** Verify only fields intended for readers; context/facts remain private machine metadata. */
    public function assertEditorialPackage(array $package): array
    {
        foreach (['title', 'summary', 'body', 'why_this_matters'] as $field) if (is_string($package[$field] ?? null)) $this->assertSafe((string) $package[$field]);
        $seo = is_array($package['seo'] ?? null) ? $package['seo'] : [];
        foreach (['title', 'description'] as $field) if (is_string($seo[$field] ?? null)) $this->assertSafe((string) $seo[$field]);
        return $package;
    }
}
