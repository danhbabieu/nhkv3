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
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class RelatedContentQueryTest extends TestCase
{
    public function test_related_entities_are_derived_from_graph_in_both_directions(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, [])); $types->register(new EntityTypeDefinition('model', 1, true, []));
        $authority = new AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'odo', 'Odo'); $model = $authority->create('model', 'calibre-1', 'Calibre 1');
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId])); $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService($graphRepository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('model', $model->canonicalId));
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyVideos = new class implements VideoRepository { public function findByCanonicalId(string $id): ?Video { return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $video): Video { return $video; } public function update(Video $video, int $expectedRevision): Video { return $video; } public function list(bool $includeRetired = false): array { return []; } };
        $related = (new RelatedContentQuery($graph, $authorityRepository, $emptyMedia, $emptyVideos, $types))->forEntity('brand', $brand->canonicalId);
        self::assertSame([['type' => 'model', 'id' => $model->canonicalId, 'title' => 'Calibre 1', 'url' => '/model/calibre-1/']], $related['entities']);
        self::assertSame([], $related['articles']); self::assertSame([], $related['media']); self::assertSame([], $related['videos']);
    }
}
