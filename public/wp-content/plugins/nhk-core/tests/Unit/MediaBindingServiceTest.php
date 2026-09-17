<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaBindingService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaBindingOperationRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaBindingOperation, MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaBindingServiceTest extends TestCase
{
    public function test_exact_user_binding_is_pinned_and_replay_is_receipt_idempotent(): void
    {
        [$service, $usages] = $this->service();
        $mediaId = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $targetId = '01a07614-832d-7f27-959c-74eb0cd63f3e';
        $request = ['idempotency_key' => 'golden-567', 'media' => ['id' => $mediaId], 'target' => ['type' => 'classification', 'id' => $targetId], 'role' => 'representative', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED', 'seo' => ['alt_text' => 'Đồng hồ chim cúc cu', 'caption' => 'Ảnh đại diện']];

        $first = $service->bind($request);
        $second = $service->bind($request);

        self::assertSame('COMPLETE', $first['status']);
        self::assertSame($first['operation_id'], $second['operation_id']);
        self::assertSame(1, count($usages->listByEndpoint('classification', $targetId, 'representative')));
        self::assertSame($mediaId, $usages->listByEndpoint('classification', $targetId, 'representative')[0]->mediaId);
        self::assertSame('USER_EXPLICIT', $usages->listByEndpoint('classification', $targetId, 'representative')[0]->selectionSource);
        self::assertSame('PINNED', $usages->listByEndpoint('classification', $targetId, 'representative')[0]->selectionPolicy);
    }

    public function test_auto_cannot_replace_pinned(): void
    {
        [$service, $usages] = $this->service();
        $targetId = '01a07614-832d-7f27-959c-74eb0cd63f3e';
        $service->bind(['idempotency_key' => 'pin', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c89'], 'target' => ['type' => 'classification', 'id' => $targetId]]);

        $this->expectException(MediaException::class);
        $service->bind(['idempotency_key' => 'auto', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c90'], 'target' => ['type' => 'classification', 'id' => $targetId], 'selection_source' => 'SYSTEM_AUTO', 'selection_policy' => 'AUTO']);
    }

    public function test_explicit_binding_replaces_pinned_without_deleting_media(): void
    {
        [$service, $usages] = $this->service();
        $targetId = '01a07614-832d-7f27-959c-74eb0cd63f3e';
        $service->bind(['idempotency_key' => 'pin', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c89'], 'target' => ['type' => 'classification', 'id' => $targetId]]);
        $service->bind(['idempotency_key' => 'explicit-replace', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c90'], 'target' => ['type' => 'classification', 'id' => $targetId], 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']);
        self::assertCount(1, $usages->listByEndpoint('classification', $targetId, 'representative'));
        self::assertCount(1, $usages->listByEndpoint('classification', $targetId, 'gallery'));
    }

    public function test_same_media_can_bind_to_multiple_exact_targets_and_supported_locators_reuse_it(): void
    {
        [$service, $usages] = $this->service();
        $mediaId = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $service->bind(['idempotency_key' => 'target-one', 'media' => ['attachment_id' => 567], 'target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f3e']]);
        $service->bind(['idempotency_key' => 'target-two', 'media' => ['url' => 'https://nhk.test/anh/cuckoo.webp'], 'target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f40']]);

        self::assertSame($mediaId, $usages->listByEndpoint('classification', '01a07614-832d-7f27-959c-74eb0cd63f3e', 'representative')[0]->mediaId);
        self::assertSame($mediaId, $usages->listByEndpoint('classification', '01a07614-832d-7f27-959c-74eb0cd63f40', 'representative')[0]->mediaId);
    }

    public function test_typed_batch_binds_uploaded_item_by_index_to_a_stable_target(): void
    {
        [$service, $usages] = $this->service();
        $mediaId = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $targetId = '01a07614-832d-7f27-959c-74eb0cd63f3e';

        $result = $service->bindMany([[
            'media_ref' => ['item_index' => 0],
            'target' => ['type' => 'classification', 'stable_key' => 'nhk:classification:clock-type.cuckoo-clock'],
            'role' => 'representative',
            'selection_source' => 'USER_EXPLICIT',
            'selection_policy' => 'PINNED',
        ]], 'capture-upload', [['media_id' => $mediaId]]);

        self::assertSame('COMPLETE', $result['status']);
        self::assertSame($targetId, $usages->listByEndpoint('classification', $targetId, 'representative')[0]->endpointKey);
        self::assertSame($mediaId, $result['media_ids'][0]);
    }

    public function test_inactive_target_and_wrong_target_type_fail_closed(): void
    {
        [$service] = $this->service(false);
        $this->expectException(MediaException::class);
        $service->bind(['idempotency_key' => 'inactive', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c89'], 'target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f40']]);
    }

    public function test_changed_payload_under_same_key_is_an_idempotency_conflict(): void
    {
        [$service] = $this->service();
        $base = ['idempotency_key' => 'same-key', 'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c89'], 'target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f3e']];
        $service->bind($base);
        $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
        $service->bind($base + ['seo' => ['caption' => 'payload changed']]);
    }

    public function test_duplicate_operation_create_race_rechecks_the_returned_receipt_fingerprint(): void
    {
        $operations = new RaceOperationRepository();
        $operations->existing = new MediaBindingOperation(
            UuidCodec::newV7(),
            'race-key',
            str_repeat('b', 64),
            null,
            'classification',
            '01a07614-832d-7f27-959c-74eb0cd63f3e',
            'representative',
            'USER_EXPLICIT',
            'PINNED',
        );
        [$service, $usages] = $this->service(true, $operations);

        try {
            $service->bind([
                'idempotency_key' => 'race-key',
                'media' => ['id' => '01a0ab0c-fde0-7c01-a89d-fc5eef832c89'],
                'target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f3e'],
            ]);
            self::fail('The duplicate operation race must be rejected before usage mutation.');
        } catch (MediaException $error) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $error->getMessage());
        }

        self::assertCount(0, $usages->listByEndpoint('classification', '01a07614-832d-7f27-959c-74eb0cd63f3e', 'representative'));
    }

    public function test_auto_discovery_requires_registered_scope_and_returns_review_on_tie(): void
    {
        [$service] = $this->service();
        $result = $service->autoDiscoverAndBind('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', [
            ['target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f3e'], 'scope' => 'representative', 'scope_justified' => true, 'semantic_specificity' => 5],
            ['target' => ['type' => 'classification', 'id' => '01a07614-832d-7f27-959c-74eb0cd63f40'], 'scope' => 'representative', 'scope_justified' => true, 'semantic_specificity' => 5],
        ], 'auto-tie');
        self::assertSame('REVIEW_REQUIRED', $result['status']);
    }

    /** @return array{0:MediaBindingService,1:MemoryUsageRepository} */
    private function service(bool $activeTarget = true, ?MediaBindingOperationRepository $operations = null): array
    {
        $mediaId = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $media = new MemoryMediaRepository([
            new Media($mediaId, 'wp-attachment:1:567', 'Cuckoo clock', 'ready', ['source' => 'wordpress']),
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c90', 'wp-attachment:1:568', 'Second clock', 'ready', ['source' => 'wordpress']),
        ]);
        $assets = new MemoryAssetRepository([
            new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/cuckoo.jpg', hash('sha256', 'cuckoo'), 'image/jpeg', 10, 1200, 800, 'PUBLIC', ['wordpress_attachment_id' => 567, 'public_url_path' => '/anh/cuckoo.webp']),
            new MediaAsset(UuidCodec::newV7(), '01a0ab0c-fde0-7c01-a89d-fc5eef832c90', 'original', 'uploads/second.jpg', hash('sha256', 'second'), 'image/jpeg', 10, 1200, 800, 'PUBLIC', ['wordpress_attachment_id' => 568]),
        ]);
        $targetOne = new AuthorityEntity('01a07614-832d-7f27-959c-74eb0cd63f3e', 'classification', 'nhk:classification:clock-type.cuckoo-clock', 'Đồng hồ chim cúc cu', 1, ['family' => 'clock_type']);
        $targetTwo = new AuthorityEntity('01a07614-832d-7f27-959c-74eb0cd63f40', 'classification', 'nhk:classification:clock-type.second', 'Second clock', 1, ['family' => 'clock_type'], $activeTarget ? AuthorityState::ACTIVE : AuthorityState::RETIRED);
        $authority = new MemoryAuthorityRepository([$targetOne, $targetTwo]);
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('classification', 1, true));
        $usages = new MemoryUsageRepository();
        return [new MediaBindingService($media, $assets, $usages, $authority, $types, $operations ?? new MemoryOperationRepository()), $usages];
    }
}

