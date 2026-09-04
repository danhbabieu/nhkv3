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
        $slug = $this->normalizeSlug($slug);
        if ($slug === '' || ($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        return $this->repository->allocate($this->record($ownerKind, $ownerId, $routeType, $scope, $slug), $idempotencyKey);
    }

    public function changeSlug(string $identityId, string $slug, int $expectedRevision, string $idempotencyKey): array
    {
        if ($identityId === '' || $expectedRevision < 1 || $idempotencyKey === '') throw new \InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $current = $this->repository->findCurrentById($identityId);
        if ($current === null) throw new \RuntimeException('NOT_FOUND');
        if ((int) ($current['revision'] ?? 0) !== $expectedRevision) throw new \RuntimeException('STALE_REVISION');
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') throw new \InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (($this->nativeRouteExists)($slug)) throw new \RuntimeException('NATIVE_ROUTE_CONFLICT');
        $record = $current;
        $record['current_slug'] = $slug;
        $record['current_path'] = $this->path((string) $current['route_type'], $slug);
        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
    }

    private function record(string $ownerKind, string $ownerId, string $routeType, string $scope, string $slug): array
    {
        return ['owner_kind' => $ownerKind, 'owner_id' => $ownerId, 'route_type' => $routeType, 'collision_scope' => $scope, 'current_slug' => $slug, 'current_path' => $this->path($routeType, $slug), 'route_policy_version' => '1'];
    }

    private function path(string $routeType, string $slug): string
    {
        $prefix = match ($routeType) { 'video' => '/video/', 'movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/', default => '/' };
        return $prefix . $slug . '/';
    }

    private function normalizeSlug(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, ['Đ'=>'D','đ'=>'d','Ô'=>'O','ô'=>'o','Ơ'=>'O','ơ'=>'o','Ư'=>'U','ư'=>'u','Â'=>'A','â'=>'a','Ă'=>'A','ă'=>'a','Ê'=>'E','ê'=>'e','Á'=>'A','À'=>'A','Ả'=>'A','Ã'=>'A','Ạ'=>'A','á'=>'a','à'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','É'=>'E','È'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ẹ'=>'E','é'=>'e','è'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','Í'=>'I','Ì'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ị'=>'I','í'=>'i','ì'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i','Ó'=>'O','Ò'=>'O','Ỏ'=>'O','Õ'=>'O','Ọ'=>'O','ó'=>'o','ò'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','Ú'=>'U','Ù'=>'U','Ủ'=>'U','Ũ'=>'U','Ụ'=>'U','ú'=>'u','ù'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','Ý'=>'Y','Ỳ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Ỵ'=>'Y','ý'=>'y','ỳ'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y']);
        $value = strtolower($value);
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }
}
