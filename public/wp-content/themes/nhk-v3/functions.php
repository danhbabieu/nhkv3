<?php
declare(strict_types=1);

function nhk_v3_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Primary navigation']);
}
add_action('after_setup_theme', 'nhk_v3_setup');

function nhk_v3_assets(): void { wp_enqueue_style('nhk-v3-style', get_stylesheet_uri(), [], '1.1.0'); wp_enqueue_style('nhk-v3-entity', get_theme_file_uri('entity.css'), ['nhk-v3-style'], '1.0.0'); }
add_action('wp_enqueue_scripts', 'nhk_v3_assets');

function nhk_v3_nav_fallback(): void
{
    $items = ['Tri thức' => '/category/tri-thuc/', 'Thương hiệu' => '/brand/', 'Mẫu' => '/model/', 'Bộ máy' => '/movement/', 'Bản nhạc' => '/music/', 'So sánh' => '/comparison/', 'Linh kiện' => '/component/', 'Hiện vật' => '/specimen/', 'Video' => '/video/', 'Góc chia sẻ' => '/category/goc-chia-se/'];
    echo '<ul class="nav-list">';
    foreach ($items as $label => $path) printf('<li><a href="%s">%s</a></li>', esc_url(home_url($path)), esc_html($label));
    echo '</ul>';
}

function nhk_v3_excerpt(): string { return wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 28); }
