<?php
declare(strict_types=1);

namespace NHK\Core\Application\Compliance;

/** Fail-closed boundary between internal orchestration packets and public prose. */
final class PublicEditorialCopyGuard
{
    private const REPAIRABLE_PATTERNS = [
        '/Trong bối cảnh tri thức NHK/iu' => 'Trong bối cảnh đã được xác định',
        '/nguồn tham chiếu cụ thể/iu' => 'nguồn tham chiếu',
        '/không biến cách diễn đạt marketing thành kết luận phổ quát/iu' => 'không nên xem cách giới thiệu đó là kết luận cho mọi trường hợp',
    ];

    private const STRUCTURAL_PATTERNS = [
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
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
    ];

    /** @return string The unchanged, verified copy. */
    public function assertSafe(string $copy): string
    {
        $copy = trim($copy);
        // NHK-managed HTML comments carry machine provenance and regeneration
        // metadata. They are not reader-visible prose and must not be judged
        // as if their internal owner labels were public copy.
        $visibleCopy = trim((string) (preg_replace('/<!--.*?-->/s', '', $copy) ?? $copy));
        foreach (self::STRUCTURAL_PATTERNS as $pattern) if (preg_match($pattern, $visibleCopy) === 1) throw new \RuntimeException('PUBLIC_INTERNAL_JARGON_LEAK');
        return $copy;
    }

    /** @return list<array{field:string,phrase:string,severity:string,code:string,repair:string,reason:string}> */
    public function findings(array $package): array
    {
        $findings = [];
        foreach (['title', 'summary', 'body', 'why_this_matters', 'seo_title', 'seo_description', 'meta_description'] as $field) {
            if (!is_string($package[$field] ?? null)) continue;
            $copy = trim((string) $package[$field]);
            $visibleCopy = trim((string) (preg_replace('/<!--.*?-->/s', '', $copy) ?? $copy));
            foreach (self::STRUCTURAL_PATTERNS as $pattern) {
                if (preg_match_all($pattern, $visibleCopy, $matches) === false || $matches[0] === []) continue;
                foreach (array_values(array_unique($matches[0])) as $phrase) $findings[] = ['field' => $field, 'phrase' => $phrase, 'severity' => 'HARD_BLOCK', 'code' => 'PUBLIC_INTERNAL_JARGON_LEAK', 'repair' => 'USE_AS_IS', 'reason' => 'Structural internal data cannot be safely rewritten.'];
            }
            foreach (self::REPAIRABLE_PATTERNS as $pattern => $_replacement) {
                if (preg_match_all($pattern, $visibleCopy, $matches) === false || $matches[0] === []) continue;
                foreach (array_values(array_unique($matches[0])) as $phrase) $findings[] = ['field' => $field, 'phrase' => $phrase, 'severity' => 'REPAIRABLE', 'code' => 'PUBLIC_INTERNAL_JARGON_LEAK', 'repair' => 'REPAIR_PUBLIC_COPY', 'reason' => 'Generated workflow wording can be replaced without changing factual meaning.'];
            }
        }
        return $findings;
    }

    public function repair(string $copy): string
    {
        foreach (self::REPAIRABLE_PATTERNS as $pattern => $replacement) $copy = (string) preg_replace($pattern, $replacement, $copy);
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
