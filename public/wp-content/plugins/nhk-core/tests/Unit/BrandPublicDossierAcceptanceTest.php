<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{BrandDossierProjection, EntityMediaProjection, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, SemanticDossierQuery};
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery};
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

final class BrandPublicDossierAcceptanceTest extends TestCase
{
    public function test_brand_detail_assembles_complete_public_dossier_without_promoting_child_claims(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'maker-a', 'Maker A', ['description' => 'A documented maker.']);
        $model = $authority->create('model', 'family-a', 'Family A', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'family-a-variant', 'Family A Variant', ['model_uuid' => $model->canonicalId]);
        $movement = $authority->create('movement', 'movement-a', 'Movement A');
        $music = $authority->create('music', 'music-a', 'Music A');

        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'maker-a-front', 'Ảnh đại diện thương hiệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'maker-a-front.jpg', hash('sha256', 'brand-img'), 'image/jpeg', 3, 1200, 900, 'PUBLIC', ['canonical_filename' => 'maker-a-front.jpg']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'brand', $brand->canonicalId, 'representative', 0, 'Ảnh đại diện thương hiệu');

        $videoId = UuidCodec::newV7();
        $video = new Video($videoId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video tư liệu', [
            'public_identity' => ['current_slug' => 'video-tu-lieu'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'thumbnail_urls' => ['https://img.example.test/brand-video.jpg']],
            'editorial' => ['title' => 'Video tư liệu', 'summary' => 'Tư liệu liên quan trực tiếp.'],
            'hub' => ['primary' => 'brand'],
            'provenance' => ['kind' => 'TEST_SOURCE'],
            'semantic_attachments' => [['target_id' => $brand->canonicalId]],
        ]);

        $source = new Source(UuidCodec::newV7(), 'source-brand-a', 'Catalogue thương hiệu', 'archive', 'box-a', ['visibility' => 'PUBLIC']);
        $brandClaimId = UuidCodec::newV7();
        $brandClaim = new KnowledgeClaim($brandClaimId, 'maker-a-history', 'Thương hiệu có một ghi nhận lịch sử trực tiếp.', 'fact', [
            'metadata' => ['subject_id' => $brand->canonicalId, 'facet' => 'chronology', 'scope' => 'brand'],
        ]);
        $movementClaim = new KnowledgeClaim(UuidCodec::newV7(), 'movement-a-construction', 'CLAIM_CHILD_MUST_NOT_PROMOTE', 'technical', [
            'metadata' => ['subject_id' => $movement->canonicalId, 'facet' => 'movement', 'scope' => 'movement'],
        ]);
        $evidence = new Evidence(UuidCodec::newV7(), $brandClaimId, $source->canonicalId, 'supports', 'Trích đoạn lịch sử.', 'p.12', true, 1, ['visibility' => 'PUBLIC']);

        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'variant' => $variant, 'movement' => $movement, 'music' => $music] as $type => $entity) {
            $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        }
        $endpoints->register('video', new FakeEndpointResolver('video', [$videoId]));
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:42']));
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, $predicates, new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'uses_movement', new NodeReference('movement', $movement->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'configured_with_music', new NodeReference('music', $music->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'supports_music', new NodeReference('music', $music->canonicalId));
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('video', $videoId));
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('wp_post', '1:42'));

        $mediaRepo = $this->mediaRepository([$media]);
        $assetRepo = $this->assetRepository([$asset]);
        $usageRepo = $this->usageRepository([$usage]);
        $videoRepo = $this->videoRepository([$video]);
        $routes = new PublicRouteResolver($authorityRepo, $types);
        $eligibility = new PublicEntityEligibilityPolicy($authorityRepo, $types, $routes);
        $aggregation = new BrandAggregationQuery($graph, $authorityRepo, $types, $routes, $eligibility);
        $dossierQuery = new SemanticDossierQuery(
            $authorityRepo,
            $types,
            new PublicIdentityContract($types),
            $eligibility,
            $routes,
            new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)),
            new EntityKnowledgeProjection($this->knowledgeRepository([$brandClaim, $movementClaim]), $this->evidenceRepository([$evidence]), $this->sourceRepository([$source])),
            new EntityMediaProjection($mediaRepo, $assetRepo, $usageRepo),
            $mediaRepo,
            $videoRepo,
            static fn(int $postId): ?array => $postId === 42 ? ['title' => 'Bài nghiên cứu thương hiệu', 'url' => '/bai-nghien-cuu-thuong-hieu/', 'excerpt' => 'Tư liệu bài viết.'] : null,
        );

        $result = (new BrandDossierProjection())->merge($dossierQuery->forEntity($brand), $aggregation->forBrand($brand->canonicalId));

        self::assertSame('AVAILABLE', $result['status']);
        self::assertCount(1, $result['relation_sections']['models']);
        self::assertCount(1, $result['relation_sections']['variants']);
        self::assertCount(1, $result['relation_sections']['movements']);
        self::assertCount(1, $result['relation_sections']['music']);
        self::assertCount(1, $result['relation_sections']['videos']);
        self::assertCount(1, $result['relation_sections']['articles']);
        self::assertSame(1, $result['knowledge']['claim_count']);
        self::assertSame(1, $result['knowledge']['evidence_count']);
        self::assertSame(1, $result['knowledge']['coverage']['sourced_claim_count']);
        self::assertNotEmpty($result['primary_media']['url'] ?? '');
        self::assertSame(['model_of', 'variant_of', 'uses_movement'], $result['relation_sections']['movements'][0]['origin']['predicates']);
        self::assertSame(['model', 'variant'], $result['relation_sections']['movements'][0]['origin']['via_types']);

        $knowledgeJson = json_encode($result['knowledge'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('CLAIM_CHILD_MUST_NOT_PROMOTE', $knowledgeJson);
        $profileJson = json_encode($result['profile'], JSON_THROW_ON_ERROR);
        foreach ([$brand->canonicalId, $model->canonicalId, $variant->canonicalId, $movement->canonicalId, $music->canonicalId] as $internalId) {
            self::assertStringNotContainsString($internalId, $profileJson);
        }
        self::assertStringNotContainsString('stable_key', $profileJson);
        self::assertSame(1, $result['coverage']['video_count']);
        self::assertSame(1, $result['coverage']['article_count']);
    }

    private function mediaRepository(array $items): MediaRepository
    {
        return new class($items) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
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

    private function videoRepository(array $items): VideoRepository
    {
        return new class($items) implements VideoRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $id) return $item; return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function knowledgeRepository(array $items): KnowledgeRepository
    {
        return new class($items) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function evidenceRepository(array $items): EvidenceRepository
    {
        return new class($items) implements EvidenceRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Evidence { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn(Evidence $item): bool => $item->claimId === $claimId)); }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn(Evidence $item): bool => $item->sourceId === $sourceId)); }
        };
    }

    private function sourceRepository(array $items): SourceRepository
    {
        return new class($items) implements SourceRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Source { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?Source { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }
}
