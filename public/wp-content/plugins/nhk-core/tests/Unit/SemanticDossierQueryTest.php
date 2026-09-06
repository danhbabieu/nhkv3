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

final class SemanticDossierQueryTest extends TestCase
{
    public function test_movement_dossier_assembles_path_aware_relations_knowledge_media_and_video_without_internal_ids(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepo = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'maker-a', 'Maker A');
        $model = $authority->create('model', 'family-a', 'Family A', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'family-a-long', 'Family A Long', ['model_uuid' => $model->canonicalId]);
        $movement = $authority->create('movement', 'movement-39', 'Machine 39', ['description' => 'Movement dossier subject.']);
        $music = $authority->create('music', 'music-a', 'Music A');

        $media = new Media($mediaId = UuidCodec::newV7(), 'movement-front', 'Ảnh mặt máy', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'movement-front.jpg', hash('sha256', 'img'), 'image/jpeg', 3, 1200, 900, 'PUBLIC', ['canonical_filename' => 'movement-front.jpg']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'movement', $movement->canonicalId, 'representative', 0, 'Mặt trước bộ máy');
        $video = new Video($videoId = UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video âm thanh', [
            'public_identity' => ['current_slug' => 'video-am-thanh'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'thumbnail_urls' => ['https://img.example.test/video.jpg']],
            'editorial' => ['title' => 'Video âm thanh', 'summary' => 'Ghi nhận âm thanh liên quan.'],
            'hub' => ['primary' => 'movement'],
            'provenance' => ['kind' => 'TEST_SOURCE'],
            'semantic_attachments' => [['target_id' => $movement->canonicalId]],
        ]);

        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'variant' => $variant, 'movement' => $movement, 'music' => $music] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $endpoints->register('media', new FakeEndpointResolver('media', [$mediaId]));
        $endpoints->register('video', new FakeEndpointResolver('video', [$videoId]));
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, $predicates, new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'uses_movement', new NodeReference('movement', $movement->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'supports_music', new NodeReference('music', $music->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'about', new NodeReference('video', $videoId));

        $source = new Source(UuidCodec::newV7(), 'source-a', 'Tư liệu kỹ thuật', 'archive', 'box-1', ['visibility' => 'PUBLIC']);
        $claim1 = new KnowledgeClaim($claim1Id = UuidCodec::newV7(), 'movement-construction', 'Khác biệt nằm ở bộ thoát.', 'technical', ['metadata' => ['subject_id' => $movement->canonicalId, 'facet' => 'movement', 'scope' => 'movement']]);
        $claim2 = new KnowledgeClaim(UuidCodec::newV7(), 'movement-recognition', 'Có một dấu nhận diện đã ghi nhận.', 'fact', ['metadata' => ['subject_id' => $movement->canonicalId, 'facet' => 'recognition', 'scope' => 'movement']]);
        $evidence = new Evidence(UuidCodec::newV7(), $claim1Id, $source->canonicalId, 'supports', 'Trích đoạn hỗ trợ.', 'p.1', true, 1, ['visibility' => 'PUBLIC']);

        $mediaRepo = $this->mediaRepository([$media]);
        $assetRepo = $this->assetRepository([$asset]);
        $usageRepo = $this->usageRepository([$usage]);
        $videoRepo = $this->videoRepository([$video]);
        $routes = new PublicRouteResolver($authorityRepo, $types);
        $eligibility = new PublicEntityEligibilityPolicy($authorityRepo, $types, $routes);
        $dossier = new SemanticDossierQuery(
            $authorityRepo,
            $types,
            new PublicIdentityContract($types),
            $eligibility,
            $routes,
            new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)),
            new EntityKnowledgeProjection($this->knowledgeRepository([$claim1, $claim2]), $this->evidenceRepository([$evidence]), $this->sourceRepository([$source])),
            new EntityMediaProjection($mediaRepo, $assetRepo, $usageRepo),
            $mediaRepo,
            $videoRepo,
        );

        $result = $dossier->forEntity($movement);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('Machine 39', $result['identity']['name']);
        self::assertSame('movement', $result['profile']['identity']['type']);
        self::assertContains('music', $result['profile']['section_order']);
        self::assertSame([], $result['profile']['articles']);
        self::assertSame('/bo-may/machine-39/', $result['identity']['url']);
        self::assertSame('DIRECT', $result['relation_sections']['variants'][0]['origin']['kind'] ?? null);
        self::assertSame('DERIVED', $result['relation_sections']['models'][0]['origin']['kind'] ?? null);
        self::assertSame(2, $result['relation_sections']['models'][0]['origin']['hop_count'] ?? null);
        self::assertSame(['uses_movement', 'variant_of'], $result['relation_sections']['models'][0]['origin']['predicates'] ?? null);
        self::assertSame('DIRECT', $result['relation_sections']['music'][0]['origin']['kind'] ?? null);
        self::assertSame('https://img.example.test/video.jpg', $result['relation_sections']['videos'][0]['thumbnail_url'] ?? null);
        self::assertStringContainsString('/anh/movement-front.webp', (string) ($result['primary_media']['url'] ?? ''));
        self::assertSame(2, $result['knowledge']['claim_count']);
        self::assertSame(1, $result['knowledge']['coverage']['sourced_claim_count']);
        self::assertSame(1, $result['knowledge']['coverage']['unsourced_claim_count']);
        self::assertContains('PUBLIC_CLAIMS_WITHOUT_EVIDENCE', $result['warnings']);
        self::assertArrayNotHasKey('canonical_id', $result['identity']);
        self::assertStringNotContainsString($movement->canonicalId, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('brands', $result['relation_sections'], 'A three-hop Brand must not leak into a two-hop Movement dossier.');
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
