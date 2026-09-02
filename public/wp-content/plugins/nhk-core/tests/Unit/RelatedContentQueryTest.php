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

final class RelatedContentQueryTest extends TestCase
{
    public function test_invalid_canonical_entity_input_fails_closed_before_graph_lookup(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $throwingAuthority = new class implements \NHK\Core\Contracts\Authority\AuthorityRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Authority\AuthorityEntity { throw new \RuntimeException('invalid UUID reached authority'); }
            public function findByStableKey(string $type, string $key): ?\NHK\Core\Domain\Authority\AuthorityEntity { return null; }
            public function create(\NHK\Core\Domain\Authority\AuthorityEntity $entity): \NHK\Core\Domain\Authority\AuthorityEntity { return $entity; }
            public function update(\NHK\Core\Domain\Authority\AuthorityEntity $entity, int $expectedRevision): \NHK\Core\Domain\Authority\AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyVideos = new class implements VideoRepository { public function findByCanonicalId(string $id): ?Video { return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $video): Video { return $video; } public function update(Video $video, int $expectedRevision): Video { return $video; } public function list(bool $includeRetired = false): array { return []; } };
        $graph = new GraphService(new InMemoryGraphRepository(), new EndpointTypeRegistry(), new PredicateRegistry(), new InMemoryAuditSink());

        self::assertSame(['entities' => [], 'articles' => [], 'media' => [], 'videos' => []], (new RelatedContentQuery($graph, $throwingAuthority, $emptyMedia, $emptyVideos, $types))->forEntity('brand', '00000000-0000-0000-0000-000000000000'));
    }

    public function test_related_entities_are_derived_from_graph_in_both_directions(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, [])); $types->register(new EntityTypeDefinition('model', 1, true, ['brand_uuid']));
        $authority = new AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'odo', 'Odo'); $model = $authority->create('model', 'calibre-1', 'Calibre 1', ['brand_uuid' => $brand->canonicalId]);
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId])); $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService($graphRepository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('model', $model->canonicalId));
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyVideos = new class implements VideoRepository { public function findByCanonicalId(string $id): ?Video { return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $video): Video { return $video; } public function update(Video $video, int $expectedRevision): Video { return $video; } public function list(bool $includeRetired = false): array { return []; } };
        $related = (new RelatedContentQuery($graph, $authorityRepository, $emptyMedia, $emptyVideos, $types))->forEntity('brand', $brand->canonicalId);
        $expectedUrl = function_exists('home_url') ? home_url('/odo/calibre-1/') : '/odo/calibre-1/';
        self::assertSame([['type' => 'model', 'title' => 'Calibre 1', 'url' => $expectedUrl]], $related['entities']);
        self::assertSame([], $related['articles']); self::assertSame([], $related['media']); self::assertSame([], $related['videos']);
    }

    public function test_post_related_query_uses_the_wp_post_graph_endpoint(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'odo', 'Odo');
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:42']));
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $graph = new GraphService($graphRepository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('wp_post', '1:42'), 'about', new NodeReference('brand', $brand->canonicalId));
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyVideos = new class implements VideoRepository { public function findByCanonicalId(string $id): ?Video { return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $video): Video { return $video; } public function update(Video $video, int $expectedRevision): Video { return $video; } public function list(bool $includeRetired = false): array { return []; } };

        $related = (new RelatedContentQuery($graph, $authorityRepository, $emptyMedia, $emptyVideos, $types))->forPost(42);

        self::assertSame([['type' => 'brand', 'title' => 'Odo', 'url' => function_exists('home_url') ? home_url('/odo/') : '/odo/']], $related['entities']);
        self::assertSame([], $related['articles']);
    }

    public function test_related_query_hides_invalid_public_video_references(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'odo', 'Odo');
        $invalid = new Video(UuidCodec::newV7(), 'vimeo', 'bad-reference', 'https://vimeo.com/bad-reference', 'Invalid');
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('video', new FakeEndpointResolver('video', [$invalid->canonicalId]));
        $graph = new GraphService($graphRepository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('video', $invalid->canonicalId));
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } };
        $videos = new class($invalid) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };

        $related = (new RelatedContentQuery($graph, $authorityRepository, $emptyMedia, $videos, $types))->forEntity('brand', $brand->canonicalId);

        self::assertSame([], $related['videos']);
    }
}
