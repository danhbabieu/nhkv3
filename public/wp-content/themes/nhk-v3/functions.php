<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/class-nhk-home-page-query.php';
require_once __DIR__ . '/inc/class-nhk-search-page-query.php';

function nhk_v3_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Primary navigation']);
}
add_action('after_setup_theme', 'nhk_v3_setup');

function nhk_v3_public_language_attributes(string $attributes): string
{
    $localized = preg_replace('/\blang=(["\']).*?\1/i', 'lang="vi"', $attributes);
    return is_string($localized) ? $localized : $attributes . ' lang="vi"';
}
add_filter('language_attributes', 'nhk_v3_public_language_attributes');

function nhk_v3_allow_semantic_search_pages(mixed $handled, \WP_Query $query): mixed
{
    // WordPress marks a search page as 404 when its native post paginator is
    // exhausted, even when semantic repositories still have pageable results.
    return $query->is_search() ? true : $handled;
}
add_filter('pre_handle_404', 'nhk_v3_allow_semantic_search_pages', 10, 2);

function nhk_v3_assets(): void { wp_enqueue_style('nhk-v3-style', get_stylesheet_uri(), [], '1.1.8'); wp_enqueue_style('nhk-v3-entity', get_theme_file_uri('entity.css'), ['nhk-v3-style'], '1.0.2'); wp_enqueue_style('nhk-v3-media-video', get_theme_file_uri('media-video.css'), ['nhk-v3-entity'], '1.0.1'); wp_enqueue_style('nhk-v3-knowledge', get_theme_file_uri('knowledge.css'), ['nhk-v3-media-video'], '1.0.0'); wp_enqueue_script('nhk-v3-navigation', get_theme_file_uri('navigation.js'), [], '1.0.0', true); }
add_action('wp_enqueue_scripts', 'nhk_v3_assets');

function nhk_v3_nav_fallback(): void
{
    $items = ['Tri thức' => '/tri-thuc/', 'Thương hiệu' => '/brand/', 'Mẫu' => '/model/', 'Bộ máy' => '/movement/', 'Bản nhạc' => '/music/', 'So sánh' => '/comparison/', 'Linh kiện' => '/component/', 'Hiện vật' => '/specimen/', 'Video' => '/video/', 'Góc chia sẻ' => '/goc-chia-se/'];
    echo '<ul class="nav-list">';
    foreach ($items as $label => $path) printf('<li><a href="%s">%s</a></li>', esc_url(home_url($path)), esc_html($label));
    echo '</ul>';
}

function nhk_v3_excerpt(): string { return wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 28); }

function nhk_v3_entity_label(string $type): string
{
    return ['brand' => 'thương hiệu', 'model' => 'mẫu đồng hồ', 'variant' => 'biến thể', 'movement' => 'bộ máy', 'music' => 'bản nhạc', 'component' => 'linh kiện', 'classification' => 'phân loại', 'specimen' => 'hiện vật', 'product' => 'sản phẩm'][$type] ?? 'hồ sơ';
}

function nhk_v3_public_type(string $type): string
{
    return ['wp_post' => 'bài viết', 'post' => 'bài viết', 'brand' => 'thương hiệu', 'model' => 'mẫu đồng hồ', 'variant' => 'biến thể', 'movement' => 'bộ máy', 'music' => 'bản nhạc', 'component' => 'linh kiện', 'classification' => 'phân loại', 'specimen' => 'hiện vật', 'product' => 'sản phẩm', 'media' => 'hình ảnh', 'video' => 'video', 'knowledge' => 'tri thức', 'source' => 'nguồn', 'evidence' => 'bằng chứng', 'publication' => 'ấn phẩm', 'website' => 'website', 'archive' => 'lưu trữ', 'catalog' => 'catalogue', 'interview' => 'phỏng vấn', 'fact' => 'dữ kiện', 'technical' => 'kỹ thuật', 'specification' => 'thông số'][$type] ?? 'nội dung liên quan';
}

