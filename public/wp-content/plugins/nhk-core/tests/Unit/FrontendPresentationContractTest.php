<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendPresentationContractTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        $this->theme = dirname(__DIR__, 2) . '/../../themes/nhk-v3';
    }

    public function test_article_cards_always_have_a_display_visual(): void
    {
        $source = $this->read('template-parts/article-card.php');
        self::assertStringContainsString('default-archive.svg', $source);
        self::assertStringContainsString('card-image', $source);
        self::assertStringContainsString('has_post_thumbnail()', $source);
    }

    public function test_homepage_exposes_visual_media_video_knowledge_and_dictionary_modules(): void
    {
        $source = $this->read('front-page.php');
        $videoCard = $this->read('template-parts/presentation/video-card.php');
        foreach (['image_url', "['knowledge']", "['dictionary']", '/thu-vien/', '/video/', '/tu-dien/'] as $needle) self::assertStringContainsString($needle, $source);
        self::assertStringContainsString('thumbnail_url', $videoCard);
        self::assertStringContainsString('template-parts/presentation/video-card', $source);
        self::assertStringContainsString('Khám phá theo nhóm đồng hồ', $source);
        self::assertStringContainsString('$hubLabel', $source);
    }

    public function test_homepage_places_unified_latest_feed_inside_left_hero_column(): void
    {
        $source = $this->read('front-page.php');
        self::assertStringContainsString('class="hero-latest-feed"', $source);
        self::assertStringContainsString('$home[\'latest_feed\']', $source);
        self::assertStringContainsString('latest-feed-row', $source);
        self::assertStringContainsString('class="hero-copy-block"', $source);
        self::assertLessThan(strpos($source, 'class="hero-media-column"'), strpos($source, 'class="hero-latest-feed"'));
        self::assertSame(1, substr_count($source, 'class="hero-latest-feed"'));
        self::assertStringContainsString('id="featured-title"', $source);
        self::assertLessThan(strpos($source, 'class="featured-section"'), strpos($source, 'class="hero-latest-feed"'));
    }

    public function test_entity_detail_renders_dossier_knowledge_gallery_and_path_aware_related_content(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString("['dossier']", $source);
        self::assertStringContainsString("['knowledge']", $source);
        self::assertStringContainsString("['relation_sections']", $source);
        self::assertStringContainsString("['origin']", $source);
        self::assertStringContainsString('Liên quan trực tiếp', $source);
        self::assertStringContainsString('Mở rộng từ quan hệ nền', $source);
        self::assertStringContainsString('nhk_v3_public_dictionary_terms_for_text', $source);
        self::assertStringContainsString('Nhóm con', $source);
    }

    public function test_entity_detail_has_a_reader_first_form_and_no_empty_hierarchy_rail(): void
    {
        $source = $this->read('entity.php');
        foreach (['reader-guide', 'Đây là gì?', 'Vai trò & bối cảnh', 'Giá trị sưu tầm', 'Người sưu tầm thường xem gì?'] as $needle) self::assertStringContainsString($needle, $source);
        self::assertStringNotContainsString('class="hierarchy-rail"', $source);
        self::assertStringContainsString('array_unique', $source);
    }

    public function test_shared_presentation_css_does_not_reserve_a_removed_hierarchy_column(): void
    {
        $source = $this->read('presentation.css');
        self::assertStringNotContainsString('minmax(170px,220px) minmax(0,1fr) minmax(230px,290px)', $source);
        self::assertStringNotContainsString('minmax(160px,190px) minmax(0,1fr) minmax(210px,250px)', $source);
        self::assertStringContainsString("wp_enqueue_style('nhk-v3-presentation', get_theme_file_uri('presentation.css'), ['nhk-v3-knowledge'], '1.0.1')", $this->read('functions.php'));
    }

    public function test_entity_archive_intro_explains_the_reader_purpose_of_each_profile_family(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString('$archiveSummary', $source);
        self::assertStringContainsString('Mỗi hồ sơ giúp bạn hiểu', $source);
        self::assertStringContainsString('Duyệt theo loại đồng hồ', $source);
    }

    public function test_collector_profile_uses_collector_first_facet_order_and_keeps_makers_last(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString("\$collectorOrder = ['display_form', 'case_styles', 'dimensions', 'dating', 'movement_family', 'running_duration', 'drive_system', 'functions', 'sound', 'music', 'automata', 'night_shutoff', 'materials', 'craft_modes', 'production_scale', 'condition_guidance', 'originality_guidance', 'provenance', 'rarity', 'origin_certification'];", $source);
        self::assertGreaterThan(strpos($source, 'collector-media-video'), strpos($source, 'collector-makers'));
    }

    public function test_brand_detail_prefers_dossier_structural_sections_and_uses_legacy_aggregation_only_as_fallback(): void
    {
        $source = $this->read('entity.php');

        self::assertStringNotContainsString('if ($type === \'brand\') foreach ([\'brands\',\'models\',\'variants\',\'movements\',\'music\',\'components\',\'classifications\',\'specimens\',\'products\'] as $group) unset($relationSections[$group]);', $source);
        self::assertStringContainsString('$dossier === null && $type === \'brand\' && is_array($entity[\'aggregation\'] ?? null)', $source);
    }

    public function test_detail_bootstrap_enriches_the_existing_generic_dossier_instead_of_replacing_it(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Frontend/EntityDossierBootstrap.php');

        self::assertStringContainsString("\$value['dossier'] ?? null", $source);
        self::assertStringContainsString('clockTypeDossier->forEntity($entity, $baseDossier)', $source);
    }

    public function test_entity_relation_sections_are_not_rendered_when_all_items_lack_public_urls(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString('$renderableItems', $source);
        self::assertStringContainsString('if ($renderableItems === []) continue;', $source);
    }

    public function test_article_detail_uses_post_dossier_canonical_media_gallery_and_path_aware_relations(): void
    {
        $source = $this->read('single.php');
        self::assertStringContainsString('nhk_v3_post_dossier', $source);
        self::assertStringContainsString('nhk_v3_article_media_gallery', $source);
        self::assertStringContainsString("['relation_sections']", $source);
        self::assertStringContainsString("['origin']", $source);
        self::assertStringContainsString('default-archive.svg', $source);
        self::assertStringContainsString('nhk_v3_public_dictionary_terms_for_text', $source);
        self::assertStringContainsString('data-nhk-album', $source);
        self::assertStringContainsString('data-album-prev', $source);
        self::assertStringContainsString("['caption']", $source);
        self::assertStringContainsString('nhk_v3_article_faq', $source);
    }

    public function test_media_archive_renders_images_without_requiring_a_fake_detail_url(): void
    {
        $source = $this->read('media.php');
        self::assertStringContainsString("['image_url']", $source);
        self::assertStringContainsString("['article_url']", $source);
        self::assertStringNotContainsString("if ($itemUrl === '') continue", $source);
    }

    public function test_video_archive_is_thumbnail_led(): void
    {
        $source = $this->read('video.php');
        self::assertStringContainsString('source_thumbnail_url', $source);
    }

    public function test_video_detail_has_first_party_sections_and_keeps_youtube_as_source_only(): void
    {
        $source = $this->read('video.php');
        foreach (['video-frame', 'Tri thức liên quan', 'Nguồn tham chiếu', 'Bài viết liên quan', 'Hình ảnh liên quan', 'Liên kết nội bộ', 'Mở nguồn video'] as $needle) self::assertStringContainsString($needle, $source);
        self::assertStringContainsString('embed_url', $source);
        self::assertStringContainsString('video[\'url\']', $source);
    }

    public function test_shared_media_presentation_is_intrinsic_ratio_aware_and_safe_for_all_orientations(): void
    {
        $functions = $this->read('functions.php');
        $css = $this->read('presentation.css');
        foreach (['nhk_v3_media_orientation', 'nhk-media--portrait', 'nhk-media--landscape', 'nhk-media--square', 'nhk-media--unknown'] as $needle) {
            self::assertStringContainsString($needle, $functions . $css);
        }
        self::assertStringContainsString('object-fit:contain', $css);
        self::assertStringNotContainsString('object-fit:cover', $css);
        self::assertStringContainsString('aspect-ratio:9/16', $this->read('media-video.css'));
    }

    public function test_video_copy_removes_url_only_duplicate_and_boilerplate_summaries_without_moving_source_cta(): void
    {
        $functions = $this->read('functions.php');
        $video = $this->read('video.php');
        self::assertStringContainsString('nhk_v3_video_summary', $functions . $video);
        foreach (['^https?://', 'mời các bác xem video', 'video đồng hồ cổ'] as $needle) self::assertStringContainsString($needle, $functions);
        self::assertStringContainsString('Mở nguồn video', $video);
        self::assertStringNotContainsString('foreach ((array) ($video[\'provenance\'] ?? []) as $key => $value)', $video);
    }

    public function test_related_video_cards_share_orientation_aware_frames(): void
    {
        foreach (['entity.php', 'single.php'] as $template) {
            $source = $this->read($template);
            self::assertStringContainsString('nhk_v3_media_orientation_class', $source);
            self::assertStringContainsString('video-thumb <?php echo esc_attr($orientation); ?>', $source);
        }
        self::assertStringContainsString('.visual-frame.nhk-media--portrait', $this->read('presentation.css'));
    }

    public function test_display_fallback_asset_exists_and_is_not_a_semantic_media_writer(): void
    {
        $fallback = $this->theme . '/assets/default-archive.svg';
        self::assertFileExists($fallback);
        self::assertStringNotContainsString('MediaUsage', (string) file_get_contents($fallback));
    }

    private function read(string $path): string
    {
        $file = $this->theme . '/' . $path;
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }
}
