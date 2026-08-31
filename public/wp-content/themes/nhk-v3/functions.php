<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/class-nhk-home-page-query.php';

function nhk_v3_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Primary navigation']);
}
add_action('after_setup_theme', 'nhk_v3_setup');

function nhk_v3_assets(): void { wp_enqueue_style('nhk-v3-style', get_stylesheet_uri(), [], '1.1.0'); wp_enqueue_style('nhk-v3-entity', get_theme_file_uri('entity.css'), ['nhk-v3-style'], '1.0.0'); wp_enqueue_style('nhk-v3-media-video', get_theme_file_uri('media-video.css'), ['nhk-v3-entity'], '1.0.0'); }
add_action('wp_enqueue_scripts', 'nhk_v3_assets');

function nhk_v3_nav_fallback(): void
{
    $items = ['Tri thức' => '/tri-thuc/', 'Thương hiệu' => '/brand/', 'Mẫu' => '/model/', 'Bộ máy' => '/movement/', 'Bản nhạc' => '/music/', 'So sánh' => '/comparison/', 'Linh kiện' => '/component/', 'Hiện vật' => '/specimen/', 'Video' => '/video/', 'Góc chia sẻ' => '/goc-chia-se/'];
    echo '<ul class="nav-list">';
    foreach ($items as $label => $path) printf('<li><a href="%s">%s</a></li>', esc_url(home_url($path)), esc_html($label));
    echo '</ul>';
}

function nhk_v3_excerpt(): string { return wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 28); }

function nhk_v3_seo_head(): void
{
    if (is_admin()) return;
    $context = $GLOBALS['nhk_core_entity_context'] ?? null;
    $title = wp_get_document_title(); $description = get_bloginfo('description'); $canonical = '';
    if (is_singular('post')) { $description = nhk_v3_excerpt(); $canonical = get_permalink(); }
    if (is_array($context)) {
        if (($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)) { $entity = $context['entity']; $title = (string) $entity['name'] . ' — Đồng Hồ Nhà Kho'; $description = 'Hồ sơ canonical ' . (string) $entity['name'] . ' trong kho NHK.'; $canonical = home_url('/' . (string) $context['type'] . '/' . rawurlencode((string) $entity['stable_key']) . '/'); }
        elseif (($context['mode'] ?? '') === 'archive') { $title = 'Khám phá ' . (string) ($context['type'] ?? '') . ' — Đồng Hồ Nhà Kho'; $canonical = home_url('/' . (string) ($context['type'] ?? '') . '/'); }
    }
    if ($canonical === '') { $canonical = function_exists('wp_get_canonical_url') ? (string) wp_get_canonical_url() : home_url(add_query_arg([])); if ($canonical === '') $canonical = home_url('/'); }
    echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr(is_singular('post') ? 'article' : 'website') . '"><meta property="og:title" content="' . esc_attr($title) . '"><meta property="og:description" content="' . esc_attr(wp_strip_all_tags($description)) . '"><meta property="og:url" content="' . esc_url($canonical) . '"><meta property="og:site_name" content="Đồng Hồ Nhà Kho">' . "\n";
    if (is_singular('post') && has_post_thumbnail()) echo '<meta property="og:image" content="' . esc_url(get_the_post_thumbnail_url(null, 'large')) . '">' . "\n";
    $breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'NHK', 'item' => home_url('/')]]];
    if (is_singular('post')) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink()];
    if (is_array($context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($context['type'] ?? 'Entity'), 'item' => home_url('/' . (string) ($context['type'] ?? '') . '/')];
    echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    if (is_singular('post')) echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => get_the_title(), 'datePublished' => get_the_date('c'), 'dateModified' => get_the_modified_date('c'), 'author' => ['@type' => 'Person', 'name' => get_the_author()], 'mainEntityOfPage' => get_permalink()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}
add_action('wp_head', 'nhk_v3_seo_head', 1);
