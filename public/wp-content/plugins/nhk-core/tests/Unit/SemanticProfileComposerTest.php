<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Entity\SemanticProfileComposer;
use PHPUnit\Framework\TestCase;

final class SemanticProfileComposerTest extends TestCase
{
    public function test_composes_reader_safe_profile_with_explicit_public_slots(): void
    {
        $profile = (new SemanticProfileComposer())->compose('brand', [
            'status' => 'AVAILABLE',
            'identity' => [
                'type' => 'brand',
                'name' => 'Maker A',
                'url' => '/maker-a/',
                'canonical_id' => 'must-not-leak',
                'stable_key' => 'must-not-leak',
            ],
            'relation_sections' => [
                'models' => [[
                    'type' => 'model',
                    'title' => 'Family A',
                    'url' => '/maker-a/family-a/',
                    'origin' => ['kind' => 'DIRECT', 'hop_count' => 1],
                ]],
            ],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => ['technical' => [['text' => 'Claim']]], 'claim_count' => 1],
            'primary_media' => ['url' => '/anh/front.webp', 'alt' => 'Mặt trước'],
            'media_gallery' => [],
            'coverage' => ['relation_count' => 1],
            'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'AVAILABLE'],
            'warnings' => [],
            'seo_projection' => ['canonical' => '/maker-a/'],
        ]);

        self::assertSame([
            'identity', 'hierarchy', 'relation_sections', 'knowledge', 'evidence_context',
            'primary_media', 'media_gallery', 'videos', 'articles', 'navigation',
            'coverage', 'availability', 'warnings', 'seo_projection', 'section_order',
        ], array_keys($profile));
        self::assertSame('Maker A', $profile['identity']['name']);
        self::assertArrayNotHasKey('canonical_id', $profile['identity']);
        self::assertArrayNotHasKey('stable_key', $profile['identity']);
        self::assertSame('AVAILABLE', $profile['availability']['graph']);
        self::assertSame([], $profile['videos']);
        self::assertSame([], $profile['articles']);
    }

    public function test_brand_section_order_exposes_every_supported_relation_group(): void
    {
        $profile = (new SemanticProfileComposer())->compose('brand', [
            'identity' => ['type' => 'brand', 'name' => 'Maker A', 'url' => '/maker-a/'],
            'relation_sections' => [
                'models' => [], 'variants' => [], 'movements' => [], 'music' => [],
                'components' => [], 'classifications' => [], 'specimens' => [], 'products' => [],
                'media' => [], 'videos' => [], 'articles' => [],
            ],
        ]);

        foreach (['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'] as $section) {
            self::assertContains($section, $profile['section_order'], 'Brand dossier section order must not hide ' . $section . '.');
        }
    }

    public function test_keeps_unavailable_dependency_distinct_from_empty_available_profile(): void
    {
        $composer = new SemanticProfileComposer();
        $empty = $composer->compose('variant', [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'variant', 'name' => 'Thin Variant', 'url' => '/thin-variant/'],
            'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'EMPTY'],
        ]);
        $unavailable = $composer->compose('variant', [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'variant', 'name' => 'Blocked Variant', 'url' => '/blocked-variant/'],
            'availability' => ['graph' => 'UNAVAILABLE', 'knowledge' => 'UNAVAILABLE'],
        ]);

        self::assertSame('EMPTY', $empty['availability']['knowledge']);
        self::assertSame('UNAVAILABLE', $unavailable['availability']['knowledge']);
        self::assertSame('UNAVAILABLE', $unavailable['availability']['graph']);
    }

    public function test_orders_sections_by_profile_type_and_deduplicates_relation_targets_with_direct_precedence(): void
    {
        $profile = (new SemanticProfileComposer())->compose('movement', [
            'identity' => ['type' => 'movement', 'name' => 'Machine 39', 'url' => '/bo-may/machine-39/'],
            'relation_sections' => [
                'models' => [
                    ['type' => 'model', 'canonical_id' => 'model-a', 'title' => 'Family A', 'origin' => ['kind' => 'DERIVED', 'hop_count' => 2]],
                    ['type' => 'model', 'canonical_id' => 'model-a', 'title' => 'Family A', 'origin' => ['kind' => 'DIRECT', 'hop_count' => 1]],
                ],
            ],
        ]);

        self::assertSame('parent_context', $profile['section_order'][1]);
        self::assertCount(1, $profile['relation_sections']['models']);
        self::assertSame('DIRECT', $profile['relation_sections']['models'][0]['origin']['kind']);
    }
}
