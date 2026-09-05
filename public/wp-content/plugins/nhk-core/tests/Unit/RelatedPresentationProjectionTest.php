<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\RelatedContentQuery;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class RelatedPresentationProjectionTest extends TestCase
{
    public function test_related_items_distinguish_direct_from_derived_and_video_keeps_thumbnail(): void
    {
        $types = new EntityTypeRegistry();
        foreach (['brand','model'] as $type) $types->register(new EntityTypeDefinition($type, 1, true, []));
        $authority = new AuthorityService($authorityRepo = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'maker', 'Maker');
        $model = $authority->create('model', 'model', 'Model');
        $media = new Media($mediaId = UuidCodec::newV7(), 'media', 'Ảnh hiện vật', 'ready');
        $video = new Video(UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video kỹ thuật', ['source_snapshot' => ['availability' => 'available', 'thumbnail_urls' => ['https://img.example.test/video.jpg']]]);

        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $endpoints->register('media', new FakeEndpointResolver('media', [$mediaId]));
        $endpoints->register('video', new FakeEndpointResolver('video', [$video->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('media', $mediaId));
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'about', new NodeReference('video', $video->canonicalId));

        $result = (new RelatedContentQuery($graph, $authorityRepo, $this->mediaRepository([$media]), $this->videoRepository([$video]), $types))->forEntity('brand', $brand->canonicalId);

        self::assertSame('DIRECT', $result['media'][0]['relationship_class'] ?? null);
        self::assertSame(1, $result['media'][0]['hop_count'] ?? null);
        self::assertSame('DERIVED', $result['videos'][0]['relationship_class'] ?? null);
        self::assertSame(2, $result['videos'][0]['hop_count'] ?? null);
        self::assertSame('https://img.example.test/video.jpg', $result['videos'][0]['thumbnail_url'] ?? null);
    }

    private function mediaRepository(array $items): MediaRepository
    {
        return new class($items) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function videoRepository(array $items): VideoRepository
    {
        return new class($items) implements VideoRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }
}
