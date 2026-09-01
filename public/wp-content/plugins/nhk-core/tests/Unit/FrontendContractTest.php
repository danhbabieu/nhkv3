<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendContractTest extends TestCase
{
    public function test_homepage_uses_query_service_and_semantic_modules_are_not_fixture_lists(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $frontPage = (string) file_get_contents($theme . '/front-page.php');
        $query = (string) file_get_contents($theme . '/inc/class-nhk-home-page-query.php');
        self::assertStringContainsString('NHK_V3_Home_Page_Query', $frontPage);
        self::assertStringNotContainsString('new WP_Query', $frontPage);
        self::assertStringContainsString('nhk_v3_home_semantic_modules', $query);
        self::assertStringContainsString('<br> <em>mang một câu chuyện.</em>', $frontPage);
    }

    public function test_theme_seo_contract_covers_editorial_entity_media_and_video_surfaces(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        foreach (['pre_get_document_title', 'wp_get_canonical_url', 'og:title', 'BreadcrumbList', 'VideoObject', 'nhk_core_media_context', 'nhk_core_video_context'] as $contract) {
            self::assertStringContainsString($contract, $functions);
        }
        self::assertStringContainsString('PublicMediaAssetRoutes', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
        $delivery = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/PublicMediaAssetDelivery.php');
        self::assertStringContainsString('findByCanonicalId($asset->mediaId)', $delivery);
        self::assertStringContainsString('$media->readiness !== \'ready\'', $delivery);
        self::assertStringContainsString('!$media->active', $delivery);
        self::assertStringContainsString("'mode' => 'archive', 'type' => \$type", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEntityRoutes.php'));
        self::assertStringContainsString('nhk_v3_entity_label', $functions);
        self::assertStringContainsString("return 'Đồng Hồ Nhà Kho — Kho tri thức và sưu tầm';", $functions);
        self::assertStringContainsString("Khám phá bài viết, thương hiệu, mẫu đồng hồ và hiện vật trong kho tri thức NHK.", $functions);
        self::assertStringContainsString('$description = \'Khám phá \' . $label . \' trong kho tri thức NHK.\';', $functions);
        self::assertStringContainsString('if (is_front_page()) $description', $functions);
        self::assertLessThan(strpos($functions, 'if (is_array($context))'), strpos($functions, 'if (is_front_page()) $description'));
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        self::assertStringContainsString('!$media->active', $readApi);
        self::assertStringContainsString('!$video->active', $readApi);
    }

    public function test_theme_design_tokens_have_one_nhk_source(): void
    {
        $style = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/style.css');
        self::assertSame(1, substr_count($style, ':root{'));
        foreach (['--nhk-bg:', '--nhk-surface:', '--nhk-text:', '--nhk-muted:', '--nhk-border:', '--nhk-accent:', '--nhk-accent-secondary:', '--nhk-radius:', '--nhk-shadow:', '--nhk-content-width:', '--nhk-wide-width:'] as $token) {
            self::assertSame(1, substr_count($style, $token));
        }
        foreach (['--ink:', '--line:', '--paper:', '--max:'] as $legacyToken) {
            self::assertStringNotContainsString($legacyToken, $style);
        }
        self::assertStringContainsString('Version: 1.1.8', $style);
    }

    public function test_theme_accessibility_contract_has_skip_link_keyboard_menu_and_main_targets(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $header = (string) file_get_contents($theme . '/header.php');
        $style = (string) file_get_contents($theme . '/style.css');
        $functions = (string) file_get_contents($theme . '/functions.php');

        self::assertStringContainsString('class="skip-link"', $header);
        self::assertStringContainsString('href="#main-content"', $header);
        self::assertStringContainsString('aria-controls="primary-navigation"', $header);
        self::assertStringContainsString('aria-expanded="false"', $header);
        self::assertStringContainsString('id="primary-navigation"', $header);
        self::assertStringContainsString('.nav-toggle:focus-visible', $style);
        self::assertStringContainsString('display:block!important', $style);
        self::assertStringContainsString('.skip-link:focus', $style);
        self::assertStringContainsString('.entity-card-key', $style);
        self::assertStringContainsString('overflow-wrap:anywhere', $style);
        self::assertStringContainsString("get_theme_file_uri('navigation.js')", $functions);

        foreach (['front-page.php', 'index.php', 'single.php', 'entity.php', 'knowledge.php', 'media.php', 'video.php', 'comparison.php', '404.php'] as $template) {
            self::assertStringContainsString('id="main-content"', (string) file_get_contents($theme . '/' . $template), $template . ' must expose the skip-link target');
        }
    }

    public function test_theme_card_and_footer_links_use_nhk_color_tokens(): void
    {
        $style = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/style.css');
        self::assertStringContainsString('a{color:var(--nhk-accent)}', $style);
        self::assertStringContainsString('.card h3 a{color:var(--nhk-text)}', $style);
        self::assertStringContainsString('.semantic-card strong{color:var(--nhk-text)}', $style);
        self::assertStringContainsString('.entity-card h2 a,.media-card h2 a,.knowledge-card h2 a,.related-card strong{color:var(--nhk-text)}', $style);
        self::assertStringContainsString('.site-footer a{color:#e9e0d5}', $style);
        self::assertStringContainsString('.site-footer a:hover,.site-footer a:focus{color:var(--nhk-accent-secondary)}', $style);
    }

    public function test_editorial_featured_images_have_meaningful_alt_fallbacks(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        self::assertStringContainsString("'alt' => get_the_title()", (string) file_get_contents($theme . '/front-page.php'));
        self::assertStringContainsString("'alt' => get_the_title()", (string) file_get_contents($theme . '/single.php'));
        self::assertStringContainsString("'alt' => ''", (string) file_get_contents($theme . '/template-parts/article-card.php'));
    }

    public function test_theme_seo_contract_declares_archive_index_policy(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('function nhk_v3_robots(array $robots): array', $functions);
        self::assertStringContainsString("'nhk_entity_page', 'nhk_media_page', 'nhk_video_page', 'nhk_knowledge_page'", $functions);
        self::assertStringContainsString('$robots[\'noindex\'] = true', $functions);
        self::assertStringContainsString('$robots[\'index\'] = true', $functions);
        self::assertStringContainsString("add_filter('wp_robots', 'nhk_v3_robots', 20)", $functions);
        self::assertStringContainsString('nhk_v3_allow_semantic_search_pages', $functions);
        self::assertStringContainsString("add_filter('pre_handle_404', 'nhk_v3_allow_semantic_search_pages', 10, 2)", $functions);
        self::assertStringContainsString('if (is_front_page() || is_home() || is_search()) $canonical = home_url(\'/\');', $functions);
        self::assertStringContainsString("return \$term === '' ? 'Tìm kiếm — Đồng Hồ Nhà Kho'", $functions);
        self::assertStringContainsString("Kết quả tìm kiếm cho ' . \$term . ' trong kho tri thức NHK.", $functions);
    }

    public function test_admin_contract_associates_labels_with_operational_controls(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Admin/AdminPage.php');
        self::assertStringContainsString('renderMigrationLedgerSummary', $admin);
        self::assertStringContainsString('nhk_migration_ledger', $admin);
        self::assertStringContainsString('reason_code', $admin);
        self::assertStringContainsString('details_json', $admin);
        self::assertStringContainsString('$reason === \'DOMAIN_TARGETED\'', $admin);
        self::assertStringContainsString('$reason === \'UNSUPPORTED_MEDIA_REFERENCE\'', $admin);
        self::assertStringContainsString('$reason === \'RETIRED_LEGACY_GARBAGE\'', $admin);
        self::assertStringContainsString('Explicit mapping required', $admin);
        self::assertStringContainsString('Review action', $admin);
        foreach (['aria-labelledby="nhk-entity-lookup-heading"', 'for="nhk-entity-type"', 'for="nhk-entity-key"', 'for="nhk-proposal-id"', 'aria-describedby="nhk-proposal-composer-help"', 'id="nhk-source-key"', 'id="nhk-target-key"', 'for="nhk-semantic-id"', 'id="nhk-graph-endpoint-key"', 'id="nhk-video-url"', 'value="video"', 'value="knowledge"', 'value="source"', 'value="evidence"', 'aria-live="polite"'] as $contract) {
            self::assertStringContainsString($contract, $admin);
        }
        self::assertStringContainsString("echo '<option value=\"video\">video</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"knowledge\">knowledge</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"source\">source</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"evidence\">evidence</option>';", $admin);
        self::assertStringContainsString('payload.operation==="ingest"&&payload.entity_type==="video"', $admin);
    }

    public function test_mcp_wire_smoke_is_read_only_and_covers_protocol_negotiation(): void
    {
        $tool = (string) file_get_contents(dirname(__DIR__, 4) . '/../../tools/mcp-wire-smoke.php');
        self::assertStringContainsString('CORS preflight', $tool);
        self::assertStringContainsString("'initialize'", $tool);
        self::assertStringContainsString("'tools/list'", $tool);
        self::assertStringContainsString("'notifications/initialized'", $tool);
        self::assertStringContainsString('invalid Origin rejection', $tool);
        self::assertStringContainsString("'Origin: https://invalid.example'", $tool);
        self::assertStringNotContainsString("'tools/call'", $tool);
        self::assertStringNotContainsString('--apply', $tool);
    }

    public function test_search_template_uses_unified_search_query_boundary(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $index = (string) file_get_contents($theme . '/index.php');
        $query = (string) file_get_contents($theme . '/inc/class-nhk-search-page-query.php');
        self::assertStringContainsString('NHK_V3_Search_Page_Query', $index);
        self::assertStringNotContainsString('new WP_Query', $index);
        self::assertStringContainsString('nhk_v3_search_semantic_results', $query);
        self::assertStringContainsString("home_url('/knowledge/claim/'", $index);
        $searchApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/SearchApi.php');
        self::assertStringContainsString('$entity->active()', $searchApi);
        self::assertStringContainsString("'semantic_totals' => \$semanticTotals", $searchApi);
    }

    public function test_public_knowledge_read_api_exposes_reader_safe_evidence_detail(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        self::assertStringContainsString("'/knowledge/evidence/(?P<id>[0-9a-f-]{36})'", $readApi);
        self::assertStringContainsString('function evidenceRead(\\WP_REST_Request $request)', $readApi);
        self::assertStringContainsString("'nhk_evidence_not_found'", $readApi);
        self::assertStringNotContainsString("'metadata' =>", $readApi);
    }

    public function test_knowledge_template_accepts_evidence_locator_fallback_without_notices(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/knowledge.php');
        self::assertStringContainsString("\$item['locator'] ?? (\$item['source_locator'] ?? null)", $template);
        self::assertStringNotContainsString("\$item['locator'] ?:", $template);
    }

    public function test_semantic_archives_render_pagination_from_query_totals(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['media.php', 'video.php', 'knowledge.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            self::assertStringContainsString('entity-pagination', $contents, $template . ' must render archive pagination');
            self::assertStringContainsString('$pages', $contents, $template . ' must derive page count');
        }
    }

    public function test_pagination_marks_the_current_page_for_assistive_technology(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['index.php', 'entity.php', 'media.php', 'video.php', 'knowledge.php'] as $template) {
            self::assertStringContainsString("aria-current=\"page\"", (string) file_get_contents($theme . '/' . $template), $template . ' must expose the current page');
        }
    }

    public function test_pagination_wraps_without_horizontal_overflow(): void
    {
        $style = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.css');
        self::assertStringContainsString('.entity-pagination{', $style);
        self::assertStringContainsString('flex-wrap:wrap', $style);
        self::assertStringContainsString('max-width:100%', $style);
    }

    public function test_public_templates_do_not_expose_internal_domain_terms(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['front-page.php', 'comparison.php', 'knowledge.php', 'media.php', 'video.php', 'entity.php', 'single.php', 'index.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            foreach (['Authority reference', 'Knowledge claim', 'Canonical ID', 'entity Video', 'Semantic search', 'Hồ sơ canonical', 'External references', 'media canonical', 'Kho claim canonical', 'atomic claim', 'Revision', 'dữ liệu canonical', 'Trang semantic', 'external reference', 'storage_key', 'Stable key', 'Readiness', 'Usage'] as $internalTerm) {
                self::assertStringNotContainsString($internalTerm, $contents, $template . ' exposes internal term: ' . $internalTerm);
            }
        }
        self::assertStringNotContainsString('NHK editorial archive', (string) file_get_contents($theme . '/index.php'));
        self::assertStringNotContainsString('NHK discovery', (string) file_get_contents($theme . '/comparison.php'));
    }

    public function test_public_entity_payload_values_are_reader_facing(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('function nhk_v3_public_language_attributes', $functions);
        self::assertStringContainsString("add_filter('language_attributes', 'nhk_v3_public_language_attributes')", $functions);
        self::assertStringContainsString('function nhk_v3_public_type', $functions);
        self::assertStringContainsString('function nhk_v3_public_category_name', $functions);
        self::assertStringContainsString("'Uncategorized') === 0 ? 'Chưa phân loại'", $functions);
        self::assertStringContainsString('function nhk_v3_public_date', $functions);
        self::assertStringContainsString("' tháng '", $functions);
        self::assertStringContainsString('nhk_v3_public_date()', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/front-page.php'));
        self::assertStringContainsString('nhk_v3_public_date((int) get_the_modified_time', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/single.php'));
        self::assertStringContainsString('function nhk_v3_public_date', $functions);
        self::assertStringContainsString("' tháng '", $functions);
        self::assertStringContainsString('function nhk_v3_post_categories', $functions);
        self::assertStringContainsString('function nhk_v3_public_archive_title', $functions);
        self::assertStringContainsString('function nhk_v3_public_editorial_label', $functions);
        self::assertStringContainsString('nhk_v3_public_editorial_label($editorialRoute)', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/index.php'));
        self::assertStringContainsString("\$canonical = home_url('/' . \$editorialRoute . '/')", $functions);
        self::assertStringContainsString("if (is_404()) return 'Không tìm thấy trang — Đồng Hồ Nhà Kho';", $functions);
        self::assertStringContainsString("if (is_404()) {", $functions);
        self::assertStringContainsString("if (is_404() || is_search() || \$isPaginated)", $functions);
        self::assertStringContainsString('nhk_v3_public_archive_title()', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/index.php'));
        self::assertStringContainsString("is_category() || is_tag() || is_author()", $functions);
        self::assertStringContainsString("if (is_category())", $functions);
        self::assertStringContainsString('$canonical = nhk_v3_public_url($categoryUrl)', $functions);
        self::assertStringContainsString('nhk_v3_post_categories(', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/single.php'));
        self::assertStringContainsString("'wp_post' => 'bài viết'", $functions);
        self::assertStringContainsString('nhk_v3_public_type((string) ($item[\'type\'] ?? \'\'))', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.php'));
        self::assertStringContainsString('nhk_v3_public_type((string) ($item[\'type\'] ?? $group))', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/index.php'));
        self::assertStringContainsString('nhk_v3_public_type((string) ($item[\'type\'] ?? \'\'))', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/single.php'));
        self::assertStringContainsString('nhk_v3_public_type((string) $item[\'type\'])', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/front-page.php'));
        self::assertStringContainsString('nhk_v3_public_type((string) $claim[\'type\'])', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/knowledge.php'));
        self::assertStringContainsString("'catalog' => 'catalogue'", $functions);
        self::assertStringContainsString('function nhk_v3_public_value', $functions);
        self::assertStringContainsString("'canonical' => 'hồ sơ'", $functions);
        self::assertStringContainsString("'stable key' => 'mã ổn định'", $functions);
        self::assertStringContainsString("'specimen uuid' => 'Hiện vật liên kết'", $functions);
        self::assertStringContainsString("'availability' => 'Tình trạng'", $functions);
        self::assertStringContainsString("'vendor' => 'Nhà cung cấp'", $functions);
        self::assertStringContainsString("'price' => 'Giá niêm yết'", $functions);
        self::assertStringContainsString("'url' => 'Nguồn sản phẩm'", $functions);
        self::assertStringContainsString("'model uuid' => 'Mẫu liên kết'", $functions);
        self::assertStringContainsString("'brand uuid' => 'Thương hiệu liên kết'", $functions);
        self::assertStringContainsString("'serial number' => 'Số serial'", $functions);
        self::assertStringContainsString('nhk_v3_public_value($value)', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.php'));
        self::assertStringContainsString('nhk_v3_public_label((string) $key)', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.php'));
    }

    public function test_public_detail_templates_do_not_render_operational_identifiers(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['entity.php', 'media.php', 'knowledge.php', 'video.php', 'comparison.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            foreach (['Mã hồ sơ', 'Phiên bản', 'Mã video', 'entity-key', 'entity-card-key'] as $technicalLabel) {
                self::assertStringNotContainsString($technicalLabel, $contents, $template . ' renders operational identifier: ' . $technicalLabel);
            }
        }
    }

    public function test_public_related_and_external_links_fail_closed_when_url_is_missing(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['entity.php', 'single.php', 'video.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            self::assertStringNotContainsString("?? '#'", $contents, $template . ' must not render placeholder links');
            self::assertStringContainsString('nhk_v3_public_url', $contents, $template . ' must guard optional URLs');
        }
        self::assertStringContainsString("if (\$url === '') continue", (string) file_get_contents($theme . '/index.php'));
        self::assertStringContainsString('function nhk_v3_public_url', (string) file_get_contents($theme . '/functions.php'));
        self::assertStringContainsString('nhk_v3_public_url($item[\'locator\']', (string) file_get_contents($theme . '/knowledge.php'));
        self::assertStringContainsString('nhk_v3_public_url($section[\'url\']', (string) file_get_contents($theme . '/front-page.php'));
        self::assertStringContainsString('nhk_v3_public_url(get_category_link($topic))', (string) file_get_contents($theme . '/front-page.php'));
    }

    public function test_public_entity_boundaries_filter_unregistered_payload_fields(): void
    {
        foreach ([dirname(__DIR__, 2) . '/src/Application/Entity/EntityPageQuery.php', dirname(__DIR__, 2) . '/src/Infrastructure/Http/EntityApi.php'] as $file) {
            self::assertStringContainsString('array_intersect_key($entity->payload', (string) file_get_contents($file), $file . ' must allowlist public entity payload fields');
        }
    }

    public function test_public_entity_api_has_fail_closed_authority_storage_guard(): void
    {
        $entityApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/EntityApi.php');
        self::assertStringContainsString('authorityStorageReady()', $entityApi);
        self::assertStringContainsString("'nhk_storage_unavailable'", $entityApi);
        self::assertStringContainsString("['status' => 503]", $entityApi);
    }

    public function test_public_media_api_does_not_expose_asset_storage_metadata(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $assetMethod = substr($readApi, strpos($readApi, 'private function asset'), strpos($readApi, 'private function usage') - strpos($readApi, 'private function asset'));
        foreach (["'storage_key'", "'checksum'", "'visibility'", "'metadata'"] as $field) {
            self::assertStringNotContainsString($field, $assetMethod, 'public media API exposes internal asset field: ' . $field);
        }
        self::assertStringContainsString("'public_url' => '/media/asset/'", $assetMethod);
    }

    public function test_public_media_serializers_do_not_expose_lifecycle_fields(): void
    {
        foreach ([
            dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php',
            dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php',
            dirname(__DIR__, 2) . '/src/Application/Media/MediaVideoPageQuery.php',
        ] as $path) {
            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString("'readiness' => \$media->readiness", $contents, $path . ' exposes Media readiness');
            self::assertStringNotContainsString("'active' => \$media->active", $contents, $path . ' exposes Media active state');
            self::assertStringNotContainsString("'revision' => \$media->revision", $contents, $path . ' exposes Media revision');
        }
    }

    public function test_media_detail_renders_reader_safe_image_asset_url(): void
    {
        $query = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/media.php');
        self::assertStringContainsString("str_starts_with(strtolower((string) (\$asset['mime_type'] ?? '')), 'image/')", $query);
        self::assertStringContainsString("home_url((string) \$asset['public_url'])", $query);
        self::assertStringContainsString('loading="lazy"', $query);
    }

    public function test_public_media_api_does_not_expose_provenance_blob(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $mediaMethod = substr($readApi, strpos($readApi, 'private function media'), strpos($readApi, 'private function video') - strpos($readApi, 'private function media'));
        self::assertStringNotContainsString("'provenance' => \$media->provenance", $mediaMethod);
    }

    public function test_public_video_api_does_not_expose_metadata_blob(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $videoMethod = substr($readApi, strpos($readApi, 'private function video'), strpos($readApi, 'private function claim') - strpos($readApi, 'private function video'));
        self::assertStringNotContainsString("'metadata' => \$video->metadata", $videoMethod);
    }

    public function test_public_media_api_does_not_expose_usage_endpoint_keys(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $usageMethod = substr($readApi, strpos($readApi, 'private function usage'), strpos($readApi, 'private function evidence') - strpos($readApi, 'private function usage'));
        self::assertStringNotContainsString("'endpoint_type'", $usageMethod);
        self::assertStringNotContainsString("'endpoint_key'", $usageMethod);
    }

    public function test_public_knowledge_api_does_not_expose_provenance_metadata_blob(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $claimMethod = substr($readApi, strpos($readApi, 'private function claim'), strpos($readApi, 'private function source') - strpos($readApi, 'private function claim'));
        $sourceMethod = substr($readApi, strpos($readApi, 'private function source'), strpos($readApi, 'private function asset') - strpos($readApi, 'private function source'));
        $evidenceMethod = substr($readApi, strpos($readApi, 'private function evidence'), strpos($readApi, 'private function publicEvidenceByClaim') - strpos($readApi, 'private function evidence'));
        self::assertStringNotContainsString("'provenance' => \$claim->provenance", $claimMethod);
        self::assertStringNotContainsString("'metadata' => \$source->metadata", $sourceMethod);
        self::assertStringNotContainsString("'metadata' => \$evidence->metadata", $evidenceMethod);
    }

    public function test_public_knowledge_serializers_do_not_expose_lifecycle_fields(): void
    {
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        foreach ([
            "'active' => \$claim->active", "'revision' => \$claim->revision",
            "'active' => \$source->active", "'revision' => \$source->revision",
            "'active' => \$evidence->active", "'revision' => \$evidence->revision",
        ] as $field) self::assertStringNotContainsString($field, $readApi);
        $mcp = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php');
        foreach ([
            "'active' => \$claim->active", "'revision' => \$claim->revision",
            "'active' => \$source->active", "'revision' => \$source->revision",
        ] as $field) self::assertStringNotContainsString($field, $mcp);
        $publicEvidence = substr($mcp, strpos($mcp, 'private function publicEvidence'), strpos($mcp, 'private function publicEvidenceByClaim') - strpos($mcp, 'private function publicEvidence'));
        self::assertStringNotContainsString("'active' => \$evidence->active", $publicEvidence);
        self::assertStringNotContainsString("'revision' => \$evidence->revision", $publicEvidence);
        $query = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Knowledge/KnowledgePageQuery.php');
        self::assertStringNotContainsString("'revision' => \$claim->revision", $query);
        self::assertStringNotContainsString("'revision' => \$item->revision", $query);
    }

    public function test_public_search_filters_retired_media_and_video(): void
    {
        $searchApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/SearchApi.php');
        self::assertStringContainsString('fn (Media $item): bool => $item->active && $item->readiness === \'ready\' &&', $searchApi);
        self::assertStringContainsString('fn (Video $item): bool => $item->active && $item->hasValidPublicReference() &&', $searchApi);
        self::assertStringContainsString('hasValidPublicReference()', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/MediaVideoPageQuery.php'));
        $readApiSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        self::assertStringContainsString('hasValidPublicReference()', $readApiSource);
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        $mcpRead = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php');
        $videoMethod = substr($readApi, strpos($readApi, 'private function video'), strpos($readApi, 'private function claim') - strpos($readApi, 'private function video'));
        self::assertStringNotContainsString("'thumbnail_media_id' => \$video->thumbnailMediaId", $videoMethod);
        self::assertStringNotContainsString("'active' => \$video->active", $videoMethod);
        self::assertStringNotContainsString("'revision' => \$video->revision", $videoMethod);
        self::assertStringNotContainsString("'thumbnail_media_id' => \$video->thumbnailMediaId", $mcpRead);
    }

    public function test_raw_graph_rest_reads_are_admin_only(): void
    {
        $graphApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/GraphApi.php');
        self::assertStringNotContainsString("'permission_callback' => '__return_true'", $graphApi);
        self::assertStringContainsString("'permission_callback' => static fn (): bool => current_user_can('manage_options')", $graphApi);
    }

    public function test_public_semantic_search_only_indexes_allowlisted_entity_fields(): void
    {
        foreach ([
            dirname(__DIR__, 2) . '/src/Infrastructure/Http/SearchApi.php',
            dirname(__DIR__, 2) . '/src/Application/Search/SearchSemanticQuery.php',
            dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php',
        ] as $file) {
            self::assertStringContainsString('array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true))', (string) file_get_contents($file), $file . ' must avoid indexing private entity payload fields');
        }
    }

    public function test_public_media_readiness_gate_covers_all_discovery_boundaries(): void
    {
        foreach ([
            dirname(__DIR__, 2) . '/src/Application/Home/HomeSemanticQuery.php' => '!$item->active || $item->readiness !== \'ready\'',
            dirname(__DIR__, 2) . '/src/Application/Search/SearchSemanticQuery.php' => '$item->active && $item->readiness === \'ready\' &&',
            dirname(__DIR__, 2) . '/src/Application/Entity/RelatedContentQuery.php' => '$media->active && $media->readiness === \'ready\'',
            dirname(__DIR__, 2) . '/src/Application/Media/MediaVideoPageQuery.php' => '!$media->active || $media->readiness !== \'ready\'',
            dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php' => '!$media->active || $media->readiness !== \'ready\'',
            dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php' => '$media->active && $media->readiness === \'ready\' &&',
        ] as $file => $needle) {
            self::assertStringContainsString($needle, (string) file_get_contents($file), $file . ' must apply the public Media readiness boundary');
        }
    }

    public function test_public_video_reference_gate_covers_all_discovery_boundaries(): void
    {
        foreach ([
            dirname(__DIR__, 2) . '/src/Application/Home/HomeSemanticQuery.php',
            dirname(__DIR__, 2) . '/src/Application/Search/SearchSemanticQuery.php',
            dirname(__DIR__, 2) . '/src/Application/Entity/RelatedContentQuery.php',
            dirname(__DIR__, 2) . '/src/Application/Media/MediaVideoPageQuery.php',
            dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php',
            dirname(__DIR__, 2) . '/src/Application/Mcp/McpReadHandler.php',
        ] as $file) {
            self::assertStringContainsString('hasValidPublicReference()', (string) file_get_contents($file), $file . ' must apply the public Video reference boundary');
        }
    }

    public function test_post_template_uses_graph_related_query_boundary(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        self::assertStringContainsString("apply_filters('nhk_v3_post_related_content'", (string) file_get_contents($theme . '/single.php'));
        self::assertStringContainsString('function (array $value, int $postId)', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
        self::assertStringContainsString('forPost', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Entity/RelatedContentQuery.php'));
    }

    public function test_v2_archive_aliases_resolve_to_canonical_entity_types(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEntityRoutes.php');
        self::assertStringContainsString("'thuong-hieu' => 'brand'", $routes);
        self::assertStringContainsString("'hien-vat' => 'specimen'", $routes);
        self::assertStringContainsString("'am-nhac' => 'music'", $routes);
        self::assertStringContainsString('nhk_entity_alias', $routes);
    }

    public function test_v2_search_alias_preserves_query_and_uses_native_wordpress_search(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString('legacySearchRedirect', $routes);
        self::assertStringContainsString("\$_GET['q']", $routes);
        self::assertStringContainsString("add_query_arg('s', \$term, home_url('/'))", $routes);
        $routeSmoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
        self::assertStringContainsString("'/tim-kiem/?q=odo' => 301", $routeSmoke);
        self::assertStringContainsString("'/tim-kiem/?q=odo' => '/?s=odo'", $routeSmoke);
        self::assertStringContainsString("'brand-alias', 'model-alias'", $routeSmoke);
        self::assertStringContainsString('explode(\'|\', substr($argument, strlen($prefix)), 2)', $routeSmoke);
        self::assertStringContainsString('optionalRedirects[$route] = $target', $routeSmoke);
    }

    public function test_comparison_surface_uses_entity_query_and_has_a_real_discovery_route(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicComparisonRoutes.php');
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/comparison.php');
        $home = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/front-page.php');
        self::assertStringContainsString('PublicComparisonRoutes', $plugin);
        self::assertStringContainsString("comparison/?$", $routes);
        self::assertStringContainsString('ComparisonPageQuery', $plugin);
        self::assertStringContainsString('name="a"', $template);
        self::assertStringContainsString("home_url('/comparison/')", $home);
        self::assertStringContainsString("'/comparison/' => 200", (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php'));
        $routeSmoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
        self::assertStringContainsString("'movement-url', 'music-url', 'component-url', 'specimen-url', 'product-url'", $routeSmoke);
        self::assertStringContainsString("'media-url', 'video-url', 'comparison-url'", $routeSmoke);
        foreach (["'/tri-thuc/page/2/' => 200", "'/goc-chia-se/page/2/' => 200", "'/media/page/2/' => 200", "'/video/page/2/' => 200", "'/knowledge/page/2/' => 200", "'/wp-sitemap.xml' => 200", "'/feed/' => 200"] as $route) {
            self::assertStringContainsString($route, $routeSmoke, 'semantic page-two route must be in smoke coverage');
        }
        self::assertStringContainsString("'/wp-sitemap.xml' => '<sitemapindex'", $routeSmoke);
        self::assertStringContainsString("'/feed/' => '<rss'", $routeSmoke);
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('nhk_core_comparison_context', $functions);
        self::assertStringContainsString('So sánh hồ sơ — Đồng Hồ Nhà Kho', $functions);
    }
}
