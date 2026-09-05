<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{EntityPageQuery, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, RelatedContentQuery};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Application\Media\{PublicMediaAssetDelivery, PublicMediaGalleryQuery};
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

final class FrontendSemanticProjectionV2Test extends TestCase
{
    public function test_canonical_entity_detail_keeps_graph_related_projection(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepo = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'maker-a', 'Maker A');
        $model = $authority->create('model', 'model-a', 'Model A', ['brand_uuid' => $brand->canonicalId]);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('model', $model->canonicalId));
        $routes = new PublicRouteResolver($authorityRepo, $types);
        $collection = new PublicEntityCollectionQuery($authorityRepo, $types, new PublicIdentityContract($types), new PublicEntityEligibilityPolicy($authorityRepo, $types, $routes), $routes);
        $related = new RelatedContentQuery($graph, $authorityRepo, $this->mediaRepository([]), $this->videoRepository([]), $types);
        $query = new EntityPageQuery($authorityRepo, $types, $related, null, $routes, $collection);

        $detail = $query->detailForEntity($brand);

        self::assertIsArray($detail);
        self::assertSame('Model A', $detail['related']['entities'][0]['title'] ?? null);
    }

    public function test_media_gallery_projects_a_real_public_image_without_inventing_a_media_detail_url(): void
    {
        $root = sys_get_temp_dir() . '/nhk-gallery-' . bin2hex(random_bytes(4));
        mkdir($root);
        $bytes = 'image-bytes'; file_put_contents($root . '/front.jpg', $bytes);
        $media = new Media($mediaId = UuidCodec::newV7(), 'front', 'Ảnh mặt trước', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'front.jpg', hash('sha256', $bytes), 'image/jpeg', strlen($bytes), 1200, 800, 'PUBLIC', ['canonical_filename' => 'front.jpg']);
        try {
            $mediaRepo = $this->mediaRepository([$media]);
            $assetRepo = $this->assetRepository([$asset]);
            $gallery = new PublicMediaGalleryQuery($mediaRepo, $assetRepo, new PublicMediaAssetDelivery($assetRepo, $mediaRepo, $root));
            $item = $gallery->archive(1, 12)['items'][0] ?? [];
            self::assertSame('Ảnh mặt trước', $item['title'] ?? null);
            self::assertStringContainsString('/anh/front.jpg', (string) ($item['image_url'] ?? ''));
            self::assertArrayNotHasKey('url', $item);
            self::assertArrayNotHasKey('media_id', $item);
        } finally {
            @unlink($root . '/front.jpg'); @rmdir($root);
        }
    }

    public function test_entity_knowledge_projection_groups_only_public_subject_scoped_claims_with_public_evidence(): void
    {
        $subjectId = UuidCodec::newV7();
        $source = new Source(UuidCodec::newV7(), 'source-a', 'Tư liệu A', 'archive', 'box-1');
        $publicClaim = new KnowledgeClaim($claimId = UuidCodec::newV7(), 'claim-a', 'Khác biệt nằm ở tỷ số truyền phía bộ thoát.', 'technical', ['metadata' => ['subject_id' => $subjectId, 'facet' => 'movement', 'scope' => 'movement']]);
        $otherClaim = new KnowledgeClaim(UuidCodec::newV7(), 'claim-b', 'Không thuộc chủ thể này.', 'fact', ['metadata' => ['subject_id' => UuidCodec::newV7(), 'facet' => 'identity', 'scope' => 'movement']]);
        $privateClaim = new KnowledgeClaim(UuidCodec::newV7(), 'claim-c', 'Chưa xác minh.', 'fact', ['metadata' => ['subject_id' => $subjectId, 'facet' => 'recognition', 'scope' => 'movement', 'verification_status' => 'UNVERIFIED']]);
        $evidence = new Evidence(UuidCodec::newV7(), $claimId, $source->canonicalId, 'supports', 'Trích đoạn hỗ trợ', 'p.1');
        $projection = new EntityKnowledgeProjection($this->knowledgeRepository([$publicClaim, $otherClaim, $privateClaim]), $this->evidenceRepository([$evidence]), $this->sourceRepository([$source]));

        $result = $projection->forSubject($subjectId);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertCount(1, $result['facets']['movement'] ?? []);
        self::assertSame('Khác biệt nằm ở tỷ số truyền phía bộ thoát.', $result['facets']['movement'][0]['text'] ?? null);
        self::assertSame('Tư liệu A', $result['facets']['movement'][0]['evidence'][0]['source_title'] ?? null);
        self::assertArrayNotHasKey('canonical_id', $result['facets']['movement'][0]);
        self::assertArrayNotHasKey('recognition', $result['facets']);
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
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
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

    private function knowledgeRepository(array $items): KnowledgeRepository
    {
        return new class($items) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
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
        };
    }

    private function sourceRepository(array $items): SourceRepository
    {
        return new class($items) implements SourceRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Source { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }
}
