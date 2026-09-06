<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\PublicIdentity;

use NHK\Core\Application\Graph\StructuralContextQuery;
use NHK\Core\Application\Media\PublicMediaAssetUrlResolver;
use NHK\Core\Application\PublicIdentity\{PublicIdentityService, PublicUrlMaintenanceService};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Media\MediaAsset;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Migration\PublicIdentityMigration014;

/**
 * WordPress composition for the pre-public URL cutover.
 *
 * This is deliberately a Public Identity / native permalink maintenance
 * boundary. It never rekeys Authority/Video/Media identities and never writes
 * Graph, Knowledge, Evidence or Source state.
 */
final class WordPressPublicUrlMaintenanceRuntime
{
    private PublicIdentityService $identityService;

    public function __construct(
        private object $wpdb,
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private StructuralContextQuery $contexts,
        private VideoRepository $videos,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private PublicIdentityRepository $identities,
    ) {
        // Collision with native root routes is handled with owner-aware checks
        // in this runtime. Namespaced semantic routes must not be blocked just
        // because the same token exists as a WordPress post slug.
        $this->identityService = new PublicIdentityService($identities, static fn (string $slug): bool => false);
    }

    public function service(): PublicUrlMaintenanceService
    {
        return new PublicUrlMaintenanceService(
            fn (): array => $this->inventory(),
            fn (array $item, string $candidate): bool => $this->externallyOccupied($item, $candidate),
            function (array $item, string $idempotencyKey): void { $this->apply($item, $idempotencyKey); },
        );
    }

    /** @return list<array<string,mixed>> */
    private function inventory(): array
    {
        if (!PublicIdentityMigration014::schemaReady($this->wpdb)) throw new \RuntimeException('PUBLIC_IDENTITY_STORAGE_UNAVAILABLE');
        $items = [];

        foreach ($this->types->all() as $definition) {
            foreach ($this->authority->listByType($definition->type) as $entity) {
                if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
                $scope = $this->authorityScope($entity);
                $current = $this->identities->findCurrentByOwner('authority', $entity->canonicalId, $entity->entityType);
                $items[] = [
                    'kind' => 'authority',
                    'owner_id' => $entity->canonicalId,
                    'route_type' => $entity->entityType,
                    'scope' => $scope,
                    'name' => $entity->canonicalName,
                    'current_slug' => is_array($current) ? (string) ($current['current_slug'] ?? '') : '',
                    'current_path' => is_array($current) ? (string) ($current['current_path'] ?? '') : '',
                    'identity_id' => is_array($current) ? (string) ($current['identity_id'] ?? '') : '',
                    'revision' => is_array($current) ? (int) ($current['revision'] ?? 0) : 0,
                    'qualifiers' => $this->meaningfulAuthorityQualifiers($entity),
                ];
            }
        }

        foreach ($this->videos->list() as $video) {
            if (!$video instanceof Video || !$video->active || !$video->hasValidPublicReference()) continue;
            $current = $this->identities->findCurrentByOwner('video', $video->canonicalId, 'video');
            $metadata = is_array($video->metadata) ? $video->metadata : [];
            $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
            $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title;
            $items[] = [
                'kind' => 'video',
                'owner_id' => $video->canonicalId,
                'route_type' => 'video',
                'scope' => 'root',
                'name' => $title,
                'current_slug' => is_array($current) ? (string) ($current['current_slug'] ?? '') : '',
                'current_path' => is_array($current) ? (string) ($current['current_path'] ?? '') : '',
                'identity_id' => is_array($current) ? (string) ($current['identity_id'] ?? '') : '',
                'revision' => is_array($current) ? (int) ($current['revision'] ?? 0) : 0,
                'qualifiers' => $this->meaningfulVideoQualifiers($metadata),
            ];
        }

        if (function_exists('get_posts')) {
            foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $post) {
                if (!$post instanceof \WP_Post) continue;
                $items[] = [
                    'kind' => 'wp_post',
                    'owner_id' => (string) $post->ID,
                    'route_type' => $post->post_type === 'page' ? 'wp_page' : 'wp_post',
                    'scope' => 'root',
                    'name' => (string) $post->post_title,
                    'current_slug' => (string) $post->post_name,
                    'current_path' => function_exists('get_permalink') ? (string) parse_url((string) get_permalink($post), PHP_URL_PATH) : '',
                    'qualifiers' => $this->postQualifiers($post),
                ];
            }
        }

