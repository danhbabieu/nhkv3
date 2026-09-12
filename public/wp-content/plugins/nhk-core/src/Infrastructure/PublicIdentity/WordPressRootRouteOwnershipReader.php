<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\PublicIdentity;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Contracts\PublicIdentity\RootRouteOwnershipReader;

/**
 * Read-only composition adapter for the global root namespace.
 *
 * The optional callback must be supplied by the existing route owner/registry
 * when one exists. Without it, an unoccupied-looking slug remains a typed
 * registry gap instead of being silently claimed by the semantic router.
 */
final class WordPressRootRouteOwnershipReader implements RootRouteOwnershipReader
{
    /** @param \Closure(string):array<string,mixed>|null $registeredRouteReader */
    public function __construct(private ?\Closure $registeredRouteReader = null) {}

    /** @return array{status:string,owners:list<array<string,mixed>>,source:string} */
    public function inspect(string $path): array
    {
        if (!preg_match('#^/([a-z0-9][a-z0-9-]*)/$#', $path, $matches)) {
            return ['status' => 'UNAVAILABLE', 'owners' => [], 'source' => 'root-route-policy'];
        }
        $slug = $matches[1];
        if (in_array($slug, PublicRouteResolver::reservedRoots(), true)) {
            return ['status' => 'AVAILABLE', 'owners' => [['kind' => 'reserved_route', 'route' => '/' . $slug . '/']], 'source' => 'public-route-registry'];
        }

        $native = $this->nativeOwners($slug);
        if ($native !== []) return ['status' => 'AVAILABLE', 'owners' => $native, 'source' => 'wordpress'];

        if ($this->registeredRouteReader !== null) {
            $result = ($this->registeredRouteReader)($path);
            if (($result['status'] ?? '') !== 'AVAILABLE') {
                return ['status' => 'UNAVAILABLE', 'owners' => [], 'source' => (string) ($result['source'] ?? 'registered-route-owner')];
            }
            return ['status' => 'AVAILABLE', 'owners' => array_values(array_filter((array) ($result['owners'] ?? []), 'is_array')), 'source' => (string) ($result['source'] ?? 'registered-route-owner')];
        }

        return ['status' => 'UNAVAILABLE', 'owners' => [], 'source' => 'global-route-registry'];
    }

    /** @return list<array<string,mixed>> */
    private function nativeOwners(string $slug): array
    {
        if (!function_exists('get_posts')) return [];
        $owners = [];
        if (function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page instanceof \WP_Post && function_exists('is_post_publicly_viewable') && is_post_publicly_viewable($page)) {
                $owners[] = ['kind' => 'wp_page', 'owner_id' => (string) $page->ID, 'slug' => $slug];
            }
        }
        $types = function_exists('get_post_types') ? get_post_types(['publicly_queryable' => true], 'names') : ['post', 'page'];
        $posts = get_posts(['name' => $slug, 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 20, 'no_found_rows' => true]);
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) continue;
            $owners[] = ['kind' => $post->post_type === 'page' ? 'wp_page' : 'wp_post', 'owner_id' => (string) $post->ID, 'slug' => $slug];
        }
        return $owners;
    }
}
