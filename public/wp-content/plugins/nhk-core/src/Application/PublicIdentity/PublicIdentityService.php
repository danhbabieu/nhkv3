<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicIdentityService
{
    public function __construct(private object $repository, private \Closure $nativeRouteExists) {}

    public function allocate(string $ownerKind, string $ownerId, string $routeType, string $scope, string $slug, string $idempotencyKey): array
    {
        if ($ownerKind === '' || !UuidCodec::isValid($ownerId) || $routeType === '' || $scope === '' || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $slug = CanonicalPublicSlugPolicy::normalize($slug);
        if ($slug === '' || ($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        return $this->repository->allocate($this->record($ownerKind, $ownerId, $routeType, $scope, $slug), $idempotencyKey);
    }

    public function changeSlug(string $identityId, string $slug, int $expectedRevision, string $idempotencyKey): array
    {
        if ($identityId === '' || $expectedRevision < 1 || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $current = $this->repository->findCurrentById($identityId);
        if ($current === null) throw new \RuntimeException('NOT_FOUND');
        if ((int) ($current['revision'] ?? 0) !== $expectedRevision) throw new \RuntimeException('STALE_REVISION');
        $slug = CanonicalPublicSlugPolicy::normalize($slug);
        if ($slug === '') throw new \InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        $record = $current;
        $record['current_slug'] = $slug;
        $record['current_path'] = $this->path((string) $current['route_type'], $slug);
        $record['route_policy_version'] = '2';
        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
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
