<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Application\Media\MediaService;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Infrastructure\Graph\{MediaEndpointResolver, VideoEndpointResolver};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class P6PersistenceTest extends TestCase
{
    public function test_media_service_keeps_identity_separate_from_assets_and_usage(): void
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { if (($this->items[$item->canonicalId]->revision ?? 0) !== $revision) throw new \RuntimeException('stale'); $next = new Media($item->canonicalId, $item->stableKey, $item->canonicalName, $item->readiness, $item->provenance, $item->active, $revision + 1); return $this->items[$item->canonicalId] = $next; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $item): MediaAsset { return $this->items[$item->assetId] = $item; }
            public function update(MediaAsset $item, int $expectedRevision = 1): MediaAsset { return $this->items[$item->assetId] = $item; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, fn (MediaAsset $item): bool => $item->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, fn (MediaAsset $item): bool => $item->checksum === $checksum)); }
        };
        $usages = new class implements MediaUsageRepository {
            public array $items = [];
            public function create(MediaUsage $item): MediaUsage { return $this->items[$item->usageId] = $item; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
        };
        $service = new MediaService($media, $assets, $usages);
        $created = $service->create('odo-front', 'Odo front', 'ready', ['source' => 'migration']);
        $asset = $service->addAsset($created->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'binary'), 'image/jpeg', 6, 1200, 800);
        $usage = $service->addUsage($created->canonicalId, 'wp_post', '1:42', 'featured');
        self::assertSame($created->canonicalId, $asset->mediaId);
        self::assertSame('PRIVATE', $asset->visibility);
        $sameUsage = $service->addUsage($created->canonicalId, 'wp_post', '1:42', 'featured');
        self::assertSame($usage->usageId, $sameUsage->usageId);
        self::assertCount(1, $service->usages($created->canonicalId));
        $sameAsset = $service->addAsset($created->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'binary'), 'image/jpeg', 6, 1200, 800);
        self::assertSame($asset->assetId, $sameAsset->assetId);
        self::assertCount(1, $service->assets($created->canonicalId));
        self::assertSame($created->canonicalId, $usage->mediaId);
        try {
            $service->addAsset($created->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'changed'), 'image/jpeg', 7, 1200, 800);
            self::fail('Expected a conflicting Media asset storage key to be rejected.');
        } catch (\NHK\Core\Domain\Media\MediaException $exception) {
            self::assertSame('Media asset storage key is already bound to different content.', $exception->getMessage());
        }
        try {
            $service->addUsage($created->canonicalId, 'wp_post', '1:42', 'featured', 1);
            self::fail('Expected a conflicting Media usage sort order to be rejected.');
        } catch (\NHK\Core\Domain\Media\MediaException $exception) {
            self::assertSame('Media usage is already bound to a different sort order.', $exception->getMessage());
        }
        self::assertCount(1, $service->assets($created->canonicalId));
        self::assertCount(1, $service->usages($created->canonicalId));

        $packet = [
            ['kind' => 'original', 'storage_key' => 'uploads/fast-media.jpg', 'checksum' => hash('sha256', 'fast-media'), 'mime_type' => 'image/jpeg', 'byte_size' => 9, 'width' => 900, 'height' => 600, 'metadata' => ['source' => 'mcp']],
        ];
        $fast = $service->ingest('fast-media', 'Fast Media', 'draft', ['source' => 'mcp'], $packet, [['endpoint_type' => 'wp_post', 'endpoint_key' => '1:42', 'role' => 'gallery']]);
        $sameFast = $service->ingest('fast-media', 'Fast Media', 'draft', ['source' => 'mcp'], $packet, [['endpoint_type' => 'wp_post', 'endpoint_key' => '1:42', 'role' => 'gallery']]);
        self::assertSame($fast->canonicalId, $sameFast->canonicalId);
        self::assertCount(1, $service->assets($fast->canonicalId));
        self::assertSame('PRIVATE', $service->assets($fast->canonicalId)[0]->visibility);
        self::assertCount(1, $service->usages($fast->canonicalId));

        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), $types), null, $service);
        $executed = $executor(new Proposal(
            id: 'media-ingest-1',
            subjectId: 'media',
            operation: 'ingest',
            payload: ['stable_key' => 'executor-media', 'name' => 'Executor Media', 'assets' => $packet],
            contentFingerprint: 'content',
            expectedRevision: 1,
            dependencyFingerprint: 'deps',
            state: \NHK\Core\Domain\Governance\ProposalState::APPROVED,
            actor: '1',
            decisionActor: '2',
            idempotencyKey: 'idem-media',
            entityType: 'media',
        ));
        self::assertInstanceOf(Media::class, $executed);
        self::assertCount(1, $service->assets($executed->canonicalId));
    }

    public function test_video_service_deduplicates_external_reference_without_merging_identity(): void
    {
        $repo = new class implements VideoRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $id): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $id) return $item; return null; }
            public function create(Video $item): Video { return $this->items[$item->canonicalId] = $item; }
            public function update(Video $item, int $revision): Video { return $this->items[$item->canonicalId] = new Video($item->canonicalId, $item->platform, $item->externalVideoId, $item->canonicalUrl, $item->title, $item->metadata, $item->thumbnailMediaId, $item->active, $revision + 1); }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $service = new VideoService($repo);
        $first = $service->ingestUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference');
        $same = $service->ingestUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference');
        self::assertSame($first->canonicalId, $same->canonicalId);
        self::assertCount(1, $repo->items);
    }

    public function test_video_proposals_use_governance_executor_for_ingest_and_state_lifecycle(): void
    {
        $repo = new class implements VideoRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $id): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $id) return $item; return null; }
            public function create(Video $item): Video { return $this->items[$item->canonicalId] = $item; }
            public function update(Video $item, int $revision): Video { if (($this->items[$item->canonicalId]->revision ?? 0) !== $revision) throw new \RuntimeException('stale'); return $this->items[$item->canonicalId] = new Video($item->canonicalId, $item->platform, $item->externalVideoId, $item->canonicalUrl, $item->title, $item->metadata, $item->thumbnailMediaId, $item->active, $revision + 1); }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $service = new VideoService($repo);
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), $types), null, null, $service);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NO_SEMANTIC_ATTACHMENT');
        $executor(new Proposal('video-ingest-1', 'video', 'ingest', ['url' => 'https://youtu.be/9bZkp7q19f0', 'title' => 'Canonical video', 'metadata' => ['source' => 'test']], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-video-ingest', 1, null, null, null, 'video'));
    }

    public function test_media_and_video_are_real_graph_endpoints_and_retired_records_remain_resolvable(): void
    {
        $id = UuidCodec::newV7();
        $media = new Media($id, 'media-key', 'Media');
        $mediaRepo = new class($media) implements MediaRepository {
            public function __construct(private Media $item) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByStableKey(string $key): ?Media { return $key === $this->item->stableKey ? $this->item : null; }
            public function create(Media $item): Media { return $item; }
            public function update(Media $item, int $revision): Media { return $item; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $mediaResolver = new MediaEndpointResolver($mediaRepo);
        self::assertTrue($mediaResolver->supports('media'));
        self::assertTrue($mediaResolver->exists(new NodeReference('media', $id)));

        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ');
        $videoRepo = new class($video) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return $platform === $this->item->platform && $id === $this->item->externalVideoId ? $this->item : null; }
            public function create(Video $item): Video { return $item; }
            public function update(Video $item, int $revision): Video { return $item; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $videoResolver = new VideoEndpointResolver($videoRepo);
        self::assertTrue($videoResolver->supports('video'));
        self::assertTrue($videoResolver->exists(new NodeReference('video', $video->canonicalId)));
    }
}