function nhk_v3_public_category_name(string $name): string
{
    return strcasecmp(trim($name), 'Uncategorized') === 0 ? 'Chưa phân loại' : $name;
}

function nhk_v3_public_date(?int $timestamp = null): string
{
    $timestamp = $timestamp ?? (int) get_post_timestamp();
    if ($timestamp <= 0) return '';
    return wp_date('j', $timestamp) . ' tháng ' . wp_date('n', $timestamp) . ', ' . wp_date('Y', $timestamp);
}

function nhk_v3_post_categories(string $separator = ', '): string
{
    $categories = get_the_category();
    if (!is_array($categories) || $categories === []) return esc_html('Bài viết');
    $links = [];
    foreach ($categories as $category) {
        $name = nhk_v3_public_category_name((string) ($category->name ?? ''));
        $url = nhk_v3_public_url(get_category_link($category));
        $links[] = $url === '' ? esc_html($name) : sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($name));
    }
    return implode(esc_html($separator), $links);
}

function nhk_v3_public_archive_title(): string
{
    if (is_category()) {
        $term = get_queried_object();
        return 'Chủ đề: ' . nhk_v3_public_category_name((string) ($term->name ?? ''));
    }
    if (is_tag()) {
        $term = get_queried_object();
        return 'Thẻ: ' . (string) ($term->name ?? '');
    }
    if (is_author()) return 'Bài viết của ' . get_the_author();
    return wp_strip_all_tags(get_the_archive_title());
}

function nhk_v3_public_editorial_label(string $route): string
{
    return ['tri-thuc' => 'Tri thức', 'goc-chia-se' => 'Góc chia sẻ'][$route] ?? '';
}

function nhk_v3_public_url(mixed $value): string
{
    if (!is_string($value)) return '';
    $url = trim($value);
    if ($url === '' || $url === '#') return '';
    if (str_starts_with($url, '/')) return esc_url_raw(home_url('/' . ltrim($url, '/')));
    $validated = wp_http_validate_url($url);
    return is_string($validated) ? $validated : '';
}

function nhk_v3_public_value(mixed $value): string
{
    $text = is_scalar($value) ? (string) $value : (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE);
    $replacements = [
        'canonical ID' => 'mã hồ sơ',
        'Brand identity' => 'danh tính thương hiệu',
        'canonical' => 'hồ sơ',
        'stable key' => 'mã ổn định',
        'external reference' => 'nguồn bên ngoài',
        'atomic claim' => 'thông tin đã kiểm chứng',
    ];
    return str_ireplace(array_keys($replacements), array_values($replacements), $text);
}

function nhk_v3_public_label(string $key): string
{
    return [
        'description' => 'Mô tả',
        'aliases' => 'Tên gọi khác',
        'brand identity' => 'Danh tính thương hiệu',
        'specimen uuid' => 'Hiện vật liên kết',
        'availability' => 'Tình trạng',
        'vendor' => 'Nhà cung cấp',
        'price' => 'Giá niêm yết',
        'url' => 'Nguồn sản phẩm',
        'model uuid' => 'Mẫu liên kết',
        'brand uuid' => 'Thương hiệu liên kết',
        'serial number' => 'Số serial',
    ][strtolower(str_replace('_', ' ', $key))] ?? ucwords(str_replace('_', ' ', $key));
}

