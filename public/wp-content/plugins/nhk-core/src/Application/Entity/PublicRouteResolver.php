<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Graph\StructuralContextQuery;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicRouteResolver
{
    /** @var array<string,string> */
    private const NAMESPACES = [
        'movement' => 'bo-may', 'music' => 'ban-nhac', 'component' => 'linh-kien',
        'classification' => 'phan-loai', 'specimen' => 'hien-vat', 'product' => 'san-pham',
    ];

    /** @var list<string> */
    private const RESERVED_ROOTS = [
        'thuong-hieu', 'mau', 'tri-thuc', 'so-sanh', 'bo-may', 'ban-nhac', 'linh-kien', 'phan-loai',
        'hien-vat', 'san-pham', 'video', 'goc-chia-se', 'thu-vien', 'media',
        'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed', 'search', 'sitemap', 'category', 'tag', 'author', 'knowledge',
        'brand', 'model', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'comparison',
    ];

    /**
     * @param \Closure(string):bool|null $nativeRootExists Optional WordPress
     *        boundary probe. The application remains usable without WP in
     *        unit tests and non-HTTP consumers.
     */
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?StructuralContextQuery $contexts = null, private ?\Closure $nativeRootExists = null) {}

    public function types(): EntityTypeRegistry { return $this->types; }

    public function path(AuthorityEntity $entity): ?string
    {
        if (!$this->types->has($entity->entityType) || !$entity->active()) return null;
        $slug = self::slug($entity->canonicalName);
        if ($slug === '') return null;
        if ($entity->entityType === 'brand') return in_array($slug, self::RESERVED_ROOTS, true) || !$this->uniqueEntity($entity->entityType, $slug, $entity->canonicalId) || $this->nativeRootExists($slug) ? null : '/' . $slug . '/';
        if ($entity->entityType === 'model') {
            $context = $this->context($entity);
            $brand = $context === null ? $this->parent($entity, 'brand_uuid', 'brand') : ($context->brandId === null || $context->reasons !== [] ? null : $this->authority->findByCanonicalId($context->brandId));
            return $brand && $brand->entityType === 'brand' && $brand->active() && $this->uniqueChildAvailable($entity->entityType, $slug, $brand->canonicalId, $entity->canonicalId) ? $this->path($brand) . $slug . '/' : null;
        }
        if ($entity->entityType === 'variant') {
            $context = $this->context($entity);
            $model = $context === null ? $this->parent($entity, 'model_uuid', 'model') : ($context->modelId === null || $context->reasons !== [] ? null : $this->authority->findByCanonicalId($context->modelId));
            return $model && $model->entityType === 'model' && $model->active() && $this->uniqueChildAvailable($entity->entityType, $slug, $model->canonicalId, $entity->canonicalId) ? $this->path($model) . $slug . '/' : null;
        }
        $namespace = self::NAMESPACES[$entity->entityType] ?? null;
        return $namespace === null || !$this->uniqueEntity($entity->entityType, $slug, $entity->canonicalId) ? null : '/' . $namespace . '/' . $slug . '/';
    }

    public function archivePath(string $type): ?string
    {
        if ($type === 'brand') return '/thuong-hieu/';
        if ($type === 'model') return '/mau/';
        if ($type === 'variant') return null;
        $namespace = self::NAMESPACES[$type] ?? null;
        return $namespace === null ? null : '/' . $namespace . '/';
    }

    public static function existingSemanticPath(string $type, string $id): ?string
    {
        if (!UuidCodec::isValid($id)) return null;
        return match ($type) {
            'media', 'knowledge', 'video' => null,
            default => null,
        };
    }

    public static function videoPath(string $title, string $externalId): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $externalId)) return null;
        $slug = self::slug($title);
        return '/video/' . ($slug !== '' ? $slug . '-' . strtolower($externalId) : 'video-' . strtolower($externalId)) . '/';
    }

    /** @param list<string> $segments */
    public function resolve(string $type, array $segments): ?AuthorityEntity
    {
        if (!$this->types->has($type) || $segments === []) return null;
        $slugs = array_values(array_filter(array_map(static fn (mixed $value): string => self::slug((string) $value), $segments), static fn (string $value): bool => $value !== ''));
        if (count($slugs) !== count($segments)) return null;
        if ($type === 'brand' && count($slugs) === 1) return $this->unique($type, $slugs[0]);
        if ($type === 'model' && count($slugs) === 2) {
            $brand = $this->unique('brand', $slugs[0]);
            return $brand ? $this->uniqueChild('model', $slugs[1], $brand->canonicalId) : null;
        }
        if ($type === 'variant' && count($slugs) === 3) {
            $brand = $this->unique('brand', $slugs[0]);
            $model = $brand ? $this->uniqueChild('model', $slugs[1], $brand->canonicalId) : null;
            return $model ? $this->uniqueChild('variant', $slugs[2], $model->canonicalId) : null;
        }
        if (isset(self::NAMESPACES[$type], $slugs[1]) && count($slugs) === 2 && $slugs[0] === self::NAMESPACES[$type]) return $this->unique($type, $slugs[1]);
        return null;
    }

    public static function slug(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, ['Đ' => 'D', 'đ' => 'd', 'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A', 'Ă' => 'A', 'Ắ' => 'A', 'Ằ' => 'A', 'Ặ' => 'A', 'Â' => 'A', 'Ấ' => 'A', 'Ầ' => 'A', 'Ậ' => 'A', 'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a', 'ă' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ặ' => 'a', 'â' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ậ' => 'a', 'È' => 'E', 'É' => 'E', 'Ẹ' => 'E', 'Ê' => 'E', 'Ế' => 'E', 'Ề' => 'E', 'Ệ' => 'E', 'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ê' => 'e', 'ế' => 'e', 'ề' => 'e', 'ệ' => 'e', 'Ì' => 'I', 'Í' => 'I', 'Ị' => 'I', 'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'Ò' => 'O', 'Ó' => 'O', 'Ọ' => 'O', 'Ô' => 'O', 'Ố' => 'O', 'Ồ' => 'O', 'Ộ' => 'O', 'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ô' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ộ' => 'o', 'Ù' => 'U', 'Ú' => 'U', 'Ụ' => 'U', 'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỵ' => 'Y', 'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y']);
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        return (string) preg_replace('/(^|-)o-do(?=-|$)/', '$1odo', $value);
    }

    /** @return list<string> */
    public static function reservedRoots(): array { return self::RESERVED_ROOTS; }
    public static function namespaceFor(string $type): ?string { return self::NAMESPACES[$type] ?? null; }

    private function nativeRootExists(string $slug): bool
    {
        if ($this->nativeRootExists !== null) return (bool) ($this->nativeRootExists)($slug);
        if (!function_exists('get_posts')) return false;
        if (function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page instanceof \WP_Post && function_exists('is_post_publicly_viewable') && is_post_publicly_viewable($page)) return true;
        }
        foreach (get_post_types(['publicly_queryable' => true], 'names') as $postType) {
            if ($postType === 'page') continue;
            $posts = get_posts(['name' => $slug, 'post_type' => $postType, 'post_status' => 'publish', 'numberposts' => 1, 'no_found_rows' => true, 'fields' => 'ids']);
            if ($posts !== []) return true;
        }
        return false;
    }

    private function unique(string $type, string $slug): ?AuthorityEntity
    {
        $matches = array_values(array_filter($this->authority->listByType($type), static fn (AuthorityEntity $item): bool => $item->active() && self::slug($item->canonicalName) === $slug));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function uniqueChild(string $type, string $slug, string $parentId, ?string $excludeId = null): ?AuthorityEntity
    {
        $matches = array_values(array_filter($this->authority->listByType($type), fn (AuthorityEntity $item): bool => $item->active() && self::slug($item->canonicalName) === $slug && $this->parentForRoute($item) === $parentId && ($excludeId === null || $item->canonicalId !== $excludeId)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function uniqueEntity(string $type, string $slug, string $id): bool
    {
        foreach ($this->authority->listByType($type) as $item) {
            if ($item->active() && $item->canonicalId !== $id && self::slug($item->canonicalName) === $slug) return false;
        }
        return true;
    }

    private function uniqueChildAvailable(string $type, string $slug, string $parentId, string $id): bool
    {
        foreach ($this->authority->listByType($type) as $item) {
            if ($item->active() && $item->canonicalId !== $id && self::slug($item->canonicalName) === $slug && $this->parentForRoute($item) === $parentId) return false;
        }
        return true;
    }

    private function parent(AuthorityEntity $entity, string $field, string $type): ?AuthorityEntity
    {
        $id = (string) ($entity->payload[$field] ?? '');
        return UuidCodec::isValid($id) ? (($parent = $this->authority->findByCanonicalId($id)) && $parent->entityType === $type && $parent->active() ? $parent : null) : null;
    }

    private function context(AuthorityEntity $entity): ?\NHK\Core\Application\Graph\StructuralContext
    {
        if ($this->contexts === null) return null;
        return $entity->entityType === 'model' ? $this->contexts->forModel($entity->canonicalId) : $this->contexts->forVariant($entity->canonicalId);
    }

    private function parentForRoute(AuthorityEntity $entity): ?string
    {
        $context = $this->context($entity);
        if ($context !== null) return $entity->entityType === 'model' ? $context->brandId : $context->modelId;
        return (string) ($entity->payload[$entity->entityType === 'model' ? 'brand_uuid' : 'model_uuid'] ?? '') ?: null;
    }
}