final class MemoryMediaRepository implements MediaRepository
{
    /** @param list<Media> $items */ public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
    public function create(Media $media): Media { $this->items[] = $media; return $media; }
    public function update(Media $media, int $expectedRevision): Media { return $media; }
    public function list(bool $includeRetired = false): array { return $this->items; }
}

final class MemoryAssetRepository implements MediaAssetRepository
{
    /** @param list<MediaAsset> $items */ public function __construct(private array $items) {}
    public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
    public function create(MediaAsset $asset): MediaAsset { $this->items[] = $asset; return $asset; }
    public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
    public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->mediaId === $id)); }
    public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->checksum === $checksum)); }
}

final class MemoryUsageRepository implements MediaUsageRepository, MediaUsageUpdater
{
    /** @var list<MediaUsage> */ private array $items = [];
    public function create(MediaUsage $usage): MediaUsage { foreach ($this->items as $item) if ($item->endpointType === $usage->endpointType && $item->endpointKey === $usage->endpointKey && $item->role === $usage->role && $item->placementKey === $usage->placementKey) throw new MediaException('duplicate'); foreach ($this->items as $item) if ($usage->activeSlot !== null && $item->endpointType === $usage->endpointType && $item->endpointKey === $usage->endpointKey && $item->role === $usage->role && $item->activeSlot === $usage->activeSlot) throw new MediaException('active slot duplicate'); $this->items[] = $usage; return $usage; }
    public function update(MediaUsage $usage): MediaUsage { foreach ($this->items as $index => $item) if ($item->usageId === $usage->usageId) { $updated = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $usage->revision + 1, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->activeSlot); $this->items[$index] = $updated; return $updated; } throw new MediaException('missing'); }
    public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
    public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
}

