<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\BrandDossierProjection;
use PHPUnit\Framework\TestCase;

final class BrandDossierProjectionTest extends TestCase
{
    public function test_merge_adds_deep_brand_recipe_relations_and_keeps_better_existing_paths(): void
    {
        $dossier = [
            'status' => 'AVAILABLE',
            'relation_sections' => [
                'models' => [[
                    'type' => 'model', 'title' => 'Model A', 'url' => '/maker/model-a/',
                    'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['model_of'], 'via_types' => []],
                ]],
                'variants' => [[
                    'type' => 'variant', 'title' => 'Variant A', 'url' => '/maker/model-a/variant-a/',
                    'origin' => ['kind' => 'DERIVED', 'hop_count' => 2, 'predicates' => ['model_of', 'variant_of'], 'via_types' => ['model']],
                ]],
            ],
            'knowledge' => ['claim_count' => 1],
        ];
        $aggregation = [
            'models' => [[
                'type' => 'model', 'name' => 'Model A', 'url' => '/maker/model-a/',
                'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'path' => ['model_of']],
            ]],
            'variants' => [[
                'type' => 'variant', 'name' => 'Variant A', 'url' => '/maker/model-a/variant-a/',
                'origin' => ['kind' => 'DERIVED', 'hop_count' => 2, 'path' => ['variant_of', 'model_of']],
            ]],
            'movements' => [[
                'type' => 'movement', 'name' => 'Movement A', 'url' => '/bo-may/movement-a/',
                'origin' => ['kind' => 'DERIVED', 'hop_count' => 3, 'path' => ['model_of', 'variant_of', 'uses_movement']],
            ]],
            'music' => [[
                'type' => 'music', 'name' => 'Music A', 'url' => '/ban-nhac/music-a/',
                'origin' => ['kind' => 'DERIVED', 'hop_count' => 3, 'path' => ['model_of', 'variant_of', 'configured_with_music']],
            ]],
        ];

        $result = (new BrandDossierProjection())->merge($dossier, $aggregation);

        self::assertCount(1, $result['relation_sections']['models']);
        self::assertSame(['model_of', 'variant_of'], $result['relation_sections']['variants'][0]['origin']['predicates']);
        self::assertSame(['model_of', 'variant_of', 'uses_movement'], $result['relation_sections']['movements'][0]['origin']['predicates']);
        self::assertSame(['model', 'variant'], $result['relation_sections']['movements'][0]['origin']['via_types']);
        self::assertSame(['model', 'variant'], $result['relation_sections']['music'][0]['origin']['via_types']);
        self::assertSame(['claim_count' => 1], $result['knowledge']);
        self::assertSame(4, $result['coverage']['relation_count']);
        self::assertSame($result['relation_sections'], $result['profile']['relation_sections']);
    }
}
