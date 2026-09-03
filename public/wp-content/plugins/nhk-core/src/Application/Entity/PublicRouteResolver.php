<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\PublicIdentity\{PublicRoutePolicyRegistry, PublicUrlProjector};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\PublicIdentity\PublicUrlResult;
use NHK\Core\Shared\Text\VietnameseSlugNormalizer;

final class PublicRouteResolver
{
    private PublicUrlProjector $projector;
    private PublicIdentityRepository $identities;

    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?\NHK\Core\Application\Graph\StructuralContextQuery $contexts = null, private ?\Closure $nativeRootExists = null, ?PublicIdentityRepository $identities = null)
    {
        $identities ??= new class implements PublicIdentityRepository {
            public function findByOwner(string $ownerKind, string $ownerId): ?\NHK\Core\Domain\PublicIdentity\PublicIdentity { return null; }
            public function findByRoute(string $routeType, string $collisionScope, string $slug): ?\NHK\Core\Domain\PublicIdentity\PublicIdentity { return null; }
            public function create(\NHK\Core\Domain\PublicIdentity\PublicIdentity $identity): \NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult { throw new \RuntimeException('Read-only.'); }
            public function update(\NHK\Core\Domain\PublicIdentity\PublicIdentity $identity, int $expectedRevision): \NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult { throw new \RuntimeException('Read-only.'); }
            public function appendHistoricRoute(\NHK\Core\Domain\PublicIdentity\HistoricPublicRoute $historicRoute): \NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult { throw new \RuntimeException('Read-only.'); }
        };
        $this->identities = $identities;
        $policy = new AuthorityUrlPolicy($authority, $types, $identities, $contexts, $nativeRootExists);
        $this->projector = new PublicUrlProjector(new PublicRoutePolicyRegistry($types, $policy), $identities);
    }

    public function types(): EntityTypeRegistry { return $this->types; }
    public function result(AuthorityEntity $entity): PublicUrlResult { return $this->projector->project($entity); }
    public function path(AuthorityEntity $entity): ?string { $result = $this->result($entity); return $result->eligible ? $result->finalPath : null; }
    public function archivePath(string $type): ?string
    {
        if (!$this->types->has($type)) return null;
        return match ($type) { 'brand' => '/thuong-hieu/', 'model' => '/mau/', 'variant' => null, default => isset(AuthorityUrlPolicy::namespaces()[$type]) ? '/' . AuthorityUrlPolicy::namespaces()[$type] . '/' : null };
    }
    public static function existingSemanticPath(string $type, string $id): ?string { return null; }
    public static function videoPath(string $title, string $externalId): ?string { if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $externalId)) return null; $slug = self::slug($title); return '/video/' . ($slug !== '' ? $slug . '-' . strtolower($externalId) : 'video-' . strtolower($externalId)) . '/'; }
    public static function slug(string $value): string { $result = (new VietnameseSlugNormalizer(191))->normalize($value); return str_replace('o-do', 'odo', $result->value()); }
    public static function reservedRoots(): array { return AuthorityUrlPolicy::reservedRoots(); }
    public static function namespaceFor(string $type): ?string { return AuthorityUrlPolicy::namespaces()[$type] ?? null; }

    /** @param list<string> $segments */
    public function resolve(string $type, array $segments): ?AuthorityEntity
    {
        if (!$this->types->has($type) || $segments === []) return null;
        $matches = [];
        foreach ($this->authority->listByType($type, true) as $entity) {
            $path = $this->identityPath($entity);
            if ($path !== null && explode('/', trim($path, '/')) === array_values($segments)) $matches[] = $entity;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function identityPath(AuthorityEntity $entity): ?string
    {
        $identity = $this->identities->findByOwner('authority', $entity->canonicalId);
        if ($identity === null) return null;
        if ($entity->entityType === 'brand') return '/' . $identity->currentSlug . '/';
        if (in_array($entity->entityType, ['model', 'variant'], true)) {
            $field = $entity->entityType === 'model' ? 'brand_uuid' : 'model_uuid';
            $parentId = $entity->payload[$field] ?? null;
            $parent = is_string($parentId) ? $this->authority->findByCanonicalId($parentId) : null;
            return $parent === null ? null : rtrim($this->identityPath($parent) ?? '', '/') . '/' . $identity->currentSlug . '/';
        }
        $namespace = self::namespaceFor($entity->entityType);
        return $namespace === null ? null : '/' . $namespace . '/' . $identity->currentSlug . '/';
    }
}
