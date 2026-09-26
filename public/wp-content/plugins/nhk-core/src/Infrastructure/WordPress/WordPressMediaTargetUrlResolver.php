<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\WordPress;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Authority\AuthorityEntity;

/**
 * Resolves one exact first-party public URL to one existing MediaUsage owner.
 *
 * URL is only a locator. The returned target is always the canonical endpoint
 * type/key resolved by the owning WordPress or Authority route boundary.
 */
final class WordPressMediaTargetUrlResolver
{
    public function __construct(
        private PublicRouteResolver $authorityRoutes,
        private $postUrlResolver = null,
        private $homeUrlResolver = null,
    ) {}

    /** @return array{type:string,id:string} */
    public function resolve(string $url): array
    {
        $relativePath = $this->firstPartyRelativePath($url);
        $candidates = [];

        $postResolver = is_callable($this->postUrlResolver)
            ? $this->postUrlResolver
            : [new WordPressPostUrlResolver(), 'resolve'];
        if (is_callable($postResolver)) {
            try {
                $post = $postResolver($url);
                if (is_array($post)) {
                    $type = strtolower(trim((string) ($post['type'] ?? '')));
                    $id = trim((string) ($post['id'] ?? ''));
                    if ($type === 'wp_post' && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $id) === 1) {
                        $candidates['wp_post:' . $id] = ['type' => 'wp_post', 'id' => $id];
                    }
                }
            } catch (\InvalidArgumentException) {
                // A valid first-party URL may be an Authority route rather
                // than a native WordPress Post. Authority resolution below is
                // still exact and must independently succeed.
            }
        }

        $segments = array_values(array_filter(explode('/', trim($relativePath, '/')), static fn (string $segment): bool => $segment !== ''));
        if ($segments !== []) {
            foreach ($this->authorityRoutes->types()->all() as $definition) {
                $type = is_object($definition) && isset($definition->type) ? strtolower(trim((string) $definition->type)) : '';
                if ($type === '') continue;
                $entity = $this->authorityRoutes->resolve($type, $segments);
                if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
                $path = $this->normalizeRelativePath((string) ($this->authorityRoutes->path($entity) ?? ''));
                if ($path !== $relativePath) continue;
                $candidates[$type . ':' . $entity->canonicalId] = ['type' => $type, 'id' => $entity->canonicalId];
            }
        }

        if ($candidates === []) throw new \InvalidArgumentException('MEDIA_TARGET_NOT_FOUND');
        if (count($candidates) !== 1) throw new \InvalidArgumentException('MEDIA_TARGET_URL_AMBIGUOUS');
        return array_values($candidates)[0];
    }

    private function firstPartyRelativePath(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $homeUrl = is_callable($this->homeUrlResolver)
            ? (string) ($this->homeUrlResolver)()
            : (function_exists('home_url') ? (string) home_url('/') : '');
        $home = $homeUrl !== '' ? parse_url($homeUrl) : false;

        if (!is_array($parts) || !is_array($home)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new \InvalidArgumentException('MEDIA_TARGET_URL_INVALID');
        }

        $targetPort = isset($parts['port']) ? (int) $parts['port'] : 443;
        $homePort = isset($home['port']) ? (int) $home['port'] : (strtolower((string) ($home['scheme'] ?? 'https')) === 'https' ? 443 : 80);
        if ($targetPort !== $homePort) throw new \InvalidArgumentException('MEDIA_TARGET_URL_INVALID');

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '//') || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1) {
            throw new \InvalidArgumentException('MEDIA_TARGET_URL_INVALID');
        }

        $homePath = rtrim((string) ($home['path'] ?? ''), '/');
        if ($homePath !== '') {
            if ($path !== $homePath && !str_starts_with($path, $homePath . '/')) throw new \InvalidArgumentException('MEDIA_TARGET_URL_INVALID');
            $path = substr($path, strlen($homePath));
            if ($path === '') $path = '/';
        }

        return $this->normalizeRelativePath($path);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') return '/';
        return '/' . trim($path, '/') . '/';
    }
}
