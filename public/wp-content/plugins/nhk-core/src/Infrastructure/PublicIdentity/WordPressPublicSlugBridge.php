<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\PublicIdentity;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;

/**
 * Native WordPress remains the owner of Post/Page/Category/Tag permalinks and
 * collision handling. This bridge changes only the save-time base sanitizer so
 * newly stored native slugs use the same Vietnamese/public-token policy.
 */
final class WordPressPublicSlugBridge
{
    private CanonicalPublicSlugPolicy $slugs;

    public function __construct(?CanonicalPublicSlugPolicy $slugs = null)
    {
        $this->slugs = $slugs ?? new CanonicalPublicSlugPolicy();
    }

    public function register(): void
    {
        if (function_exists('add_filter')) add_filter('sanitize_title', [$this, 'sanitize'], 20, 3);
    }

    public function sanitize(string $sanitized, string $rawTitle, string $context): string
    {
        if ($context !== 'save') return $sanitized;
        $slug = $this->slugs->slug($rawTitle);
        return $slug !== '' ? $slug : $sanitized;
    }
}
