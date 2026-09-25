<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaBindingService, MediaOwnerCapabilityRegistry, MediaTargetNormalizer};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaUsage};
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, WpPostEndpointResolver};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaUsageReuseAcceptanceTest extends TestCase
{
    private const MEDIA_A = '01a0d7ee-3e33-7366-88c6-287112b34936';
    private const MEDIA_B = '01a0d7ee-3e33-7366-88c6-287112b34937';
    private const MODEL = '01a07614-832d-7f27-959c-74eb0cd63f3e';
    private const VARIANT = '01a07614-832d-7f27-959c-74eb0cd63f40';

    public function test_one_media_can_be_used_by_two_posts_and_two_authority_targets(): void
    {
        [$service, $usages] = $this->service();

        $post18 = $this->add($service, 'post-18', ['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18], 'featured_primary');
        $post19 = $this->add($service, 'post-19', ['type' => 'wp_post', 'id' => '1:19'], 'featured_primary');
        $model = $this->add($service, 'model', ['type' => 'model', 'id' => self::MODEL], 'representative');
        $variant = $this->add($service, 'variant', ['type' => 'variant', 'id' => self::VARIANT], 'technical_detail', 'dial');

        self::assertSame(self::MEDIA_A, $post18['media_id']);
        self::assertSame('1:18', $post18['readback']['target_id']);
        self::assertSame('1:19', $post19['readback']['target_id']);
        self::assertSame(self::MODEL, $model['readback']['target_id']);
        self::assertSame(self::VARIANT, $variant['readback']['target_id']);
        self::assertCount(4, $usages->listByMediaId(self::MEDIA_A));
        self::assertCount(1, $usages->listByEndpoint('wp_post', '1:18', 'featured_primary'));
        self::assertCount(1, $usages->listByEndpoint('wp_post', '1:19', 'featured_primary'));
        self::assertCount(1, $usages->listByEndpoint('variant', self::VARIANT, 'technical_detail'));
    }

    public function test_replacement_is_target_local_and_preserves_retired_history(): void
    {
        [$service, $usages] = $this->service();
        $old18 = $this->add($service, 'old-18', ['type' => 'wp_post', 'id' => '1:18'], 'featured_primary');
        $post19 = $this->add($service, 'same-media-19', ['type' => 'wp_post', 'id' => '1:19'], 'featured_primary');

        $replaced = $service->mutate([
            'operation' => 'replace', 'idempotency_key' => 'replace-18', 'media' => ['id' => self::MEDIA_B],
            'target' => ['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18], 'usage_id' => $old18['usage_id'],
            'expected_usage_revision' => 1, 'role' => 'featured_primary', 'placement_key' => 'featured_primary',
        ]);

        self::assertNotSame($old18['usage_id'], $replaced['usage_id']);
        self::assertSame(self::MEDIA_B, $replaced['media_id']);
        self::assertSame('retired', $usages->listByEndpoint('wp_post', '1:18')[0]->activeSlot);
        self::assertSame(self::MEDIA_B, array_values(array_filter($usages->listByEndpoint('wp_post', '1:18'), static fn (MediaUsage $usage): bool => $usage->activeSlot !== 'retired'))[0]->mediaId);
        self::assertSame(self::MEDIA_A, $usages->listByEndpoint('wp_post', '1:19', 'featured_primary')[0]->mediaId);
        self::assertSame($post19['usage_id'], $usages->listByEndpoint('wp_post', '1:19', 'featured_primary')[0]->usageId);
    }

    /** @return array{0:MediaBindingService,1:ReuseUsageRepository} */
    private function service(): array
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('model', 1, true));
        $types->register(new EntityTypeDefinition('variant', 1, true));
        $authority = new ReuseAuthorityRepository([
            new AuthorityEntity(self::MODEL, 'model', 'nhk:model:clock', 'Clock', 1, []),
            new AuthorityEntity(self::VARIANT, 'variant', 'nhk:variant:clock', 'Clock variant', 1, []),
        ]);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('model', new AuthorityEndpointResolver($types, $authority));
        $endpoints->register('variant', new AuthorityEndpointResolver($types, $authority));
        $endpoints->register('wp_post', new WpPostEndpointResolver(
            static fn (int $id): ?object => in_array($id, [18, 19], true) ? (object) ['post_status' => 'publish', 'post_modified_gmt' => '2023-11-14 22:13:20'] : null,
            static fn (): int => 1,
        ));
        $media = new ReuseMediaRepository([
            new Media(self::MEDIA_A, 'wp-attachment:1:567', 'A', 'ready'),
            new Media(self::MEDIA_B, 'wp-attachment:1:568', 'B', 'ready'),
        ]);
        $usages = new ReuseUsageRepository();
        $service = new MediaBindingService($media, new ReuseAssetRepository(), $usages, $authority, $types, targetNormalizer: new MediaTargetNormalizer($endpoints, $types, $authority), capabilities: MediaOwnerCapabilityRegistry::fromEndpointRegistry($endpoints));
        return [$service, $usages];
    }

    private function add(MediaBindingService $service, string $key, array $target, string $role, string $placement = ''): array
    {
        return $service->mutate([
            'operation' => 'add', 'idempotency_key' => $key, 'media' => ['id' => self::MEDIA_A], 'target' => $target,
            'role' => $role, 'placement_key' => $placement, 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED',
        ]);
    }
}

final class ReuseMediaRepository implements MediaRepository
{
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $key): ?Media { return null; }
    public function create(Media $media): Media { return $media; }
    public function update(Media $media, int $expectedRevision): Media { return $media; }
    public function list(bool $includeRetired = false): array { return $this->items; }
}

final class ReuseAssetRepository implements MediaAssetRepository
{
    public function findByAssetId(string $id): ?MediaAsset { return null; }
    public function create(MediaAsset $asset): MediaAsset { return $asset; }
    public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
    public function listByMediaId(string $id): array { return []; }
    public function findByChecksum(string $checksum): array { return []; }
}

final class ReuseAuthorityRepository implements AuthorityRepository
{
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type)); }
}

final class ReuseUsageRepository implements MediaUsageRepository, MediaUsageUpdater
{
    private array $items = [];
    public function create(MediaUsage $usage): MediaUsage
    {
        foreach ($this->items as $item) {
            if ($item->endpointType === $usage->endpointType && $item->endpointKey === $usage->endpointKey && $item->role === $usage->role && $item->placementKey === $usage->placementKey && $item->activeSlot !== 'retired') throw new MediaException('duplicate');
            if ($usage->activeSlot !== null && $item->endpointType === $usage->endpointType && $item->endpointKey === $usage->endpointKey && $item->role === $usage->role && $item->activeSlot === $usage->activeSlot) throw new MediaException('active slot duplicate');
        }
        $this->items[] = $usage;
        return $usage;
    }
    public function update(MediaUsage $usage): MediaUsage { foreach ($this->items as $index => $item) if ($item->usageId === $usage->usageId) { $this->items[$index] = $usage; return $usage; } throw new MediaException('missing'); }
    public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
    public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
}
