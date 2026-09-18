<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\StructuralContext;
use NHK\Core\Application\Graph\StructuralContextQuery;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\PublicIdentity\{PublicIdentity, PublicUrlResult};
use NHK\Core\Shared\Uuid\UuidCodec;

final class AuthorityUrlPolicy
{
    private const NAMESPACES = ['movement' => 'bo-may', 'music' => 'ban-nhac', 'component' => 'linh-kien', 'classification' => 'phan-loai', 'specimen' => 'hien-vat', 'product' => 'san-pham'];
    private const RESERVED_ROOTS = ['thuong-hieu', 'mau', 'tri-thuc', 'so-sanh', 'bo-may', 'ban-nhac', 'linh-kien', 'phan-loai', 'hien-vat', 'san-pham', 'video', 'goc-chia-se', 'thu-vien', 'media', 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed', 'search', 'sitemap', 'category', 'tag', 'author', 'knowledge', 'brand', 'model', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'comparison'];

    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private PublicIdentityRepository $identities, private ?StructuralContextQuery $contexts = null, private ?\Closure $nativeRootExists = null) {}

    public function project(AuthorityEntity $entity, PublicIdentity $identity, ?StructuralContext $structuralContext = null): PublicUrlResult
    {
        if (!$this->types->has($entity->entityType) || !$entity->active()) return $this->blocked('INACTIVE');
        if ($identity->ownerKind !== 'authority' || $identity->ownerId !== $entity->canonicalId || $identity->routeType !== $entity->entityType) return $this->blocked('IDENTITY_MISMATCH');
        if ($this->identities->findByRoute($identity->routeType, $identity->collisionScope, $identity->currentSlug)?->identityId !== $identity->identityId) return $this->blocked('IDENTITY_COLLISION');

        $type = $entity->entityType;
        if ($type === 'brand') {
            if ($identity->collisionScope !== 'root' || in_array($identity->currentSlug, self::RESERVED_ROOTS, true) || $this->nativeRootExists($identity->currentSlug)) return $this->blocked('ROOT_COLLISION');
            return $this->eligible('/' . $identity->currentSlug . '/', $identity->revision);
        }
        if (in_array($type, ['model', 'variant'], true)) {
            $context = $structuralContext ?? $this->context($entity);
            if ($context !== null && $context->reasons !== []) return $this->blocked(...$context->reasons);
            $parentType = $type === 'model' ? 'brand' : 'model';
            $parentId = $type === 'model' ? ($context?->brandId ?? $entity->payload['brand_uuid'] ?? null) : ($context?->modelId ?? $entity->payload['model_uuid'] ?? null);
            if (!is_string($parentId) || !UuidCodec::isValid($parentId)) return $this->blocked('STRUCTURAL_PARENT_MISSING');
            $parent = $this->authority->findByCanonicalId($parentId);
            if (!$parent || $parent->entityType !== $parentType || !$parent->active()) return $this->blocked('STRUCTURAL_PARENT_MISSING');
            $parentIdentity = $this->identities->findByOwner('authority', $parent->canonicalId);
            if (!$parentIdentity) return $this->blocked('STRUCTURAL_PARENT_MISSING');
            $parentResult = $this->project($parent, $parentIdentity);
            if (!$parentResult->eligible || $parentResult->finalPath === null) return $this->blocked(...($parentResult->blockers ?: ['STRUCTURAL_PARENT_MISSING']));
            if ($identity->collisionScope !== $parentType . ':' . $parent->canonicalId) return $this->blocked('IDENTITY_SCOPE_MISMATCH');
            return $this->eligible(rtrim($parentResult->finalPath, '/') . '/' . $identity->currentSlug . '/', $identity->revision);
        }
        $namespace = self::NAMESPACES[$type] ?? null;
        if ($namespace === null || $identity->collisionScope !== 'namespace:' . $type) return $this->blocked('NO_PUBLIC_ROUTE');
        return $this->eligible('/' . $namespace . '/' . $identity->currentSlug . '/', $identity->revision);
    }

    public static function namespaces(): array { return self::NAMESPACES; }
    public static function reservedRoots(): array { return self::RESERVED_ROOTS; }
    private function context(AuthorityEntity $entity): ?StructuralContext { return $this->contexts === null ? null : ($entity->entityType === 'model' ? $this->contexts->forModel($entity->canonicalId) : $this->contexts->forVariant($entity->canonicalId)); }
    private function nativeRootExists(string $slug): bool { return $this->nativeRootExists !== null && (bool) ($this->nativeRootExists)($slug); }
    private function eligible(string $path, int $revision): PublicUrlResult { return new PublicUrlResult($path, true, [], [], $revision); }
    private function blocked(string ...$reasons): PublicUrlResult { return new PublicUrlResult(null, false, array_values(array_unique($reasons))); }
}
