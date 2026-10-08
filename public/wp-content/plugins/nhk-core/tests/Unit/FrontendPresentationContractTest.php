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

    public function test_shared_presentation_stylesheet_bounds_dictionary_gallery_images_without_entity_asset(): void
    {
        $css = $this->read('presentation.css');

        self::assertStringContainsString('.media-figure img{display:block;width:100%;height:auto;max-width:100%;object-fit:contain}', $css);
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

    public function test_global_navigation_has_the_canonical_discovery_first_order(): void
    {
        $definition = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Presentation/PublicNavigationDefinition.php');
        $header = $this->read('header.php');

        $expected = ['Sản phẩm', 'Thương hiệu', 'Loại đồng hồ', 'Từ điển', 'Tri thức'];
        $positions = array_map(static fn (string $label): int|false => strpos($definition, "'label' => '{$label}'"), $expected);
        self::assertNotContains(false, $positions);
        self::assertSame($positions, array_values($positions));
        self::assertStringContainsString("'label' => 'Hình ảnh'", $definition);
        self::assertStringContainsString("'label' => 'Video'", $definition);
        self::assertStringContainsString("'label' => 'Góc chia sẻ'", $definition);
        self::assertStringContainsString('Khám phá', $header);
        self::assertStringNotContainsString('LOẠI trên điện thoại', $header);
        self::assertStringNotContainsString('nav-type-menu', $header);
        self::assertStringNotContainsString('nav-type-menu', $this->read('style.css'));
        self::assertStringNotContainsString('nth-child(n+6)', $this->read('style.css'));
        self::assertStringNotContainsString('clockTypePresentationNav', $header);
        self::assertStringNotContainsString('<span aria-hidden="true">⌄</span>', $header);
    }

    public function test_homepage_has_four_reader_gateways_and_compact_feed_derivative(): void
    {
        $source = $this->read('front-page.php');
        $definition = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Presentation/PublicNavigationDefinition.php');
        foreach (['Sản phẩm', 'Thương hiệu', 'Loại đồng hồ', 'Từ điển', 'hero-gateways'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringContainsString("'medium'", $source);
        self::assertStringNotContainsString("wp_get_attachment_image((int) \$item['attachment_id'], 'medium_large'", $source);
        self::assertStringContainsString("'label' => 'Sản phẩm'", $definition);
    }

    public function test_entity_cards_prioritize_thumbnail_projection_and_preserve_responsive_metadata(): void
    {
        $card = $this->read('template-parts/presentation/entity-card.php');
        $archive = $this->read('entity.php');
        $functions = $this->read('functions.php');

        self::assertStringContainsString("\$item['thumbnail_url']", $functions);
        self::assertStringContainsString("\$nested['url']", $functions);
        self::assertStringContainsString("\$representative['thumbnail_url']", $functions);
        self::assertStringContainsString("\$representativeThumbnail['url']", $functions);
        self::assertStringContainsString('nhk_v3_media_presentation', $card);
        self::assertStringContainsString("['srcset']", $card);
        self::assertStringContainsString("['sizes']", $card);
        self::assertStringContainsString("'representative' => \$item['media']['representative'] ?? null", $archive);
    }

    public function test_entity_reader_guide_and_local_navigation_are_data_driven(): void
    {
        $entity = $this->read('entity.php');

        self::assertStringContainsString('$readerGuideHasContent', $entity);
        self::assertStringContainsString("'available' => \$readerGuideHasContent", $entity);
        self::assertStringContainsString('if ($readerGuideHasContent):', $entity);
        self::assertStringNotContainsString("'dinh-huong' => ['label' => 'Định hướng đọc', 'available' => true]", $entity);
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
        self::assertStringContainsString('grid-template-columns:minmax(0,1.28fr) minmax(0,.72fr)', $css);
        self::assertStringNotContainsString('height:100vh', $css);
    }

    public function test_homepage_hero_asset_is_bounded_and_cache_busted_for_live_deploy(): void
    {
        $css = $this->read('style.css');
        $functions = $this->read('functions.php');
        $manifest = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Presentation/PublicTemplateFamilyAssetManifest.php');

        self::assertStringContainsString('.hero-visual{width:min(100%,400px)', $css);
        self::assertStringContainsString('.hero-media-column{min-width:0', $css);
        self::assertStringContainsString('max-width:100%', $css);
        self::assertStringContainsString('overflow:hidden', $css);
        self::assertStringContainsString('.hero-image-frame{display:grid;place-items:center;width:100%;aspect-ratio:4/3;overflow:hidden', $css);
        self::assertStringContainsString('object-position:center center', $css);
        self::assertStringContainsString('PublicTemplateFamilyAssetManifest::dependencies()', $functions);
        self::assertStringContainsString("'nhk-v3-entity' => ['nhk-v3-style', 'nhk-v3-presentation']", $manifest);
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
        self::assertStringContainsString("'nhk-v3-presentation' => ['nhk-v3-style']", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Presentation/PublicTemplateFamilyAssetManifest.php'));
        self::assertStringContainsString("'nhk-v3-album-style'", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Presentation/PublicTemplateFamilyAssetManifest.php'));
    }

    public function test_entity_archive_intro_explains_the_reader_purpose_of_each_profile_family(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString('$archiveSummary', $source);
        self::assertStringContainsString('Mỗi hồ sơ giúp bạn hiểu', $source);
        self::assertStringContainsString('Duyệt theo loại đồng hồ', $source);
    }

    public function test_contextual_discovery_is_bounded_and_uses_canonical_navigation(): void
    {
        $functions = $this->readTheme('functions.php');
        $definition = $this->readCore('src/Application/Presentation/PublicNavigationDefinition.php');

        self::assertStringContainsString('function nhk_v3_contextual_discovery_items', $functions);
        self::assertStringContainsString('PublicNavigationDefinition::groups()', $functions);
        self::assertStringContainsString("'brand' => ['models', 'movements', 'specimens', 'media', 'videos', 'comparison']", $functions);
        self::assertStringContainsString('array_slice($selected, 0, 5)', $functions);
        $helper = substr($functions, strpos($functions, 'function nhk_v3_contextual_discovery_items'), strpos($functions, '/** @return array<string,string> */') - strpos($functions, 'function nhk_v3_contextual_discovery_items'));
        self::assertStringNotContainsString("'/thu-vien/'", $helper);
        self::assertStringContainsString("'label' => 'Góc chia sẻ'", $definition);
    }

    public function test_sidebar_and_article_rail_use_contextual_discovery_instead_of_fixed_menu_items(): void
    {
        $sidebar = $this->readTheme('sidebar.php');
        $article = $this->readTheme('single.php');

        self::assertStringContainsString('nhk_v3_contextual_discovery_items', $sidebar);
        self::assertStringNotContainsString('array_slice((array) ($nav[\'discovery\'] ?? []), 0, 2)', $sidebar);
        self::assertStringContainsString('nhk_v3_contextual_discovery_items', $article);
        self::assertStringNotContainsString("home_url('/thu-vien/')", $article);
        self::assertStringNotContainsString("home_url('/video/')", $article);
    }

    public function test_entity_contextual_discovery_is_after_main_and_mobile_rail_is_static(): void
    {
        $entity = $this->readTheme('entity.php');
        $css = $this->readTheme('entity.css');

        self::assertStringContainsString('nhk_v3_contextual_discovery_items', $entity);
        self::assertLessThan(strpos($entity, '<aside class="context-rail"'), strpos($entity, '<div class="semantic-main">'));
        self::assertStringContainsString('@media(max-width:48rem){.home-hero-v2,.entity-dossier-hero,.semantic-layout,.article-context-layout,.video-detail-layout{display:block}', $css);
        self::assertStringContainsString('.context-rail{position:static;padding-top:36px}', $css);
    }

    public function test_v8_contextual_content_modules_are_relation_backed_and_bounded(): void
    {
        $functions = $this->read('functions.php');
        $module = $this->read('template-parts/presentation/contextual-discovery.php');
        $entity = $this->read('entity.php');
        $article = $this->read('single.php');

        self::assertStringContainsString('function nhk_v3_contextual_discovery_content_modules', $functions);
        self::assertStringContainsString('array_slice($items, 0, $limit)', $functions);
        self::assertStringContainsString("'content_backed' => true", $functions);
        self::assertStringContainsString('nhk_v3_media_presentation', $module);
        self::assertStringContainsString('video-card', $module);
        self::assertStringContainsString('nhk_v3_contextual_discovery_content_modules', $entity);
        self::assertStringContainsString('nhk_v3_contextual_discovery_content_modules', $article);
        self::assertStringContainsString('$relationSections', $entity);
        self::assertStringContainsString('$relationSections', $article);
    }

    public function test_v8_archive_maps_are_context_specific_and_homepage_excludes_dedicated_branches(): void
    {
        $functions = $this->read('functions.php');
        $entity = $this->read('entity.php');
        $home = $this->read('front-page.php');

        self::assertStringContainsString("'archive_brand' =>", $functions);
        self::assertStringContainsString("'archive_movement' =>", $functions);
        self::assertNotSame(strpos($functions, "'archive_brand' =>"), strpos($functions, "'archive_movement' =>"));
        self::assertStringContainsString('$archiveContext', $entity);
        self::assertStringContainsString("archive_' . $type", $entity);
        self::assertStringContainsString('$homeDiscoveryExcluded', $home);
        self::assertStringContainsString("'media'", $home);
        self::assertStringContainsString("'videos'", $home);
    }

    public function test_v8_downstream_discovery_follows_media_video_and_comparison_primary_content(): void
    {
        $media = $this->read('media.php');
        $video = $this->read('video.php');
        $comparison = $this->read('comparison.php');

        self::assertStringContainsString('media-downstream-discovery', $media);
        self::assertStringContainsString('video-downstream-discovery', $video);
        self::assertStringContainsString('comparison-downstream-discovery', $comparison);
        self::assertStringContainsString("nhk_v3_contextual_discovery_items('media')", $media);
        self::assertStringContainsString("nhk_v3_contextual_discovery_items('video')", $video);
        self::assertStringContainsString("nhk_v3_contextual_discovery_items('comparison')", $comparison);
        self::assertStringContainsString('entity-pagination', $media);
        self::assertStringContainsString('entity-pagination', $video);
    }

    public function test_v9_homepage_excludes_real_dedicated_media_and_video_sections(): void
    {
        $home = $this->read('front-page.php');
        self::assertStringContainsString("if (\$mediaItems !== [])", $home);
        self::assertStringContainsString("if (\$videos !== [])", $home);
        self::assertStringNotContainsString("if (!empty(\$home['media']))", $home);
        self::assertStringNotContainsString("if (!empty(\$home['videos']))", $home);
    }

    public function test_v9_video_compact_variant_is_real_and_not_double_linked(): void
    {
        $card = $this->read('template-parts/presentation/video-card.php');
        $module = $this->read('template-parts/presentation/contextual-discovery.php');
        self::assertStringContainsString("\$compact = !empty(\$args['compact'])", $card);
        self::assertStringContainsString('video-card--compact', $card);
        self::assertStringContainsString("!\$compact", $card);
        self::assertStringContainsString("if (\$kind !== 'video' && \$kind !== 'media')", $module);
        self::assertStringContainsString('thumbnail_srcset', $card);
    }

    public function test_v9_compact_media_preview_preserves_responsive_projection_metadata(): void
    {
        $module = $this->read('template-parts/presentation/contextual-discovery.php');
        foreach (['$visual[\'srcset\']', '$visual[\'sizes\']', '$visual[\'width\']', '$visual[\'height\']'] as $needle) {
            self::assertStringContainsString($needle, $module);
        }
    }

    public function test_contextual_modules_have_context_copy_and_canonical_ctas_without_duplicate_navigation(): void
    {
        $functions = $this->readTheme('functions.php');
        $module = $this->readTheme('template-parts/presentation/contextual-discovery.php');

        self::assertStringContainsString("'cta_label' =>", $functions);
        self::assertStringContainsString("nhk_v3_contextual_discovery_items", $functions);
        self::assertStringContainsString("'article' => 'Liên quan đến bài viết'", $functions);
        self::assertStringContainsString("'entity' => 'Trong hồ sơ này'", $functions);
        self::assertStringContainsString('Xem tất cả', $functions);
        self::assertStringContainsString("nhk_v3_contextual_discovery_items('module'", $functions);
        self::assertStringContainsString("if (!empty(\$availableGroups[\$destination])) continue;", $functions);
        self::assertStringContainsString("\$module['content_backed']", $module);
    }

    public function test_shared_discovery_and_relation_presentation_wraps_with_gap(): void
    {
        $presentation = $this->readTheme('presentation.css');
        $comparison = $this->readTheme('comparison.php');
        $entity = $this->readTheme('entity.css');

        self::assertStringContainsString('.contextual-discovery .topic-cloud', $presentation);
        self::assertStringContainsString('align-items:center;gap:10px', $presentation);
        self::assertStringContainsString('.related-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:', $entity);
        self::assertStringContainsString('class="topic-cloud"', $comparison);
    }

    public function test_entity_card_has_explicit_no_image_state_without_placeholder_frame(): void
    {
        $card = $this->readTheme('template-parts/presentation/entity-card.php');
        $presentation = $this->readTheme('presentation.css');

        self::assertStringContainsString('is-no-image', $card);
        self::assertStringContainsString('.entity-card.is-no-image .entity-card-image{display:none}', $presentation);
        self::assertStringNotContainsString('image-placeholder', $card);
    }

    public function test_compact_media_is_one_whole_row_link_and_compact_video_titles_are_clamped(): void
    {
        $media = $this->readTheme('template-parts/presentation/media-card.php');
        $module = $this->readTheme('template-parts/presentation/contextual-discovery.php');
        $css = $this->readTheme('presentation.css');

        self::assertStringContainsString('media-card-link', $media);
        self::assertStringNotContainsString('media-card-image', $module);
        self::assertStringContainsString('-webkit-line-clamp:2', $css);
        self::assertStringNotContainsString('video-card--compact .video-card-copy strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}', $css);
    }

    public function test_mobile_compact_discovery_keeps_the_wrapper_full_width_and_sizes_media_inside_links(): void
    {
        $css = $this->readTheme('presentation.css');

        self::assertStringContainsString('.compact-discovery-item{display:grid;grid-template-columns:minmax(0,1fr);', $css);
        self::assertStringNotContainsString('.compact-discovery-item{grid-template-columns:52px minmax(0,1fr)}', $css);
        self::assertStringContainsString('.compact-discovery-media-link{display:grid;grid-template-columns:58px minmax(0,1fr);', $css);
        self::assertStringContainsString('.video-card--compact .video-card-link{display:grid;grid-template-columns:72px minmax(0,1fr);', $css);
        self::assertStringNotContainsString('.compact-discovery-thumb{display:block;width:58px;height:44px;', $css);
    }

    public function test_topic_cloud_flex_treatment_is_scoped_to_discovery_contexts(): void
    {
        $css = $this->readTheme('presentation.css');

        self::assertStringContainsString('.contextual-discovery .topic-cloud{display:flex;flex-wrap:wrap;', $css);
        self::assertStringNotContainsString('.topic-cloud,.context-box', $css);
        $home = $this->readTheme('front-page.php');
        self::assertStringContainsString('class="home-semantic-section topics-section"', $home);
        self::assertStringContainsString('class="topic-cloud"', $home);
    }

    public function test_contextual_css_uses_only_nhk_tokens_and_resets_section_headers(): void
    {
        $css = $this->readTheme('presentation.css');

        foreach (['var(--nhk-border)', 'var(--nhk-surface)', 'var(--nhk-muted)', 'var(--nhk-text)', '.contextual-discovery-module .section-head{margin:0'] as $needle) {
            self::assertStringContainsString($needle, $css);
        }
        foreach (['var(--line', 'var(--paper', 'var(--muted'] as $needle) {
            self::assertStringNotContainsString($needle, $css);
        }
    }

    public function test_homepage_and_entity_archives_place_bounded_discovery_after_primary_content(): void
    {
        $home = $this->readTheme('front-page.php');
        $entity = $this->readTheme('entity.php');

        self::assertStringContainsString("nhk_v3_contextual_discovery_items('homepage')", $home);
        self::assertStringContainsString('class="contextual-discovery archive-discovery"', $entity);
        self::assertStringContainsString('$archiveContext', $entity);
        self::assertStringContainsString('</main>', $home);
    }

    public function test_canonical_discovery_directory_keeps_all_nine_destinations(): void
    {
        $definition = $this->readCore('src/Application/Presentation/PublicNavigationDefinition.php');
        foreach (['Hình ảnh', 'Video', 'Mẫu', 'Bộ máy', 'Bản nhạc', 'Linh kiện', 'Hiện vật', 'So sánh', 'Góc chia sẻ'] as $label) {
            self::assertStringContainsString("'label' => '{$label}'", $definition);
        }
    }

    private function readTheme(string $file): string
    {
        return (string) file_get_contents($this->theme . '/' . $file);
    }

    private function readCore(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
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

    public function test_article_album_assets_follow_a_lightweight_album_feature_signal(): void
    {
        $functions = $this->readTheme('functions.php');
        self::assertStringContainsString('function nhk_v3_article_has_album_feature', $functions);
        self::assertStringContainsString("'album' => nhk_v3_article_has_album_feature()", $functions);
        self::assertStringNotContainsString("'album' => is_singular('post')", $functions);
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
            self::assertStringContainsString("get_template_part('template-parts/presentation/video-card'", $source);
        }
        self::assertStringContainsString('$orientationClass = nhk_v3_media_orientation_class', $this->read('template-parts/presentation/video-card.php'));
        self::assertStringContainsString('video-card--compact', $this->read('template-parts/presentation/video-card.php'));
        self::assertStringContainsString('.visual-frame.nhk-media--portrait', $this->read('presentation.css'));
    }

    public function test_display_fallback_asset_exists_and_is_not_a_semantic_media_writer(): void
    {
        $fallback = $this->theme . '/assets/default-archive.svg';
        self::assertFileExists($fallback);
        self::assertStringNotContainsString('MediaUsage', (string) file_get_contents($fallback));
    }

    public function test_music_dossier_is_a_generic_read_only_template_with_safe_playback_hooks(): void
    {
        $partial = $this->read('template-parts/presentation/music-dossier.php');
        $entity = $this->read('entity.php');
        $script = $this->read('music-dossier.js');
        $css = $this->read('entity.css');
        $bootstrap = $this->readCore('src/Infrastructure/Frontend/EntityDossierBootstrap.php');

        foreach (['music_dossier', 'music-score', 'music-audio', 'aria-label', 'data-start-ms', 'AVAILABLE'] as $needle) {
            self::assertStringContainsString($needle, $partial, $needle . ' missing from music dossier partial');
        }
        self::assertStringNotContainsString('Westminster', $partial);
        self::assertStringContainsString("get_template_part('template-parts/presentation/music-dossier'", $entity);
        self::assertStringContainsString('$isMusicDossier', $entity);
        self::assertStringContainsString('MusicDossierProjection', $bootstrap);
        self::assertStringContainsString('musicProjection->forEntity', $bootstrap);
        foreach (['data-music-action', 'playbackRate', 'currentTime', 'aria-current', 'pause', 'repeat', 'reset-rate'] as $needle) {
            self::assertStringContainsString($needle, $script, $needle . ' missing from music controller');
        }
        foreach (['section_order', 'foreach ($sectionOrder as $sectionKey)', "['videos']", "['articles']", 'delivery', 'Sản phẩm', 'Hiện vật', 'Cấu hình âm nhạc của biến thể', 'Bộ máy hỗ trợ giai điệu', 'Liên hệ suy ra'] as $needle) {
            self::assertStringContainsString($needle, $partial, $needle . ' missing from generic music renderer');
        }
        self::assertStringNotContainsString('$sectionLabels = [', $partial);
        self::assertStringNotContainsString('$url = $publicUrl($audio[\'url\'] ?? null)', $partial);
        self::assertStringContainsString('keydown', $script);
        self::assertStringContainsString('focus-visible', $css);
        self::assertStringContainsString('overflow-wrap:anywhere', $css);
        self::assertStringContainsString('.music-dossier', $css);
        self::assertStringNotContainsString('overflow-x:hidden', $css);
    }

    public function test_music_dossier_has_a_generic_staff_renderer_and_loopback_preview_boundary(): void
    {
        $partial = $this->read('template-parts/presentation/music-dossier.php');
        $script = $this->read('music-dossier.js');
        $preview = dirname(__DIR__, 6) . '/tools/westminster/music-preview-server.py';

        foreach (['data-music-score', 'data-score-events', 'Bản xem dạng khuông nhạc', 'data-local-preview', 'data-music-instrument', 'Toàn bộ bản trình diễn giáo dục'] as $needle) {
            self::assertStringContainsString($needle, $partial, $needle . ' missing from score presentation');
        }
        foreach (['renderScore', 'isLoopbackHost', 'data-music-score-note', 'activeSegmentEnd', 'preview-src'] as $needle) {
            self::assertStringContainsString($needle, $script, $needle . ' missing from generic music controller');
        }
        self::assertFileExists($preview);
        $previewSource = (string) file_get_contents($preview);
        foreach (['127.0.0.1', 'NON_CANONICAL_TEST_FIXTURE', 'do_POST', 'Toàn bộ bản trình diễn giáo dục'] as $needle) {
            self::assertStringContainsString($needle, $previewSource, $needle . ' missing from local preview boundary');
        }
        self::assertStringNotContainsString('wp_insert_post', $previewSource);
        self::assertStringNotContainsString('MediaAsset', $previewSource);
    }

    public function test_music_dossier_keeps_a_listening_surface_when_public_audio_is_unavailable(): void
    {
        $partial = $this->read('template-parts/presentation/music-dossier.php');

        self::assertStringContainsString('$hasMusicAudioSection', $partial);
        self::assertStringContainsString('music-listening-unavailable', $partial);
        self::assertStringContainsString('Chưa có tệp âm thanh công khai', $partial);
    }

    public function test_music_detail_precedes_dictionary_detail_for_all_music_entities(): void
    {
        $entity = $this->read('entity.php');
        self::assertStringContainsString('$detailMusicAvailable', $entity);
        self::assertStringContainsString("&& !$detailMusicAvailable", $entity);
        self::assertStringContainsString("get_template_part('template-parts/presentation/music-dossier'", $entity);
        self::assertStringContainsString('$isMusicDossier', $entity);
        self::assertStringContainsString("$type === 'music'", $entity);
        self::assertStringNotContainsString('Westminster', $entity);
        self::assertStringNotContainsString('Sonodo', $entity);
        self::assertStringNotContainsString('Ave Maria', $entity);
    }

    private function read(string $path): string
    {
        $file = $this->theme . '/' . $path;
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }
}
