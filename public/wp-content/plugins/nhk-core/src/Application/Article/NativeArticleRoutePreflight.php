<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\EditorialPostState;

/**
 * Validates the native WordPress route that owns one editorial Post.
 *
 * @param callable(EditorialPostState):string|null $resolver
 * @param callable(EditorialPostState):bool|null $collision
 * @param callable(EditorialPostState):string|null $canonical
 */
final class NativeArticleRoutePreflight
{
    public function __construct(
        private $resolver = null,
        private $collision = null,
        private $canonical = null,
    ) {}

    /** @return array{ready:bool,diagnostics:list<string>,slug:string,permalink:string,canonical_url:string,collision:bool,resolver_agreement:bool} */
    public function check(EditorialPostState $draft): array
    {
        $slug = trim($draft->slug);
        $permalink = trim($draft->permalink);
        $canonical = $this->canonical !== null ? trim((string) ($this->canonical)($draft)) : $permalink;
        $resolved = $this->resolver !== null ? trim((string) ($this->resolver)($draft)) : $permalink;
        $collision = $this->collision !== null && (bool) ($this->collision)($draft);
        $diagnostics = [];

        if ($draft->postId < 1) $diagnostics[] = 'NATIVE_ROUTE_POST_UNAVAILABLE';
        if ($slug === '' || preg_match('/[\s\x00-\x1F\x7F\/?#]/u', $slug) === 1) $diagnostics[] = 'NATIVE_ROUTE_SLUG_INVALID';
        if ($permalink === '') $diagnostics[] = 'NATIVE_ROUTE_PERMALINK_UNAVAILABLE';
        elseif (!self::isValidPermalink($permalink)) $diagnostics[] = 'NATIVE_ROUTE_PERMALINK_INVALID';
        if ($resolved === '' || $resolved !== $permalink) $diagnostics[] = 'NATIVE_ROUTE_RESOLVER_DISAGREEMENT';
        if ($canonical === '' || $canonical !== $permalink) $diagnostics[] = 'NATIVE_ROUTE_CANONICAL_MISMATCH';
        if ($collision) $diagnostics[] = 'NATIVE_ROUTE_COLLISION';

        return [
            'ready' => $diagnostics === [],
            'diagnostics' => array_values(array_unique($diagnostics)),
            'slug' => $slug,
            'permalink' => $permalink,
            'canonical_url' => $canonical,
            'collision' => $collision,
            'resolver_agreement' => $resolved !== '' && $resolved === $permalink,
        ];
    }

    public static function isValidPermalink(string $permalink): bool
    {
        if ($permalink === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $permalink) === 1) return false;
        if (str_starts_with($permalink, '/')) return true;
        $parts = parse_url($permalink);
        return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