        if (function_exists('get_terms')) {
            foreach (['category' => 'wp_category', 'post_tag' => 'wp_tag'] as $taxonomy => $routeType) {
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (is_wp_error($terms) || !is_array($terms)) continue;
                foreach ($terms as $term) {
                    if (!$term instanceof \WP_Term) continue;
                    $items[] = [
                        'kind' => 'wp_term',
                        'owner_id' => (string) $term->term_id,
                        'taxonomy' => $taxonomy,
                        'route_type' => $routeType,
                        'scope' => $taxonomy,
                        'name' => (string) $term->name,
                        'current_slug' => (string) $term->slug,
                        'current_path' => '',
                        'qualifiers' => [],
                    ];
                }
            }
        }

        $urlResolver = new PublicMediaAssetUrlResolver();
        foreach ($this->media->list() as $media) {
            if (!$media->active || $media->readiness !== 'ready') continue;
            foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
                if (!$asset instanceof MediaAsset || $asset->visibility !== 'PUBLIC' || !str_starts_with(strtolower($asset->mimeType), 'image/')) continue;
                $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== ''
                    ? (string) $asset->metadata['canonical_filename']
                    : basename(str_replace('\\', '/', $asset->storageKey));
                $stem = pathinfo($filename, PATHINFO_FILENAME);
                $items[] = [
                    'kind' => 'media_asset',
                    'owner_id' => $asset->assetId,
                    'route_type' => 'media_image',
                    'scope' => 'root',
                    'name' => $media->canonicalName,
                    'current_slug' => $stem,
                    'current_path' => $urlResolver->path($filename),
                    'qualifiers' => array_values(array_filter([
                        $asset->kind,
                        $asset->width !== null && $asset->height !== null ? $asset->width . 'x' . $asset->height : '',
                    ])),
                ];
            }
        }

        return $items;
    }

    private function authorityScope(AuthorityEntity $entity): string
    {
        if ($entity->entityType === 'brand') return 'root';
        if ($entity->entityType === 'model') {
            $context = $this->contexts->forModel($entity->canonicalId);
            return $context->reasons === [] && $context->brandId !== null ? 'brand:' . $context->brandId : '';
        }
        if ($entity->entityType === 'variant') {
            $context = $this->contexts->forVariant($entity->canonicalId);
            return $context->reasons === [] && $context->modelId !== null ? 'model:' . $context->modelId : '';
        }
        return 'root';
    }

    /** @return list<string> */
    private function meaningfulAuthorityQualifiers(AuthorityEntity $entity): array
    {
        $values = [];
        foreach (['year', 'reference', 'model_reference', 'configuration', 'caliber'] as $key) {
            $value = $entity->payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') $values[] = trim((string) $value);
        }
        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function meaningfulVideoQualifiers(array $metadata): array
    {
        $values = [];
        $hub = is_array($metadata['hub'] ?? null) ? $metadata['hub'] : [];
        $primary = $hub['primary'] ?? null;
        if (is_array($primary)) $primary = $primary['label'] ?? $primary['key'] ?? null;
        if (is_scalar($primary) && trim((string) $primary) !== '') $values[] = trim((string) $primary);
        return $values;
    }

    /** @return list<string> */
    private function postQualifiers(\WP_Post $post): array
    {
        $year = substr((string) $post->post_date, 0, 4);
        return preg_match('/^[0-9]{4}$/', $year) === 1 ? [$year] : [];
    }

    private function externallyOccupied(array $item, string $candidate): bool
    {
        $kind = (string) ($item['kind'] ?? '');
        $routeType = (string) ($item['route_type'] ?? '');
        $ownerId = (string) ($item['owner_id'] ?? '');

        if ($kind === 'authority' && $routeType === 'brand') return $this->nativeRootOccupied($candidate, null);
        if (in_array($kind, ['wp_post'], true)) {
            if ($this->nativeRootOccupied($candidate, ctype_digit($ownerId) ? (int) $ownerId : null)) return true;
            foreach ($this->authority->listByType('brand') as $brand) {
                if (!$brand->active()) continue;
                $identity = $this->identities->findCurrentByOwner('authority', $brand->canonicalId, 'brand');
                if (is_array($identity) && (string) ($identity['current_slug'] ?? '') === $candidate) return true;
            }
        }
        return false;
    }

    private function nativeRootOccupied(string $slug, ?int $excludePostId): bool
    {
        if (!function_exists('get_posts')) return false;
        $types = function_exists('get_post_types') ? get_post_types(['publicly_queryable' => true], 'names') : ['post', 'page'];
        $posts = get_posts(['name' => $slug, 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 10, 'no_found_rows' => true]);
        foreach ($posts as $post) if ($post instanceof \WP_Post && ($excludePostId === null || (int) $post->ID !== $excludePostId)) return true;
        if (function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page instanceof \WP_Post && ($excludePostId === null || (int) $page->ID !== $excludePostId)) return true;
        }
        return false;
    }

    private function apply(array $item, string $idempotencyKey): void
    {
        $desired = trim((string) ($item['desired_slug'] ?? ''));
        if ($desired === '') throw new \RuntimeException('PUBLIC_SLUG_INVALID');
        $kind = (string) ($item['kind'] ?? '');

        if (in_array($kind, ['authority', 'video'], true)) {
            $ownerId = (string) ($item['owner_id'] ?? '');
            $routeType = (string) ($item['route_type'] ?? '');
            $scope = (string) ($item['scope'] ?? '');
            if ($kind === 'authority' && $routeType === 'brand' && $this->nativeRootOccupied($desired, null)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
            $identityId = trim((string) ($item['identity_id'] ?? ''));
            if ($identityId === '') {
                $this->identityService->allocate($kind === 'video' ? 'video' : 'authority', $ownerId, $routeType, $scope, $desired, $idempotencyKey);
            } else {
                $this->identityService->reproject($identityId, $desired, $scope, (int) ($item['revision'] ?? 0), $idempotencyKey);
            }
            return;
        }

        if ($kind === 'wp_post') {
            if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_EDITORIAL_WRITER_UNAVAILABLE');
            $postId = (int) ($item['owner_id'] ?? 0);
            if ($this->nativeRootOccupied($desired, $postId)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
            $result = wp_update_post(['ID' => $postId, 'post_name' => $desired], true);
            if (is_wp_error($result)) throw new \RuntimeException('WORDPRESS_SLUG_UPDATE_FAILED');
            return;
        }

        if ($kind === 'wp_term') {
            if (!function_exists('wp_update_term')) throw new \RuntimeException('WORDPRESS_TAXONOMY_WRITER_UNAVAILABLE');
            $result = wp_update_term((int) ($item['owner_id'] ?? 0), (string) ($item['taxonomy'] ?? ''), ['slug' => $desired]);
            if (is_wp_error($result)) throw new \RuntimeException('WORDPRESS_SLUG_UPDATE_FAILED');
            return;
        }

        if ($kind === 'media_asset') {
            $asset = $this->assets->findByAssetId((string) ($item['owner_id'] ?? ''));
            if (!$asset instanceof MediaAsset) throw new \RuntimeException('MEDIA_ASSET_NOT_FOUND');
            $metadata = $asset->metadata;
            $metadata['canonical_filename'] = $desired . '.webp';
            $updated = new MediaAsset($asset->assetId, $asset->mediaId, $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize, $asset->width, $asset->height, $asset->visibility, $metadata);
            $this->assets->update($updated);
            return;
        }

        throw new \RuntimeException('PUBLIC_URL_OWNER_UNSUPPORTED');
    }
}
