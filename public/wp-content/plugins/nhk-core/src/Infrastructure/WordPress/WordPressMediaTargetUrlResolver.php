<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\WordPress;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Contracts\Media\FirstPartyMediaTargetUrlResolver;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Media\MediaException;

/** Resolves an exact first-party public route to one registered canonical target. */
final class WordPressMediaTargetUrlResolver implements FirstPartyMediaTargetUrlResolver
{
    /** @param callable|null $postUrlResolver @param callable|null $homeUrlResolver */
    public function __construct(
        private PublicRouteResolver $authorityRoutes,
        private $postUrlResolver = null,
        private $homeUrlResolver = null,
        private ?HistoricPublicRouteResolver $historicRoutes = null,
    ) {}

    /** @return array{type:string,id:string} */
    public function resolve(string $url): array
    {
        $relativePath = $this->firstPartyRelativePath($url);
        $candidates = $this->resolveCurrentPath($relativePath, $url);
        if (count($candidates) === 1) return array_values($candidates)[0];
        if (count($candidates) > 1) throw new MediaException('MEDIA_TARGET_URL_AMBIGUOUS');

        if ($this->historicRoutes !== null) {
            $historic = $this->historicRoutes->resolveHistoric($relativePath);
            if (($historic['status'] ?? '') === 'FOUND') {
                $targetPath = $this->normalizeRelativePath((string) ($historic['target'] ?? ''));
                if ($targetPath !== $relativePath) {
                    $currentUrl = $this->absoluteUrl($targetPath);
                    $current = $this->resolveCurrentPath($targetPath, $currentUrl);
                    if (count($current) === 1) return array_values($current)[0];
                    if (count($current) > 1) throw new MediaException('MEDIA_TARGET_URL_AMBIGUOUS');
                }
            }
        }

        throw new MediaException('MEDIA_TARGET_NOT_FOUND');
    }

    /** @return array<string,array{type:string,id:string}> */
    private function resolveCurrentPath(string $relativePath, string $absoluteUrl): array
    {
        $candidates = [];
        $postResolver = is_callable($this->postUrlResolver)
            ? $this->postUrlResolver
            : [new WordPressPostUrlResolver(), 'resolve'];
        try {
            $post = $postResolver($absoluteUrl);
            if (is_array($post) && strtolower(trim((string) ($post['type'] ?? ''))) === 'wp_post') {
                $id = trim((string) ($post['id'] ?? ''));
                if (preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $id) === 1) $candidates['wp_post:' . $id] = ['type' => 'wp_post', 'id' => $id];
            }
        } catch (\InvalidArgumentException|MediaException) {
            // A first-party semantic route is not a native Post. Other failures
            // are independently represented by the route owner below.
        }

        $segments = array_values(array_filter(explode('/', trim($relativePath, '/')), static fn (string $segment): bool => $segment !== ''));
        foreach ($this->authorityRoutes->types()->all() as $definition) {
            $type = strtolower(trim((string) ($definition->type ?? '')));
            if ($type === '') continue;
            $entity = $this->authorityRoutes->resolve($type, $segments);
            if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
            $canonicalPath = $this->normalizeRelativePath((string) ($this->authorityRoutes->path($entity) ?? ''));
            if ($canonicalPath !== $relativePath) continue;
            $candidates[$type . ':' . $entity->canonicalId] = ['type' => $type, 'id' => $entity->canonicalId];
        }
        return $candidates;
    }

    private function firstPartyRelativePath(string $url): string
    {
        $parts = parse_url(trim($url));
        $homeUrl = is_callable($this->homeUrlResolver)
            ? (string) ($this->homeUrlResolver)()
            : (function_exists('home_url') ? (string) home_url('/') : '');
        $home = $homeUrl !== '' ? parse_url($homeUrl) : false;
        if (!is_array($parts) || !is_array($home)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new MediaException('MEDIA_TARGET_URL_INVALID');
        }

        $targetPort = isset($parts['port']) ? (int) $parts['port'] : 443;
        $homePort = isset($home['port']) ? (int) $home['port'] : 443;
        if ($targetPort !== $homePort) throw new MediaException('MEDIA_TARGET_URL_INVALID');

        $path = (string) ($parts['path'] ?? '');
        $decodedPath = rawurldecode($path);
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '//')
            || str_contains($path, '\\') || preg_match('#(?:^|/)\.\.?(?:/|$)#', $decodedPath) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new MediaException('MEDIA_TARGET_URL_INVALID');
        }

        $homePath = rtrim((string) ($home['path'] ?? ''), '/');
        if ($homePath !== '') {
            if ($path !== $homePath && !str_starts_with($path, $homePath . '/')) throw new MediaException('MEDIA_TARGET_URL_INVALID');
            $path = substr($path, strlen($homePath)) ?: '/';
        }
        return $this->normalizeRelativePath($path);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') return '/';
        return '/' . trim($path, '/') . '/';
    }

    private function absoluteUrl(string $path): string
    {
        $homeUrl = is_callable($this->homeUrlResolver)
            ? rtrim((string) ($this->homeUrlResolver)(), '/')
            : rtrim((string) (function_exists('home_url') ? home_url('/') : ''), '/');
        return $homeUrl . ($path[0] === '/' ? $path : '/' . $path);
    }
}
