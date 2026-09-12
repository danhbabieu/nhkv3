<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\{BrandAggregationQuery, ClassifiedAsPolicy, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery};
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoSeoProjection, VideoSitemapProjection, VideoUrlPolicy};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ClockTypePr1GoldenRegressionTest extends TestCase
{
    public function test_profiles_share_classification_type_without_creating_clock_type_authority_type(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $definitions = array_column(CanonicalEntityTypeCatalog::definitions(), null, 'type');

        self::assertArrayHasKey('brand', $definitions);
        self::assertArrayHasKey('classification', $definitions);
        self::assertArrayNotHasKey('clock_type', $definitions);
        self::assertSame('classification', $definitions['classification']->type);

        $authority = new AuthorityService(new InMemoryAuthorityRepository(), $types);
        $clockType = $authority->create('classification', 'clock-type:bull', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $caseForm = $authority->create('classification', 'case-form:bull', 'Đồng hồ vai bò', ['family' => 'case_form']);

        self::assertNotSame($clockType->canonicalId, $caseForm->canonicalId);
        self::assertSame('clock_type', $clockType->payload['family']);
        self::assertSame('case_form', $caseForm->payload['family']);
    }

    public function test_brandless_specimen_and_product_can_be_classified_without_unknown_brand(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $specimen = $authority->create('specimen', 'specimen:bull-1', 'Hiện vật vai bò', ['serial_number' => 'BULL-1']);
        $product = $authority->create('product', 'product:bull-1', 'Listing vai bò', ['listing_title' => 'Listing']);
        $clockType = $authority->create('classification', 'clock-type:bull', 'Đồng hồ vai bò', ['family' => 'clock_type']);

        $endpoints = new EndpointTypeRegistry();
        foreach (['specimen' => $specimen, 'product' => $product, 'classification' => $clockType] as $type => $entity) {
            $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        }
        $graphRepo = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepo, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());

        $graph->create(new NodeReference('specimen', $specimen->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));
        $graph->create(new NodeReference('product', $product->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));

        self::assertCount(0, $authorityRepo->listByType('brand'));
        self::assertCount(2, $graphRepo->allEdges());
        self::assertSame('classification', $graphRepo->allEdges()[0]->target->reference->endpoint_type);
    }

    public function test_brand_only_content_remains_readable_and_empty_clock_type_is_successful_empty(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'brand:legacy', 'Brand hiện có');
        $movement = $authority->create('movement', 'movement:legacy', 'Máy hiện có');
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('movement', new FakeEndpointResolver('movement', [$movement->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('movement', $movement->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authorityRepo, $types))->forBrand($brand->canonicalId);

        self::assertSame(['Máy hiện có'], array_column($result['movements'], 'name'));
        self::assertSame([], $result['clock_types']);
        self::assertSame('DIRECT', $result['movements'][0]['origin']['kind']);
    }

    public function test_brand_and_clock_type_projection_is_derived_and_case_form_is_excluded(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'brand:odo', 'Odo');
        $model = $authority->create('model', 'model:36', 'Model 36');
        $variant = $authority->create('variant', 'variant:36-bull', 'Model 36 Vai bò');
        // This uses the current compatibility spelling; canonical underscore
        // behavior is asserted separately as a fail-closed gap below.
        $clockType = $authority->create('classification', 'clock-type:bull', 'Đồng hồ vai bò', ['family' => 'clock-type']);
        $caseForm = $authority->create('classification', 'case-form:bull', 'Dáng vai bò', ['family' => 'case_form']);

        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'variant' => $variant, 'classification' => $clockType] as $type => $entity) {
            $ids = [$entity->canonicalId];
            if ($type === 'classification') $ids[] = $caseForm->canonicalId;
            $endpoints->register($type, new FakeEndpointResolver($type, $ids));
        }
        $graphRepo = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepo, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'classified_as', new NodeReference('classification', $caseForm->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authorityRepo, $types))->forBrand($brand->canonicalId);

        self::assertSame(['Đồng hồ vai bò'], array_column($result['clock_types'], 'name'));
        self::assertSame(['model_of', 'variant_of', 'classified_as'], $result['clock_types'][0]['origin']['path']);
        self::assertNotContains('Dáng vai bò', array_column($result['clock_types'], 'name'));
        self::assertNull($graph->findEdge(new NodeReference('brand', $brand->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId)));
        self::assertNotContains('brand', array_map(static fn ($edge): string => $edge->source->reference->endpoint_type, $graphRepo->allEdges()));
    }

    public function test_canonical_underscore_family_is_not_silently_claimed_by_legacy_projection(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'brand:canonical-family', 'Brand');
        $model = $authority->create('model', 'model:canonical-family', 'Model');
        $clockType = $authority->create('classification', 'classification:canonical-family', 'Clock Type', ['family' => 'clock_type']);
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'classification' => $clockType] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));

        self::assertSame([], (new BrandAggregationQuery($graph, $authorityRepo, $types))->forBrand($brand->canonicalId)['clock_types']);
    }

    public function test_clock_type_context_does_not_rewrite_existing_video_identity_attachment_route_seo_or_sitemap(): void
    {
        $variantId = UuidCodec::newV7();
        $clockTypeId = UuidCodec::newV7();
        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'Video vai bò', [
            'public_identity' => ['current_slug' => 'video-vai-bo'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'published_at' => '2026-09-12T00:00:00Z', 'duration_seconds' => 90, 'thumbnail_urls' => ['https://img.example.test/vai-bo.jpg']],
            'editorial' => ['title' => 'Video vai bò', 'summary' => 'Tư liệu hiện vật.'],
            'seo' => ['title' => 'Video vai bò', 'description' => 'Mô tả tư liệu.'],
            'hub' => ['primary' => '08'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE'],
            'semantic_attachments' => [['target_id' => $variantId, 'target_type' => 'variant']],
            'knowledge_enrichment' => ['subject_id' => $variantId, 'subject_type' => 'variant'],
        ]);
        $beforeVideo = json_encode($video, JSON_THROW_ON_ERROR);
        $beforeMetadata = $video->metadata;
        $beforeUrl = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        $package = ['source' => ['external_video_id' => $video->externalVideoId, 'published_at' => '2026-09-12T00:00:00Z', 'duration_seconds' => 90, 'thumbnail_urls' => ['https://img.example.test/vai-bo.jpg']], 'editorial' => ['title' => 'Video vai bò', 'summary' => 'Tư liệu hiện vật.'], 'seo' => ['title' => 'Video vai bò', 'description' => 'Mô tả tư liệu.']];
        $beforeSeo = (new VideoSeoProjection())->project($package, ['path' => '/video/video-vai-bo/', 'eligible' => true, 'blockers' => []]);
        $beforeSitemap = (new VideoSitemapProjection())->project([$video], 'https://nhk.example');

        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new FakeEndpointResolver('video', [$video->canonicalId]));
        $endpoints->register('variant', new FakeEndpointResolver('variant', [$variantId]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$clockTypeId]));
        $graph = new GraphService($graphRepo = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('video', $video->canonicalId), 'about', new NodeReference('variant', $variantId));
        $graph->create(new NodeReference('variant', $variantId), 'classified_as', new NodeReference('classification', $clockTypeId));
        $related = (new RelatedSemanticQuery($graph, new PredicateTraversalPolicy(new PredicateRegistry())))->query(new NodeReference('video', $video->canonicalId), ['classification'], 2, 10);

        self::assertSame($beforeVideo, json_encode($video, JSON_THROW_ON_ERROR));
        self::assertSame($beforeMetadata, $video->metadata);
        self::assertSame($beforeUrl, (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector()));
        self::assertSame($beforeSeo, (new VideoSeoProjection())->project($package, ['path' => '/video/video-vai-bo/', 'eligible' => true, 'blockers' => []]));
        self::assertSame($beforeSitemap, (new VideoSitemapProjection())->project([$video], 'https://nhk.example'));
        self::assertSame('variant', $video->metadata['semantic_attachments'][0]['target_type']);
        self::assertSame('variant', $video->metadata['knowledge_enrichment']['subject_type']);
        self::assertSame('/video/video-vai-bo/', $beforeUrl['path']);
        self::assertSame('VideoObject', $beforeSeo['video_object']['@type']);
        self::assertSame('https://img.example.test/vai-bo.jpg', $beforeSitemap[0]['thumbnail_url']);
        self::assertSame('DERIVED', array_values(array_filter($related['items'], static fn (array $item): bool => $item['target_entity_id'] === $clockTypeId))[0]['relationship_class']);
        self::assertNull($graph->findEdge(new NodeReference('video', $video->canonicalId), 'about', new NodeReference('classification', $clockTypeId)));
        self::assertCount(2, $graphRepo->allEdges());
    }
}
