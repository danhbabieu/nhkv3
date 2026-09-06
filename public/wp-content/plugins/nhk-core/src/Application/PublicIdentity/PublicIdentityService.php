<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicIdentityService
{
    private CanonicalPublicSlugPolicy $slugs;

    public function __construct(private object $repository, private \Closure $nativeRouteExists, ?CanonicalPublicSlugPolicy $slugs = null)
    {
        $this->slugs = $slugs ?? new CanonicalPublicSlugPolicy();
    }

    public function allocate(string $ownerKind, string $ownerId, string $routeType, string $scope, string $slug, string $idempotencyKey): array
    {
        if ($ownerKind === '' || !UuidCodec::isValid($ownerId) || $routeType === '' || $scope === '' || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $slug = $this->slugs->slug($slug);
        if ($slug === '') throw new \InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        return $this->repository->allocate($this->record($ownerKind, $ownerId, $routeType, $scope, $slug), $idempotencyKey);
    }

    /**
     * Allocate the shortest meaningful canonical slug. Qualifiers are tried
     * only after a real collision; no UUID, external ID, hash or timestamp is
     * invented by this boundary.
     *
     * @param list<string> $meaningfulQualifiers
     */
    public function allocateCanonical(string $ownerKind, string $ownerId, string $routeType, string $scope, string $publicName, array $meaningfulQualifiers, string $idempotencyKey): array
    {
        if ($ownerKind === '' || !UuidCodec::isValid($ownerId) || $routeType === '' || $scope === '' || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $slug = $this->slugs->resolve($publicName, $meaningfulQualifiers, function(string $candidate) use ($routeType, $scope): bool {
            if (($this->nativeRouteExists)($candidate)) return true;
            return method_exists($this->repository, 'slugExists') && (bool) $this->repository->slugExists($routeType, $scope, $candidate, null);
        });
        return $this->repository->allocate($this->record($ownerKind, $ownerId, $routeType, $scope, $slug), $idempotencyKey);
    }

    public function changeSlug(string $identityId, string $slug, int $expectedRevision, string $idempotencyKey): array
    {
        $current = $this->currentForChange($identityId, $expectedRevision, $idempotencyKey);
        $slug = $this->validatedSlug($slug);
        $record = $current;
        $record['current_slug'] = $slug;
        $record['route_policy_version'] = '2';
        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
    }

    /**
     * Pre-public maintenance operation for canonical Public Identity only.
     * Semantic owner UUID/stable identity is preserved; this changes only the
     * URL projection (slug + collision scope) under revision/idempotency gates.
     */
    public function reproject(string $identityId, string $publicName, string $scope, int $expectedRevision, string $idempotencyKey): array
    {
        if (trim($scope) === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $current = $this->currentForChange($identityId, $expectedRevision, $idempotencyKey);
        $record = $current;
        $record['current_slug'] = $this->validatedSlug($publicName);
        $record['collision_scope'] = trim($scope);
        $record['route_policy_version'] = '2';
        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    private function currentForChange(string $identityId, int $expectedRevision, string $idempotencyKey): array
    {
        if ($identityId === '' || $expectedRevision < 1 || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $current = $this->repository->findCurrentById($identityId);
        if ($current === null) throw new \RuntimeException('NOT_FOUND');
        if ((int) ($current['revision'] ?? 0) !== $expectedRevision) throw new \RuntimeException('STALE_REVISION');
        return $current;
    }

    private function validatedSlug(string $value): string
    {
        $slug = $this->slugs->slug($value);
        if ($slug === '') throw new \InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        return $slug;
    }

    private function record(string $ownerKind, string $ownerId, string $routeType, string $scope, string $slug): array
    {
        return ['owner_kind' => $ownerKind, 'owner_id' => $ownerId, 'route_type' => $routeType, 'collision_scope' => $scope, 'current_slug' => $slug, 'current_path' => $this->path($routeType, $slug), 'route_policy_version' => '2'];
    }

    private function path(string $routeType, string $slug): string
    {
        $prefix = match ($routeType) { 'video' => '/video/', 'movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/', default => '/' };
        return $prefix . $slug . '/';
    }
}
