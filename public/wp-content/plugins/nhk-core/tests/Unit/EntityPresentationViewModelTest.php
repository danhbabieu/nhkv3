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

    public function test_builds_a_reader_guide_in_a_stable_public_order_from_existing_claims(): void
    {
        $view = EntityPresentationViewModel::fromDossier('clock_type', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Đồng hồ công cộng', 'url' => '/dong-ho-cong-cong/'],
            'knowledge' => [
                'status' => 'AVAILABLE',
                'facets' => [
                    'identity' => [['text' => 'Hệ thống công bố thời gian trong không gian chung.', 'canonical_id' => 'claim-1']],
                    'domestic_cultural' => [['text' => 'Phục vụ đời sống đô thị và cộng đồng.', 'canonical_id' => 'claim-2']],
                    'rarity_frequency' => [['text' => 'Mức độ gặp cần được đối chiếu theo từng hiện vật.', 'canonical_id' => 'claim-3']],
                ],
            ],
            'collector_profile' => [
                'status' => 'available',
                'facets' => [
                    'display_form' => [['text' => 'Lắp đặt trong không gian chung.', 'canonical_id' => 'claim-4']],
                    'movement_family' => [['text' => 'Có thể có bộ máy cơ khí lớn.', 'canonical_id' => 'claim-5']],
                    'provenance' => [['text' => 'Nguồn gốc lắp đặt là điểm cần tra cứu.', 'canonical_id' => 'claim-6']],
                ],
            ],
        ]);

        self::assertSame(['definition', 'context', 'collector_value', 'collector_focus'], array_keys($view['reader_guide']));
        self::assertSame('Hệ thống công bố thời gian trong không gian chung.', $view['reader_guide']['definition']['items'][0]['text']);
        self::assertSame('Phục vụ đời sống đô thị và cộng đồng.', $view['reader_guide']['context']['items'][0]['text']);
        self::assertSame('Nguồn gốc lắp đặt là điểm cần tra cứu.', $view['reader_guide']['collector_value']['items'][0]['text']);
        self::assertSame('Lắp đặt trong không gian chung.', $view['reader_guide']['collector_focus']['items'][0]['text']);
        self::assertArrayNotHasKey('canonical_id', $view['reader_guide']['definition']['items'][0]);
    }

    public function test_marks_reader_guide_groups_empty_without_inventing_content(): void
    {
        $view = EntityPresentationViewModel::fromDossier('classification', [
            'status' => 'AVAILABLE',
            'identity' => ['name' => 'Một phân loại', 'url' => '/phan-loai/mot-phan-loai/'],
            'knowledge' => ['status' => 'UNAVAILABLE', 'facets' => []],
            'collector_profile' => ['status' => 'unavailable', 'facets' => []],
        ]);

        foreach ($view['reader_guide'] as $group) {
            self::assertSame('EMPTY', $group['status']);
            self::assertSame([], $group['items']);
        }
    }

    public function test_enriches_an_existing_view_with_collector_facets_after_dossier_composition(): void
    {
        $view = EntityPresentationViewModel::withCollectorProfile(
            ['reader_guide' => [
                'definition' => ['status' => 'AVAILABLE', 'items' => [['text' => 'Định nghĩa']]],
                'context' => ['status' => 'EMPTY', 'items' => []],
                'collector_value' => ['status' => 'EMPTY', 'items' => []],
                'collector_focus' => ['status' => 'EMPTY', 'items' => []],
            ]],
            ['facets' => ['rarity' => [['text' => 'Hiếm']], 'case_styles' => [['text' => 'Vỏ vuông']]]],
        );

        self::assertSame('Hiếm', $view['reader_guide']['collector_value']['items'][0]['text']);
        self::assertSame('Vỏ vuông', $view['reader_guide']['collector_focus']['items'][0]['text']);
    }
}
