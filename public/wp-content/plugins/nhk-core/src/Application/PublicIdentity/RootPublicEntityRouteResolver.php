<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Application\Entity\{EntityProfileResolver, EntityProfilePublicDossier};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\PublicIdentity\RootPublicIdentityReader;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only foundation for `/{slug}/` Entity resolution.
 *
 * A root path is claimable only when an existing Public Identity already
 * points to it. No display-name, slug, stable-key or family inference is
 * performed here, and no writer is accepted by this class.
 */
final class RootPublicEntityRouteResolver
{
    public function __construct(
        private RootPublicIdentityReader $identities,
        private AuthorityRepository $authority,
        private EntityProfileResolver $profiles,
        private EntityProfilePublicDossier $dossier,
        private RootRouteCollisionPolicy $collisions,
    ) {}

    /** @return array<string,mixed> */
    public function resolve(string $slug): array
    {
        if ($slug === '' || CanonicalPublicSlugPolicy::normalize($slug) !== $slug) {
            return $this->blocked('PUBLIC_SLUG_INVALID');
        }
        $path = '/' . $slug . '/';
        $records = $this->identities->findCurrentByRootSlug($slug);
        if ($records === []) {
            $collision = $this->collisions->evaluate($path);
            if (($collision['status'] ?? '') !== 'AVAILABLE') {
                return [
                    'status' => $collision['status'] ?? 'UNAVAILABLE_IMPLEMENTATION_GAP',
                    'reason' => $collision['reason'] ?? 'ROOT_ROUTE_UNAVAILABLE',
                    'path' => null,
                    'collision' => $collision,
                ];
            }
            return $this->blocked('MISSING_PUBLIC_IDENTITY');
        }

        $identity = $records[0] ?? null;
        if (!is_array($identity)) return $this->blocked('PUBLIC_IDENTITY_UNRESOLVED');
        if (count($records) !== 1) return $this->blocked('PUBLIC_SLUG_CONFLICT', ['identity_records' => $records]);
        if ((string) ($identity['current_path'] ?? '') !== $path) return $this->blocked('PUBLIC_IDENTITY_NOT_ROOT_CANONICAL');
        if ((string) ($identity['owner_kind'] ?? '') !== 'authority') return $this->blocked('PUBLIC_IDENTITY_OWNER_UNSUPPORTED');

        $ownerId = (string) ($identity['owner_id'] ?? '');
        if (!UuidCodec::isValid($ownerId)) return $this->blocked('PUBLIC_IDENTITY_OWNER_INVALID');
        $entity = $this->authority->findByCanonicalId($ownerId);
        if (!$entity instanceof AuthorityEntity || (string) ($identity['route_type'] ?? '') !== $entity->entityType) {
            return $this->blocked('PUBLIC_IDENTITY_OWNER_UNRESOLVED');
        }

        $profile = $this->profiles->resolveProfile($entity);
        if (!$profile->resolved()) return $this->blocked('PROFILE_UNRESOLVED', ['profile_resolution' => $profile->toArray()]);

        $collision = $this->collisions->evaluate($path, (string) ($identity['identity_id'] ?? ''));
        if (($collision['status'] ?? '') !== 'AVAILABLE') {
            return [
                'status' => $collision['status'] ?? 'UNAVAILABLE_IMPLEMENTATION_GAP',
                'reason' => $collision['reason'] ?? 'ROOT_ROUTE_UNAVAILABLE',
                'path' => null,
                'profile_resolution' => $profile->toArray(),
                'collision' => $collision,
            ];
        }

        $packet = $this->dossier->forEntity($entity, $path);
        if (($packet['status'] ?? '') !== 'AVAILABLE') {
            return [
                'status' => ($packet['reason'] ?? '') === 'ENTITY_NOT_PUBLIC' ? 'BLOCKED_PUBLIC_ELIGIBILITY' : 'UNAVAILABLE_IMPLEMENTATION_GAP',
                'reason' => (string) ($packet['reason'] ?? 'DOSSIER_UNAVAILABLE'),
                'path' => null,
                'profile_resolution' => $profile->toArray(),
                'dossier' => $packet,
            ];
        }

        return [
            'status' => 'AVAILABLE',
            'path' => $path,
            'public_identity' => [
                'identity_id' => (string) ($identity['identity_id'] ?? ''),
                'slug' => $slug,
                'path' => $path,
                'route_type' => (string) ($identity['route_type'] ?? ''),
            ],
            'entity' => $entity,
            'profile_resolution' => $profile->toArray(),
            'dossier' => $packet,
            'collision' => $collision,
        ];
    }

    /** @param array<string,mixed> $extra */
    private function blocked(string $reason, array $extra = []): array
    {
        return ['status' => 'BLOCKED_PUBLIC_ELIGIBILITY', 'reason' => $reason, 'path' => null, ...$extra];
    }
}