function nhk_v3_document_title(string $title): string
{
    $entity = $GLOBALS['nhk_core_entity_context'] ?? null;
    $media = $GLOBALS['nhk_core_media_context'] ?? null;
    $video = $GLOBALS['nhk_core_video_context'] ?? null;
    $knowledge = $GLOBALS['nhk_core_knowledge_context'] ?? null;
    $comparison = $GLOBALS['nhk_core_comparison_context'] ?? null;
    if (is_array($entity) && ($entity['mode'] ?? '') === 'detail' && is_array($entity['entity'] ?? null)) return (string) $entity['entity']['name'] . ' — Đồng Hồ Nhà Kho';
    if (is_array($entity) && ($entity['mode'] ?? '') === 'archive') return 'Khám phá ' . nhk_v3_entity_label((string) ($entity['type'] ?? '')) . ' — Đồng Hồ Nhà Kho';
    if (is_array($media) && ($media['mode'] ?? '') === 'detail' && is_array($media['media'] ?? null)) return (string) ($media['media']['name'] ?? 'Media') . ' — Đồng Hồ Nhà Kho';
    if (is_array($video) && ($video['mode'] ?? '') === 'detail' && is_array($video['video'] ?? null)) return (string) (($video['video']['title'] ?? '') ?: 'Video NHK') . ' — Đồng Hồ Nhà Kho';
    if (is_array($media) && ($media['mode'] ?? '') === 'archive') return 'Hình ảnh & media — Đồng Hồ Nhà Kho';
    if (is_array($video) && ($video['mode'] ?? '') === 'archive') return 'Video — Đồng Hồ Nhà Kho';
    if (is_array($knowledge) && ($knowledge['mode'] ?? '') === 'detail' && is_array($knowledge['claim'] ?? null)) return (string) $knowledge['claim']['text'] . ' — Tri thức NHK';
    if (is_array($knowledge) && ($knowledge['mode'] ?? '') === 'archive') return 'Kho tri thức — Đồng Hồ Nhà Kho';
    if (is_array($comparison) && ($comparison['mode'] ?? '') === 'compare') return 'So sánh hồ sơ — Đồng Hồ Nhà Kho';
    if (is_search()) {
        $term = trim((string) get_search_query());
        return $term === '' ? 'Tìm kiếm — Đồng Hồ Nhà Kho' : 'Tìm kiếm: ' . $term . ' — Đồng Hồ Nhà Kho';
    }
    $editorialLabel = nhk_v3_public_editorial_label((string) get_query_var('nhk_editorial_route'));
    if ($editorialLabel !== '') return $editorialLabel . ' — Đồng Hồ Nhà Kho';
    if (is_category() || is_tag() || is_author()) return nhk_v3_public_archive_title() . ' — Đồng Hồ Nhà Kho';
    if (is_404()) return 'Không tìm thấy trang — Đồng Hồ Nhà Kho';
    if (is_front_page() || is_home()) return 'Đồng Hồ Nhà Kho — Kho tri thức và sưu tầm';
    return $title;
}
add_filter('pre_get_document_title', 'nhk_v3_document_title');

