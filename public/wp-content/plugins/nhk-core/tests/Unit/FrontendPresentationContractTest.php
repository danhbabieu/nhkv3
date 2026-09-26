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

    public function test_homepage_places_unified_latest_feed_after_the_compact_hero(): void
    {
        $source = $this->read('front-page.php');
        self::assertStringContainsString('class="home-latest-feed"', $source);
        self::assertStringContainsString('$home[\'latest_feed\']', $source);
        self::assertStringContainsString('latest-feed-row', $source);
        self::assertLessThan(strpos($source, 'class="featured-section"'), strpos($source, 'class="home-latest-feed"'));
        self::assertSame(1, substr_count($source, 'class="home-latest-feed"'));
    }

    public function test_homepage_latest_feed_is_bounded_and_image_led(): void
    {
        $source = $this->read('front-page.php');

        self::assertStringContainsString('array_slice($latestFeed, 0, 4)', $source);
        self::assertStringContainsString('class="latest-feed-card latest-feed-row"', $source);
        self::assertStringContainsString('wp_get_attachment_image((int) $item[\'attachment_id\']', $source);
        self::assertStringContainsString("\$item['image_srcset'] ?? \$item['srcset']", $source);
        self::assertStringContainsString("\$item['image_sizes'] ?? \$item['sizes']", $source);
        self::assertStringContainsString('latest-feed-card-link', $source);
    }

    public function test_homepage_featured_selection_remains_sticky_first_with_existing_fallbacks(): void
    {
        $query = $this->read('inc/class-nhk-home-page-query.php');

        self::assertStringContainsString('sticky_posts are the editorial selection mechanism for homepage featured content', $query);
        self::assertStringContainsString("get_option('sticky_posts')", $query);
        self::assertStringContainsString("if (\$featured === []) \$featured = \$this->posts(['posts_per_page' => 3, 'offset' => 6, 'ignore_sticky_posts' => true]);", $query);
        self::assertStringContainsString("if (\$featured === []) \$featured = array_slice(\$latest, 0, 3);", $query);
    }

    public function test_homepage_does_not_render_sidebar_or_empty_two_column_home_layout(): void
    {
        $source = $this->read('front-page.php');

        self::assertStringNotContainsString('get_sidebar()', $source);
        self::assertStringNotContainsString('class="content-layout home-layout"', $source);
        self::assertStringContainsString('$renderableHomeSections', $source);
        self::assertStringContainsString('if ($renderableHomeSections !== [])', $source);
        self::assertStringNotContainsString('class="sidebar"', $source);
    }

    public function test_homepage_compact_layout_contracts_are_responsive(): void
    {
        $css = $this->read('style.css') . $this->read('entity.css');

        self::assertStringContainsString('.home-latest-feed .latest-feed-list', $css);
        self::assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('.latest-feed-card-link', $css);
        self::assertStringContainsString('.featured-support', $css);
        self::assertStringContainsString('line-clamp:2', $css);
    }

    public function test_homepage_header_keeps_primary_and_discovery_navigation_visually_separate(): void
    {
        $css = $this->read('style.css');

        self::assertStringContainsString('.nav-primary{display:flex;align-items:center;gap:17px;min-width:0}', $css);
        self::assertStringContainsString('.nav{display:flex;align-items:center;gap:14px;min-width:0}', $css);
    }

    public function test_base_stylesheet_protects_latest_feed_from_intrinsic_image_layout(): void
    {
        $css = $this->read('style.css');

        self::assertStringContainsString('.home-latest-feed .latest-feed-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('.latest-feed-card-link{display:grid;grid-template-columns:120px minmax(0,1fr)', $css);
        self::assertStringContainsString('.latest-feed-card-image{display:block;width:100%;height:100%;object-fit:contain}', $css);
    }

    public function test_base_stylesheet_bounds_home_hero_horizontal_layout(): void
    {
        $css = $this->read('style.css');

        self::assertStringContainsString('.home-hero-v2{grid-template-columns:minmax(0,1.28fr) minmax(0,.72fr)', $css);
        self::assertStringContainsString('.hero-copy-block,.hero-media-column{min-width:0}', $css);
        self::assertStringContainsString('.hero-visual{width:min(100%,400px);max-width:100%;margin-inline:auto;overflow:hidden}', $css);
        self::assertStringContainsString('.hero-image{display:grid;grid-template-rows:minmax(0,1fr) auto;width:100%;min-width:0;margin:0}', $css);
        self::assertStringContainsString('.hero-image-frame{display:grid;place-items:center;width:100%;aspect-ratio:4/3;overflow:hidden}', $css);
        self::assertStringContainsString('.hero-image-frame img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain;object-position:center center}', $css);
        self::assertStringNotContainsString('overflow-x:hidden', $css);
    }

    public function test_homepage_prioritizes_only_the_hero_lcp_image(): void
    {
        $source = $this->read('front-page.php');
        $query = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Home/HomeSemanticQuery.php');
        self::assertStringContainsString('loading="eager" fetchpriority="high"', $source);
        self::assertStringNotContainsString('data-nhk-hero-slider', $source);
        self::assertStringNotContainsString('hero-slider-controls', $source);
        self::assertStringContainsString('select((array) $manualIds, $heroCandidates, 1, 1)', $query);
        self::assertStringContainsString("['loading' => 'lazy', 'alt' => get_the_title()]", $source);
        self::assertStringNotContainsString("['loading' => 'eager', 'fetchpriority' => 'high', 'alt' => get_the_title()]", $source);
    }

    public function test_homepage_presentation_css_keeps_only_active_hero_and_uncropped_thumbnails(): void
    {
        $source = $this->read('front-page.php');
        $css = $this->read('style.css') . $this->read('entity.css');

        self::assertStringNotContainsString('hero-slider-controls', $css);
        self::assertStringNotContainsString('hero-slider-dots', $css);
        self::assertStringNotContainsString('hero-visual-slider', $css);
        self::assertStringNotContainsString('hero-latest-', $css);
        self::assertStringNotContainsString('home-hero-with-slider', $css);
        self::assertStringContainsString('home-hero-v2', $css);
        self::assertStringContainsString('hero-copy-block', $css);
        self::assertStringContainsString('hero-media-column', $css);
        self::assertStringContainsString('.latest-feed-card-image{display:block;width:100%;height:100%;object-fit:contain}', $css);
        self::assertStringContainsString('.support-card-image img{display:block;width:100%;height:100%;object-fit:contain}', $css);
        self::assertStringNotContainsString('hero-slider-controls', $source);
        self::assertStringNotContainsString('hero-visual-slider', $source);
    }

    public function test_homepage_hero_and_video_cards_keep_responsive_and_lazy_image_contracts(): void
    {
        $home = $this->read('front-page.php');
        $video = $this->read('template-parts/presentation/video-card.php');
        self::assertStringContainsString("\$item['srcset']", $home);
        self::assertStringContainsString("\$item['sizes']", $home);
        self::assertStringContainsString('fetchpriority="high"', $home);
        self::assertStringContainsString('loading="lazy"', $home);
        self::assertStringContainsString('decoding="async"', $video);
        self::assertStringNotContainsString('fetchpriority="high"', $video);
    }

    public function test_desktop_navigation_exposes_primary_and_groups_discovery_behind_a_control(): void
    {
        $header = $this->read('header.php');
        $functions = $this->read('functions.php');

        self::assertStringContainsString('nhk_v3_render_nav_items((array) ($groups[\'primary\'] ?? []))', $functions);
        self::assertStringNotContainsString('array_merge((array) ($groups[\'primary\'] ?? []), (array) ($groups[\'discovery\'] ?? []))', $functions);
        self::assertStringContainsString('Khám phá', $functions);
        self::assertStringContainsString("\$groups['discovery']", $functions);
    }

    public function test_homepage_gallery_is_bound_to_public_asset_delivery_before_projection(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString('PublicMediaAssetDelivery::fromEnvironment($publicAssets, $publicMedia)', $plugin);
        self::assertStringContainsString('$homeGallery = new PublicMediaGalleryQuery', $plugin);
    }

    public function test_homepage_hero_uses_a_clean_fallback_and_separate_entry_controls(): void
    {
        $source = $this->read('front-page.php');

        self::assertStringContainsString('hero-entry-points', $source);
        self::assertStringContainsString('hero-empty', $source);
        self::assertStringContainsString('($item[\'image_url\'] ?? \'\')', $source);
        self::assertStringContainsString("trim((string) (\$heroMedia[0]['image_url'] ?? '')) !== ''", $source);
    }

    public function test_homepage_hero_layout_has_a_bounded_media_column_without_fixed_viewport_height(): void
    {
        $css = $this->read('style.css') . $this->read('presentation.css') . $this->read('entity.css');

        self::assertStringContainsString('home-hero-v2', $css);
        self::assertStringContainsString('aspect-ratio:4/3', $css);
        self::assertStringContainsString('grid-template-columns:minmax(0,1.28fr) minmax(300px,.72fr)', $css);
        self::assertStringNotContainsString('height:100vh', $css);
    }

    public function test_homepage_hero_asset_is_bounded_and_cache_busted_for_live_deploy(): void
    {
        $css = $this->read('entity.css');
        $functions = $this->read('functions.php');

        self::assertStringContainsString('.hero-visual{width:min(100%,400px)', $css);
        self::assertStringContainsString('.hero-media-column{min-width:0', $css);
        self::assertStringContainsString('max-width:100%', $css);
        self::assertStringContainsString('overflow:hidden', $css);
        self::assertStringContainsString('.hero-image-frame{display:grid;place-items:center;width:100%;aspect-ratio:4/3;overflow:hidden', $css);
        self::assertStringContainsString('object-position:center center', $css);
        self::assertStringContainsString("wp_register_style('nhk-v3-entity', get_theme_file_uri('entity.css'), ['nhk-v3-style'], '1.1.0')", $functions);
    }

    public function test_homepage_hero_and_video_archive_use_stable_centered_media_frames(): void
    {
        $home = $this->read('front-page.php');
        $video = $this->read('template-parts/presentation/video-card.php');
        $css = $this->read('presentation.css');

        self::assertStringContainsString('<span class="hero-image-frame">', $home);
        self::assertStringContainsString('video-card-link', $video);
        self::assertStringContainsString('.video-card-link{display:flex;flex-direction:column;height:100%', $css);
        self::assertStringContainsString('.video-poster{display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:16/9', $css);
        self::assertStringContainsString('.video-poster img{display:block;width:100%;height:100%;object-fit:contain;object-position:center center', $css);
        self::assertStringContainsString('.video-card-copy{display:flex;min-width:0;flex:1;flex-direction:column', $css);
        self::assertStringNotContainsString('.nhk-media--portrait .video-poster{aspect-ratio:4/5}', $css);
        self::assertStringNotContainsString('.nhk-media--square .video-poster{aspect-ratio:1}', $css);
        self::assertStringNotContainsString('.nhk-media--landscape .video-poster{aspect-ratio:4/3}', $css);
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
        self::assertStringContainsString("wp_register_style('nhk-v3-presentation', get_theme_file_uri('presentation.css'), ['nhk-v3-knowledge']", $this->read('functions.php'));
        self::assertStringContainsString("wp_enqueue_style('nhk-v3-album-style')", $this->read('functions.php'));
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