final class MemoryAuthorityRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $items */ public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type)); }
}

final class MemoryOperationRepository implements MediaBindingOperationRepository
{
    /** @var list<MediaBindingOperation> */ private array $items = [];
    public function findByOperationId(string $id): ?MediaBindingOperation { foreach ($this->items as $item) if ($item->operationId === $id) return $item; return null; }
    public function findByIdempotencyKey(string $key): ?MediaBindingOperation { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function create(MediaBindingOperation $operation): MediaBindingOperation { $this->items[] = $operation; return $operation; }
    public function save(MediaBindingOperation $operation, int $expectedRevision): MediaBindingOperation { foreach ($this->items as $index => $item) if ($item->operationId === $operation->operationId) { if ($item->revision !== $expectedRevision) throw new MediaException('conflict'); $this->items[$index] = $operation; return $operation; } throw new MediaException('missing'); }
}

final class RaceOperationRepository implements MediaBindingOperationRepository
{
    public ?MediaBindingOperation $existing = null;
    private bool $firstLookup = true;

    public function findByOperationId(string $id): ?MediaBindingOperation
    {
        return $this->existing?->operationId === $id ? $this->existing : null;
    }

    public function findByIdempotencyKey(string $key): ?MediaBindingOperation
    {
        if ($this->firstLookup) {
            $this->firstLookup = false;
            return null;
        }
        return $this->existing?->idempotencyKey === $key ? $this->existing : null;
    }

    public function create(MediaBindingOperation $operation): MediaBindingOperation
    {
        return $this->existing ?? $operation;
    }

    public function save(MediaBindingOperation $operation, int $expectedRevision): MediaBindingOperation
    {
        $this->existing = $operation;
        return $operation;
    }
}