function nhk_v3_seo_head(): void
{
    if (is_admin()) return;
    $context = $GLOBALS['nhk_core_entity_context'] ?? null;
    $media_context = $GLOBALS['nhk_core_media_context'] ?? null;
    $video_context = $GLOBALS['nhk_core_video_context'] ?? null;
    $knowledge_context = $GLOBALS['nhk_core_knowledge_context'] ?? null;
    $comparison_context = $GLOBALS['nhk_core_comparison_context'] ?? null;
    $title = wp_get_document_title(); $description = get_bloginfo('description'); $canonical = '';
    if (is_404()) {
        $title = 'Không tìm thấy trang — Đồng Hồ Nhà Kho';
        $description = 'Trang bạn tìm kiếm không tồn tại hoặc đã được chuyển sang địa chỉ khác trong kho NHK.';
        $canonical = home_url('/');
    }
    if (is_front_page()) $description = 'Khám phá bài viết, thương hiệu, mẫu đồng hồ và hiện vật trong kho tri thức NHK.';
    if (is_search()) {
        $term = trim((string) get_search_query());
        $description = $term === '' ? 'Tìm kiếm trong kho tri thức NHK.' : 'Kết quả tìm kiếm cho ' . $term . ' trong kho tri thức NHK.';
    }
    $editorialRoute = (string) get_query_var('nhk_editorial_route');
    $editorialLabel = nhk_v3_public_editorial_label($editorialRoute);
    if ($editorialLabel !== '') {
        $title = $editorialLabel . ' — Đồng Hồ Nhà Kho';
        $description = 'Các bài viết trong chuyên mục ' . $editorialLabel . ' của NHK.';
        $canonical = home_url('/' . $editorialRoute . '/');
    }
    if (is_category()) {
        $term = get_queried_object();
        $label = nhk_v3_public_category_name((string) ($term->name ?? ''));
        $title = 'Chủ đề: ' . $label . ' — Đồng Hồ Nhà Kho';
        $description = 'Các bài viết thuộc chủ đề ' . $label . ' trong kho NHK.';
        $categoryUrl = get_category_link($term);
        $canonical = nhk_v3_public_url($categoryUrl);
    } elseif (is_tag()) {
        $term = get_queried_object();
        $label = (string) ($term->name ?? '');
        $title = 'Thẻ: ' . $label . ' — Đồng Hồ Nhà Kho';
        $description = 'Các bài viết gắn với thẻ ' . $label . ' trong kho NHK.';
        $tagUrl = get_tag_link($term);
        $canonical = nhk_v3_public_url($tagUrl);
    } elseif (is_author()) {
        $title = nhk_v3_public_archive_title() . ' — Đồng Hồ Nhà Kho';
        $description = 'Các bài viết của ' . get_the_author() . ' trong kho NHK.';
        $canonical = nhk_v3_public_url(get_author_posts_url(get_queried_object_id()));
    }
    if (is_singular('post')) { $description = nhk_v3_excerpt(); $canonical = get_permalink(); }
    if (is_array($context)) {
        if (($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)) { $entity = $context['entity']; $title = (string) $entity['name'] . ' — Đồng Hồ Nhà Kho'; $description = 'Hồ sơ ' . (string) $entity['name'] . ' trong kho NHK.'; $canonical = nhk_v3_public_url($entity['url'] ?? null); }
        elseif (($context['mode'] ?? '') === 'archive') { $label = nhk_v3_entity_label((string) ($context['type'] ?? '')); $title = 'Khám phá ' . $label . ' — Đồng Hồ Nhà Kho'; $description = 'Khám phá ' . $label . ' trong kho tri thức NHK.'; $canonical = home_url('/' . (string) ($context['type'] ?? '') . '/'); }
    }
    if (is_array($media_context)) {
        if (($media_context['mode'] ?? '') === 'detail' && is_array($media_context['media'] ?? null)) { $media = $media_context['media']; $title = (string) ($media['name'] ?? 'Media') . ' — Đồng Hồ Nhà Kho'; $description = 'Hồ sơ hình ảnh trong thư viện NHK.'; $canonical = home_url('/media/' . rawurlencode((string) ($media['id'] ?? '')) . '/'); }
        elseif (($media_context['mode'] ?? '') === 'archive') { $title = 'Hình ảnh & media — Đồng Hồ Nhà Kho'; $description = 'Thư viện hình ảnh của NHK.'; $canonical = home_url('/thu-vien/'); }
    }
    if (is_array($video_context)) {
        if (($video_context['mode'] ?? '') === 'detail' && is_array($video_context['video'] ?? null)) { $video = $video_context['video']; $title = (string) (($video['title'] ?? '') ?: 'Video NHK') . ' — Đồng Hồ Nhà Kho'; $description = 'Video tham chiếu từ nguồn bên ngoài trong thư viện NHK.'; $canonical = home_url('/video/' . rawurlencode((string) ($video['id'] ?? '')) . '/'); }
        elseif (($video_context['mode'] ?? '') === 'archive') { $title = 'Video — Đồng Hồ Nhà Kho'; $description = 'Các video từ nguồn bên ngoài được NHK kiểm soát.'; $canonical = home_url('/video/'); }
    }
    if (is_array($knowledge_context)) {
        if (($knowledge_context['mode'] ?? '') === 'detail' && is_array($knowledge_context['claim'] ?? null)) { $claim = $knowledge_context['claim']; $title = (string) $claim['text'] . ' — Tri thức NHK'; $description = 'Tri thức được kiểm soát trong kho NHK.'; $canonical = home_url('/knowledge/claim/' . rawurlencode((string) ($claim['id'] ?? '')) . '/'); }
        elseif (($knowledge_context['mode'] ?? '') === 'archive') { $title = 'Kho tri thức — Đồng Hồ Nhà Kho'; $description = 'Các tri thức đang hoạt động trong kho NHK.'; $canonical = home_url('/knowledge/'); }
    }
    if (is_array($comparison_context) && ($comparison_context['mode'] ?? '') === 'compare') { $title = 'So sánh hồ sơ — Đồng Hồ Nhà Kho'; $description = 'Đọc cạnh nhau các dữ kiện công khai của hai hồ sơ NHK.'; $canonical = home_url('/comparison/'); }
    if ($canonical === '') {
        if (is_front_page() || is_home() || is_search()) $canonical = home_url('/');
        else $canonical = function_exists('wp_get_canonical_url') ? (string) wp_get_canonical_url() : home_url(add_query_arg([]));
        if ($canonical === '') $canonical = home_url('/');
    }
    echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr(is_singular('post') ? 'article' : 'website') . '"><meta property="og:title" content="' . esc_attr($title) . '"><meta property="og:description" content="' . esc_attr(wp_strip_all_tags($description)) . '"><meta property="og:url" content="' . esc_url($canonical) . '"><meta property="og:site_name" content="Đồng Hồ Nhà Kho">' . "\n";
    if (is_singular('post') && has_post_thumbnail()) echo '<meta property="og:image" content="' . esc_url(get_the_post_thumbnail_url(null, 'large')) . '">' . "\n";
    $breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'NHK', 'item' => home_url('/')]]];
    if (is_singular('post')) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink()];
    if (is_array($context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => nhk_v3_entity_label((string) ($context['type'] ?? '')), 'item' => home_url('/' . (string) ($context['type'] ?? '') . '/')];
    if (is_array($media_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Thư viện media', 'item' => home_url('/thu-vien/')];
    if (is_array($video_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Video', 'item' => home_url('/video/')];
    if (is_array($knowledge_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tri thức', 'item' => home_url('/knowledge/')];
    if (is_array($comparison_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'So sánh hồ sơ', 'item' => home_url('/comparison/')];
    echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    if (is_singular('post')) echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => get_the_title(), 'datePublished' => get_the_date('c'), 'dateModified' => get_the_modified_date('c'), 'author' => ['@type' => 'Person', 'name' => get_the_author()], 'mainEntityOfPage' => get_permalink()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    if (is_array($video_context) && ($video_context['mode'] ?? '') === 'detail' && is_array($video_context['video'] ?? null)) { $video = $video_context['video']; echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => (string) (($video['title'] ?? '') ?: 'Video NHK'), 'url' => $canonical, 'embedUrl' => strtolower((string) ($video['platform'] ?? '')) === 'youtube' ? 'https://www.youtube-nocookie.com/embed/' . (string) ($video['external_id'] ?? '') : null, 'contentUrl' => (string) ($video['url'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; }
}
add_action('wp_head', 'nhk_v3_seo_head', 1);

function nhk_v3_robots(array $robots): array
{
    $pageVars = ['paged', 'page', 'nhk_entity_page', 'nhk_media_page', 'nhk_video_page', 'nhk_knowledge_page'];
    $isPaginated = false;
    foreach ($pageVars as $pageVar) {
        if ((int) get_query_var($pageVar, 1) > 1) {
            $isPaginated = true;
            break;
        }
    }
    if (is_404() || is_search() || $isPaginated) {
        unset($robots['index']);
        $robots['noindex'] = true;
    } else {
        unset($robots['noindex']);
        $robots['index'] = true;
    }
    $robots['follow'] = true;
    return $robots;
}
add_filter('wp_robots', 'nhk_v3_robots', 20);
