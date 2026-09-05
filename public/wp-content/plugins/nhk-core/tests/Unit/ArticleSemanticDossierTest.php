<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{EntityMediaProjection, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, SemanticDossierQuery};
use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy, RelatedSemanticQuery};
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ArticleSemanticDossierTest extends TestCase
{
    public function test_article_uses_same_path_aware_relation_engine_while_wordpress_owns_identity(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepo = new InMemoryAuthorityRepository(), $types);
        $movement = $authority->create('movement', 'movement-a', 'Machine A');
        $music = $authority->create('music', 'music-a', 'Music A');

        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $endpoints->register('movement', new FakeEndpointResolver('movement', [$movement->canonicalId]));
        $endpoints->register('music', new FakeEndpointResolver('music', [$music->canonicalId]));
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, $predicates, new InMemoryAuditSink());
        $graph->create(new NodeReference('wp_post', '1:55'), 'about', new NodeReference('movement', $movement->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'supports_music', new NodeReference('music', $music->canonicalId));

        $media = new Media($mediaId = UuidCodec::newV7(), 'article-image', 'Ảnh bài viết', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'article-image.jpg', hash('sha256', 'x'), 'image/jpeg', 1, 1200, 800, 'PUBLIC', ['canonical_filename' => 'article-image.jpg']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:55', 'representative', 0, 'Ảnh bài viết');
        $mediaRepo = $this->mediaRepository([$media]);
        $assetRepo = $this->assetRepository([$asset]);
        $usageRepo = $this->usageRepository([$usage]);
        $routes = new PublicRouteResolver($authorityRepo, $types);

        $query = new SemanticDossierQuery(
            $authorityRepo,
            $types,
            new PublicIdentityContract($types),
            new PublicEntityEligibilityPolicy($authorityRepo, $types, $routes),
            $routes,
            new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)),
            new EntityKnowledgeProjection($this->knowledgeRepository(), $this->evidenceRepository(), $this->sourceRepository()),
            new EntityMediaProjection($mediaRepo, $assetRepo, $usageRepo),
            $mediaRepo,
            $this->videoRepository(),
            static fn(int $postId): ?array => $postId === 55 ? ['title' => 'Bài nghiên cứu', 'url' => '/bai-nghien-cuu/', 'excerpt' => 'Tóm tắt bài viết'] : null,
        );

        $result = $query->forPost(55);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('Bài nghiên cứu', $result['identity']['title']);
        self::assertSame('/bai-nghien-cuu/', $result['identity']['url']);
        self::assertStringContainsString('/anh/article-image.webp', (string) ($result['primary_media']['url'] ?? ''));
        self::assertSame('DIRECT', $result['relation_sections']['movements'][0]['origin']['kind'] ?? null);
        self::assertSame('DERIVED', $result['relation_sections']['music'][0]['origin']['kind'] ?? null);
        self::assertSame(2, $result['relation_sections']['music'][0]['origin']['hop_count'] ?? null);
        self::assertStringNotContainsString($movement->canonicalId, json_encode($result, JSON_THROW_ON_ERROR));
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

    private function assetRepository(array $items): MediaAssetRepository
    {
        return new class($items) implements MediaAssetRepository {
            public function __construct(private array $items) {}
            public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return array_values(array_filter($this->items, static fn(MediaAsset $item): bool => $item->mediaId === $mediaId)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
    }

    private function usageRepository(array $items): MediaUsageRepository
    {
        return new class($items) implements MediaUsageRepository {
            public function __construct(private array $items) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return array_values(array_filter($this->items, static fn(MediaUsage $item): bool => $item->mediaId === $mediaId && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return array_values(array_filter($this->items, static fn(MediaUsage $item): bool => $item->endpointType === $endpointType && $item->endpointKey === $endpointKey && ($role === null || $item->role === $role))); }
        };
    }

    private function videoRepository(): VideoRepository
    {
        return new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }

    private function knowledgeRepository(): KnowledgeRepository
    {
        return new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }

    private function evidenceRepository(): EvidenceRepository
    {
        return new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
    }

    private function sourceRepository(): SourceRepository
    {
        return new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }
}
