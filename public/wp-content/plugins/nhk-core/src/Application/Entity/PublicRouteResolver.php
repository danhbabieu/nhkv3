<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
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
        'tri-thuc', 'so-sanh', 'bo-may', 'ban-nhac', 'linh-kien', 'phan-loai',
        'hien-vat', 'san-pham', 'video', 'goc-chia-se', 'thu-vien', 'media',
        'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed', 'search', 'sitemap',
    ];

    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types) {}

    public function types(): EntityTypeRegistry { return $this->types; }

    public function path(AuthorityEntity $entity): ?string
    {
        if (!$this->types->has($entity->entityType) || !$entity->active()) return null;
        $slug = self::slug($entity->canonicalName);
        if ($slug === '') return null;
        if ($entity->entityType === 'brand') return in_array($slug, self::RESERVED_ROOTS, true) ? null : '/' . $slug . '/';
        if ($entity->entityType === 'model') {
            $brand = $this->parent($entity, 'brand_uuid', 'brand');
            return $brand ? $this->path($brand) . $slug . '/' : null;
        }
        if ($entity->entityType === 'variant') {
            $model = $this->parent($entity, 'model_uuid', 'model');
            return $model ? $this->path($model) . $slug . '/' : null;
        }
        $namespace = self::NAMESPACES[$entity->entityType] ?? null;
        return $namespace === null ? null : '/' . $namespace . '/' . $slug . '/';
    }

    public function archivePath(string $type): ?string
    {
        if ($type === 'brand' || $type === 'model' || $type === 'variant') return null;
        $namespace = self::NAMESPACES[$type] ?? null;
        return $namespace === null ? null : '/' . $namespace . '/';
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
            return $brand ? $this->uniqueChild('model', $slugs[1], 'brand_uuid', $brand->canonicalId) : null;
        }
        if ($type === 'variant' && count($slugs) === 3) {
            $brand = $this->unique('brand', $slugs[0]);
            $model = $brand ? $this->uniqueChild('model', $slugs[1], 'brand_uuid', $brand->canonicalId) : null;
            return $model ? $this->uniqueChild('variant', $slugs[2], 'model_uuid', $model->canonicalId) : null;
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
        return trim($value, '-');
    }

    /** @return list<string> */
    public static function reservedRoots(): array { return self::RESERVED_ROOTS; }
    public static function namespaceFor(string $type): ?string { return self::NAMESPACES[$type] ?? null; }

    private function unique(string $type, string $slug): ?AuthorityEntity
    {
        $matches = array_values(array_filter($this->authority->listByType($type), static fn (AuthorityEntity $item): bool => $item->active() && self::slug($item->canonicalName) === $slug));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function uniqueChild(string $type, string $slug, string $field, string $parentId): ?AuthorityEntity
    {
        $matches = array_values(array_filter($this->authority->listByType($type), static fn (AuthorityEntity $item): bool => $item->active() && self::slug($item->canonicalName) === $slug && (string) ($item->payload[$field] ?? '') === $parentId));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function parent(AuthorityEntity $entity, string $field, string $type): ?AuthorityEntity
    {
        $id = (string) ($entity->payload[$field] ?? '');
        return UuidCodec::isValid($id) ? (($parent = $this->authority->findByCanonicalId($id)) && $parent->entityType === $type && $parent->active() ? $parent : null) : null;
    }
}
