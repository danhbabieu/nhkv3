<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Presentation\EntityPresentationViewModel;
use PHPUnit\Framework\TestCase;

final class EntityPresentationViewModelTest extends TestCase
{
    public function test_deduplicates_related_items_and_keeps_the_strongest_direct_origin(): void
    {
        $view = EntityPresentationViewModel::fromDossier('clock_type', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Đồng hồ công cộng', 'url' => '/dong-ho-dong-ho-cong-cong/'],
            'primary_media' => ['url' => '/anh/cong-cong.webp', 'alt' => 'Đồng hồ công cộng'],
            'relation_sections' => [
                'media' => [
                    ['canonical_id' => 'media-1', 'title' => 'Ảnh direct', 'url' => '/anh/direct.webp', 'origin' => ['kind' => 'DERIVED', 'hop_count' => 2, 'predicates' => ['about', 'depicts']]],
                    ['canonical_id' => 'media-1', 'title' => 'Ảnh direct', 'url' => '/anh/direct.webp', 'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['depicts']]],
                ],
            ],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => []],
            'coverage' => ['media_count' => 1],
            'availability' => ['graph' => 'AVAILABLE'],
        ]);

        self::assertCount(1, $view['media']);
        self::assertSame('DIRECT', $view['media'][0]['relation_origin']['kind']);
        self::assertSame(1, $view['media'][0]['relation_depth']);
        self::assertSame('AVAILABLE', $view['section_status']['media']['status']);
    }

    public function test_classifies_existing_derived_paths_without_inventing_relations(): void
    {
        $view = EntityPresentationViewModel::fromDossier('brand', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Odo', 'url' => '/odo/'],
            'relation_sections' => [
                'movements' => [[
                    'canonical_id' => 'movement-1',
                    'title' => 'Máy 36',
                    'url' => '/bo-may/may-36/',
                    'origin' => [
                        'kind' => 'DERIVED',
                        'hop_count' => 2,
                        'predicates' => ['model_of', 'uses_movement'],
                        'via_types' => ['model'],
                    ],
                ]],
            ],
        ]);

        self::assertSame('DERIVED', $view['movements'][0]['relation_origin']['kind']);
        self::assertSame('DERIVED_VIA_MODEL', $view['movements'][0]['relation_origin']['path_kind']);
        self::assertSame(['model_of', 'uses_movement'], $view['movements'][0]['relation_origin']['predicates']);
    }

    public function test_exposes_section_states_and_profile_ready_status_without_mutating_semantic_state(): void
    {
        $view = EntityPresentationViewModel::fromDossier('clock_type', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Nhóm đồng hồ', 'url' => '/loai-dong-ho/nhom/'],
            'primary_media' => null,
            'relation_sections' => [],
            'knowledge' => ['status' => 'UNAVAILABLE', 'facets' => []],
            'warnings' => ['GRAPH_UNAVAILABLE'],
            'presentation_readiness' => ['status' => 'INCOMPLETE', 'reasons' => ['PRESENTATION_CONTENT_MISSING']],
        ]);

        self::assertSame('INCOMPLETE', $view['presentation_readiness']['status']);
        self::assertSame('EMPTY', $view['section_status']['media']['status']);
        self::assertSame('UNAVAILABLE', $view['section_status']['knowledge']['status']);
        self::assertSame('GRAPH_UNAVAILABLE', $view['warnings'][0]);
        self::assertArrayNotHasKey('canonical_id', $view['identity']);
    }

    public function test_derives_readiness_from_substantive_public_signals_when_projector_does_not_supply_a_flag(): void
    {
        $view = EntityPresentationViewModel::fromDossier('model', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Mẫu A', 'url' => '/mau/mau-a/'],
            'relation_sections' => [],
            'knowledge' => ['status' => 'NOT_APPLICABLE', 'facets' => []],
        ]);

        self::assertSame('INCOMPLETE', $view['presentation_readiness']['status']);

        $incomplete = EntityPresentationViewModel::fromDossier('model', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => '', 'url' => ''],
            'relation_sections' => [],
        ]);
        self::assertSame('INCOMPLETE', $incomplete['presentation_readiness']['status']);
    }

    public function test_derives_readiness_from_a_published_article_signal_without_summary_or_media(): void
    {
        $view = EntityPresentationViewModel::fromDossier('clock_type', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Đồng hồ công cộng', 'url' => '/dong-ho-cong-cong/'],
            'relation_sections' => [
                'articles' => [['title' => 'Bài viết công khai', 'url' => '/bai-viet-cong-khai/']],
            ],
            'knowledge' => ['status' => 'NOT_APPLICABLE', 'facets' => []],
        ]);

        self::assertSame('READY', $view['presentation_readiness']['status']);
    }
}
