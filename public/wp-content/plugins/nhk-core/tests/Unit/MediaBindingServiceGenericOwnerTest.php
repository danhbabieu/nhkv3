<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaBindingService, MediaOwnerCapability, MediaOwnerCapabilityRegistry};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaBindingOperationRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaBindingOperation, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaBindingServiceGenericOwnerTest extends TestCase
{
    public function test_registered_owner_accepts_supported_non_representative_role_and_preserves_it(): void
    {
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $media = new GenericMediaRepository(new Media($mediaId, 'nhk:media:test', 'Test image', 'ready', []));
        $assets = new GenericAssetRepository();
        $usages = new GenericUsageRepository();
        $authority = new GenericAuthorityRepository(new AuthorityEntity($targetId, 'classification', 'nhk:classification:test', 'Test classification', 1, []));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('classification', 1, true));
        $capabilities = new MediaOwnerCapabilityRegistry();
        $capabilities->register(MediaOwnerCapability::forEndpoint('classification', [MediaUsageRoleRegistry::TECHNICAL_DETAIL]));

        $service = new MediaBindingService($media, $assets, $usages, $authority, $types, new GenericOperationRepository(), capabilities: $capabilities);
        $result = $service->bind([
            'idempotency_key' => 'generic-technical-detail',
            'media' => ['id' => $mediaId],
            'target' => ['type' => 'classification', 'id' => $targetId],
            'role' => MediaUsageRoleRegistry::TECHNICAL_DETAIL,
            'selection_source' => 'USER_EXPLICIT',
            'selection_policy' => 'PINNED',
        ]);

        self::assertSame('COMPLETE', $result['status']);
        self::assertSame(MediaUsageRoleRegistry::TECHNICAL_DETAIL, $result['readback']['role']);
        self::assertSame($targetId, $result['readback']['target_id']);
    }

    public function test_registered_non_authority_endpoint_resolves_through_injected_canonical_reader(): void
    {
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $media = new GenericMediaRepository(new Media($mediaId, 'nhk:media:test-knowledge', 'Test image', 'ready', []));
        $capabilities = new MediaOwnerCapabilityRegistry();
        $capabilities->register(MediaOwnerCapability::forEndpoint('knowledge', [MediaUsageRoleRegistry::EVIDENCE]));
        $usages = new GenericUsageRepository();
        $service = new MediaBindingService(
            $media,
            new GenericAssetRepository(),
            $usages,
            new GenericAuthorityRepository(new AuthorityEntity(UuidCodec::newV7(), 'classification', 'unused', 'Unused', 1, [])),
            new EntityTypeRegistry(),
            new GenericOperationRepository(),
            capabilities: $capabilities,
            targetResolver: static fn (string $type, array $reference): array => ['canonical_id' => $targetId, 'stable_key' => 'nhk:knowledge:test', 'active' => true],
        );

        $result = $service->bind([
            'idempotency_key' => 'generic-knowledge-evidence',
            'media' => ['id' => $mediaId],
            'target' => ['type' => 'knowledge', 'id' => $targetId],
            'role' => MediaUsageRoleRegistry::EVIDENCE,
            'selection_source' => 'USER_EXPLICIT',
            'selection_policy' => 'PINNED',
        ]);

        self::assertSame('knowledge', $result['readback']['target_type']);
        self::assertSame(MediaUsageRoleRegistry::EVIDENCE, $result['readback']['role']);
        self::assertSame($targetId, $usages->listByEndpoint('knowledge', $targetId, MediaUsageRoleRegistry::EVIDENCE)[0]->endpointKey);
    }
}

final class GenericMediaRepository implements MediaRepository
{
    public function __construct(private Media $media) {}
    public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
    public function findByStableKey(string $key): ?Media { return $key === $this->media->stableKey ? $this->media : null; }
    public function create(Media $media): Media { return $media; }
    public function update(Media $media, int $expectedRevision): Media { return $media; }
    public function list(bool $includeRetired = false): array { return [$this->media]; }
}

final class GenericAssetRepository implements MediaAssetRepository
{
    public function findByAssetId(string $id): ?MediaAsset { return null; }
    public function create(MediaAsset $asset): MediaAsset { return $asset; }
    public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
    public function listByMediaId(string $id): array { return []; }
    public function findByChecksum(string $checksum): array { return []; }
}

final class GenericUsageRepository implements MediaUsageRepository, MediaUsageUpdater
{
    /** @var list<MediaUsage> */ private array $items = [];
    public function create(MediaUsage $usage): MediaUsage { $this->items[] = $usage; return $usage; }
    public function update(MediaUsage $usage): MediaUsage { $this->items[] = $usage; return $usage; }
    public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
    public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
}

final class GenericAuthorityRepository implements AuthorityRepository
{
    public function __construct(private AuthorityEntity $entity) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { return $id === $this->entity->canonicalId ? $this->entity : null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { return $type === $this->entity->entityType && $key === $this->entity->stableKey ? $this->entity : null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return $type === $this->entity->entityType ? [$this->entity] : []; }
}

final class GenericOperationRepository implements MediaBindingOperationRepository
{
    /** @var array<string,MediaBindingOperation> */ private array $items = [];
    public function findByOperationId(string $id): ?MediaBindingOperation { foreach ($this->items as $item) if ($item->operationId === $id) return $item; return null; }
    public function findByIdempotencyKey(string $key): ?MediaBindingOperation { return $this->items[$key] ?? null; }
    public function create(MediaBindingOperation $operation): MediaBindingOperation { $this->items[$operation->idempotencyKey] = $operation; return $operation; }
    public function save(MediaBindingOperation $operation, int $expectedRevision): MediaBindingOperation { $this->items[$operation->idempotencyKey] = $operation; return $operation; }
}
