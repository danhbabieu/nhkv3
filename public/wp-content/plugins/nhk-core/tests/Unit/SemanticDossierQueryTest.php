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
        $video = new Video($videoId = UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video âm thanh', ['source_snapshot' => ['availability' => 'available', 'thumbnail_urls' => ['https://img.example.test/video.jpg']]]);

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
}
