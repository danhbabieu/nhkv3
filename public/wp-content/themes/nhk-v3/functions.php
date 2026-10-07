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

function nhk_v3_assets(): void
{
    wp_register_style('nhk-v3-style', get_stylesheet_uri(), [], '1.3.5');
    $dependencies = class_exists('NHK\\Core\\Application\\Presentation\\PublicTemplateFamilyAssetManifest')
        ? \NHK\Core\Application\Presentation\PublicTemplateFamilyAssetManifest::dependencies()
        : ['nhk-v3-presentation' => ['nhk-v3-style']];
    wp_register_style('nhk-v3-presentation', get_theme_file_uri('presentation.css'), $dependencies['nhk-v3-presentation'] ?? ['nhk-v3-style'], '1.0.4');
    wp_register_style('nhk-v3-entity', get_theme_file_uri('entity.css'), $dependencies['nhk-v3-entity'] ?? ['nhk-v3-style'], '1.1.0');
    wp_register_style('nhk-v3-media-video', get_theme_file_uri('media-video.css'), $dependencies['nhk-v3-media-video'] ?? ['nhk-v3-style'], '1.0.3');
    wp_register_style('nhk-v3-knowledge', get_theme_file_uri('knowledge.css'), $dependencies['nhk-v3-knowledge'] ?? ['nhk-v3-style'], '1.0.2');
    wp_register_style('nhk-v3-album-style', get_theme_file_uri('album.css'), $dependencies['nhk-v3-album-style'] ?? ['nhk-v3-style'], '1.0.2');
    wp_register_style('nhk-v3-dictionary', get_theme_file_uri('dictionary.css'), $dependencies['nhk-v3-dictionary'] ?? ['nhk-v3-style'], '1.0.0');
    wp_register_style('nhk-v3-comparison', get_theme_file_uri('comparison.css'), $dependencies['nhk-v3-comparison'] ?? ['nhk-v3-style'], '1.0.0');

    $dictionaryContext = $GLOBALS['nhk_core_dictionary_context'] ?? null;
    $entityContext = $GLOBALS['nhk_core_entity_context'] ?? null;
    $videoContext = $GLOBALS['nhk_core_video_context'] ?? null;
    $mediaContext = $GLOBALS['nhk_core_media_context'] ?? null;
    $knowledgeContext = $GLOBALS['nhk_core_knowledge_context'] ?? null;
    $comparisonContext = $GLOBALS['nhk_core_comparison_context'] ?? null;
    $family = 'base'; $mode = '';
    if (is_array($comparisonContext)) $family = 'comparison';
    elseif (is_array($dictionaryContext) || (is_array($entityContext) && is_array($entityContext['entity']['dictionary_detail'] ?? null))) $family = 'dictionary';
    elseif (is_array($videoContext)) { $family = 'video'; $mode = (string) ($videoContext['mode'] ?? ''); }
    elseif (is_array($mediaContext)) $family = 'media';
    elseif (is_array($knowledgeContext)) $family = 'knowledge';
    elseif (is_array($entityContext) || (int) get_query_var('nhk_entity_page', 0) > 0) $family = 'entity';
    elseif (is_singular('post')) $family = 'article';
    elseif (is_search()) $family = 'search';
    elseif (is_front_page()) $family = 'homepage';
    $assetContext = ['family' => $family, 'mode' => $mode, 'album' => is_singular('post')];
    $manifest = class_exists('NHK\\Core\\Application\\Presentation\\PublicTemplateFamilyAssetManifest')
        ? \NHK\Core\Application\Presentation\PublicTemplateFamilyAssetManifest::forContext($assetContext)
        : ['styles' => ['nhk-v3-style'], 'scripts' => ['nhk-v3-navigation']];
    foreach ($manifest['styles'] as $handle) wp_enqueue_style($handle);
    foreach ($manifest['scripts'] as $handle) {
        $src = match ($handle) {
            'nhk-v3-navigation' => 'navigation.js',
            'nhk-v3-album' => 'album.js',
            'nhk-v3-video-player' => 'video-player.js',
            default => '',
        };
        if ($src !== '') wp_enqueue_script($handle, get_theme_file_uri($src), [], '1.1.1', true);
    }
}
add_action('wp_enqueue_scripts', 'nhk_v3_assets');

function nhk_v3_navigation_groups(): array
{
    // Nhóm đồng hồ remains a profile-driven public entry point at /loai-dong-ho/.
    if (class_exists('NHK\\Core\\Application\\Presentation\\PublicNavigationDefinition')) {
        $groups = \NHK\Core\Application\Presentation\PublicNavigationDefinition::groups();
        if (is_array($groups) && $groups !== []) return $groups;
    }
    return ['primary' => [], 'discovery' => [], 'footer' => []];
}

/**
 * Select a small contextual discovery subset from the canonical directory.
 * Relation keys only gate content-backed destinations; links without a
 * projection remain honest navigation suggestions.
 *
 * @param array<string,mixed> $availableGroups
 * @return list<array{label:string,path:string}>
 */
function nhk_v3_contextual_discovery_items(string $context, array $availableGroups = []): array
{
    $maps = [
        'brand' => ['models', 'movements', 'specimens', 'media', 'videos', 'comparison'],
        'model' => ['movements', 'music', 'components', 'specimens', 'media', 'videos', 'comparison'],
        'movement' => ['models', 'components', 'specimens', 'media', 'videos'],
        'music' => ['videos', 'models', 'movements', 'media'],
        'component' => ['movements', 'models', 'specimens', 'media'],
        'specimen' => ['media', 'videos', 'models', 'movements', 'comparison'],
        'clock_type' => ['models', 'specimens', 'media', 'videos', 'comparison'],
        'article' => ['media', 'videos', 'models', 'movements', 'share'],
        'media' => ['specimens', 'models', 'videos', 'share'],
        'video' => ['media', 'specimens', 'models', 'share'],
        'archive' => ['media', 'videos', 'models'],
        'archive_brand' => ['models', 'movements', 'specimens', 'media', 'comparison'],
        'archive_model' => ['movements', 'music', 'components', 'specimens', 'media'],
        'archive_movement' => ['models', 'components', 'specimens', 'media', 'videos'],
        'archive_music' => ['videos', 'models', 'movements', 'media'],
        'archive_component' => ['movements', 'models', 'specimens', 'media'],
        'archive_specimen' => ['media', 'videos', 'models', 'movements'],
        'archive_clock_type' => ['models', 'specimens', 'media', 'videos'],
        'archive_product' => ['specimens', 'models', 'media', 'comparison'],
        'comparison' => ['models', 'movements', 'specimens', 'media'],
        'search' => ['media', 'videos', 'share'],
        'homepage' => ['media', 'videos', 'models', 'movements', 'comparison', 'share'],
        'sidebar' => ['media', 'videos', 'models', 'movements'],
    ];
    $labelByDestination = [
        'models' => 'Mẫu', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc',
        'components' => 'Linh kiện', 'specimens' => 'Hiện vật', 'media' => 'Hình ảnh',
        'videos' => 'Video', 'comparison' => 'So sánh', 'share' => 'Góc chia sẻ',
    ];
    $groups = nhk_v3_navigation_groups();
    $canonical = [];
    foreach ((array) ($groups['discovery'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $label = trim((string) ($item['label'] ?? ''));
        $path = trim((string) ($item['path'] ?? ''));
        if ($label !== '' && $path !== '') $canonical[$label] = ['label' => $label, 'path' => $path];
    }
    if ($context === 'module') {
        foreach ($availableGroups as $destination => $available) {
            if (!$available) continue;
            $label = $labelByDestination[(string) $destination] ?? '';
            if ($label !== '' && isset($canonical[$label])) return [$canonical[$label]];
        }
        return [];
    }
    $selected = [];
    foreach ($maps[$context] ?? $maps['sidebar'] as $destination) {
        $label = $labelByDestination[$destination] ?? '';
        if ($label === '' || !isset($canonical[$label])) continue;
        if (!empty($availableGroups[$destination])) continue;
        $selected[] = $canonical[$label];
    }
    $seen = [];
    $selected = array_values(array_filter($selected, static function (array $item) use (&$seen): bool {
        $path = $item['path'];
        if (isset($seen[$path])) return false;
        $seen[$path] = true;
        return true;
    }));
    return array_slice($selected, 0, 5);
}

function nhk_v3_contextual_discovery_copy(string $context): string
{
    $copy = ['article' => 'Liên quan đến bài viết', 'entity' => 'Trong hồ sơ này'];
    if ($context === 'entity') return 'Liên quan';
    return $copy[$context] ?? $copy['entity'];
}

/** @param array<string,mixed> $relationSections @return list<array<string,mixed>> */
function nhk_v3_contextual_discovery_content_modules(array $relationSections, string $context = ''): array
{
    $order = $context === 'article' ? ['media', 'videos', 'models', 'movements', 'articles'] : ['models', 'movements', 'music', 'components', 'specimens', 'media', 'videos', 'articles'];
    $labels = ['models' => 'Mẫu liên quan', 'movements' => 'Bộ máy liên quan', 'music' => 'Bản nhạc liên quan', 'components' => 'Linh kiện liên quan', 'specimens' => 'Hiện vật liên quan', 'media' => 'Hình ảnh liên quan', 'videos' => 'Video liên quan', 'articles' => 'Bài viết liên quan'];
    $modules = [];
    foreach ($order as $group) {
        $items = is_array($relationSections[$group] ?? null) ? $relationSections[$group] : [];
        $items = array_values(array_filter($items, static function ($item) use ($group): bool {
            if (!is_array($item)) return false;
            if ($group === 'media') return nhk_v3_media_presentation($item)['url'] !== '' && nhk_v3_media_content_url($item) !== '';
            return trim((string) ($item['title'] ?? $item['name'] ?? '')) !== '' && nhk_v3_public_url($item['url'] ?? null) !== '';
        }));
        if ($items === []) continue;
        $kind = $group === 'media' ? 'media' : ($group === 'videos' ? 'video' : ($group === 'articles' ? 'article' : 'entity'));
        $limit = $kind === 'video' ? 2 : ($kind === 'media' ? 3 : 2);
        $cta = nhk_v3_contextual_discovery_items('module', [$group => true]);
        $ctaLabel = $group === 'media' ? 'Mở thư viện' : ($group === 'videos' ? 'Xem Video' : 'Xem tất cả ' . ($labels[$group] ?? 'liên quan'));
        $modules[] = ['kind' => $kind, 'group' => $group, 'title' => $labels[$group] ?? 'Liên quan', 'items' => array_slice($items, 0, $limit), 'content_backed' => true, 'cta_label' => $ctaLabel, 'cta' => $cta[0] ?? null];
        if (count($modules) >= 3) break;
    }
    return $modules;
}

/** @return array<string,string> */
function nhk_v3_navigation_items(): array
{
    $items = [];
    foreach (['primary', 'discovery'] as $group) {
        foreach ((array) (nhk_v3_navigation_groups()[$group] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['label'] ?? '')) === '' || trim((string) ($item['path'] ?? '')) === '') continue;
            $items[(string) $item['label']] = (string) $item['path'];
        }
    }
    return $items;
}

/** @return list<array<string,mixed>> */
function nhk_v3_clock_type_navigation_items(string $placement): array
{
    $items = apply_filters('nhk_v3_clock_type_navigation_items', [], $placement);
    return is_array($items) ? array_values(array_filter($items, static fn (mixed $item): bool => is_array($item) && trim((string) ($item['label'] ?? '')) !== '' && trim((string) ($item['path'] ?? '')) !== '')) : [];
}

/** @param list<array{label:string,path:string}> $items */
function nhk_v3_render_nav_items(array $items): void
{
    $requestPath = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    echo '<ul class="nav-list">';
    foreach ($items as $item) {
        $label = (string) ($item['label'] ?? '');
        $path = (string) ($item['path'] ?? '');
        if ($label === '' || $path === '') continue;
        $isCurrent = rtrim($requestPath, '/') === rtrim($path, '/') || ($path === '/' && $requestPath === '/');
        printf('<li%s><a href="%s"%s>%s</a></li>', $isCurrent ? ' class="current-menu-item"' : '', esc_url(home_url($path)), $isCurrent ? ' aria-current="page"' : '', esc_html($label));
    }
    echo '</ul>';
}

function nhk_v3_nav_fallback(): void
{
    $groups = nhk_v3_navigation_groups();
    nhk_v3_render_nav_items((array) ($groups['primary'] ?? []));
    $discovery = array_values(array_filter((array) ($groups['discovery'] ?? []), static fn (mixed $item): bool => is_array($item)));
    if ($discovery === []) return;
    echo '<details class="nav-discovery"><summary aria-expanded="false">Khám phá</summary>';
    nhk_v3_render_nav_items($discovery);
    echo '</details>';
}

function nhk_v3_public_brand_text(string $text): string
{
    if (class_exists('NHK\\Core\\Application\\Projection\\PublicBrandNamePolicy')) {
        return \NHK\Core\Application\Projection\PublicBrandNamePolicy::normalizeText($text);
    }

    $aliases = [
        '/(?<![\p{L}\p{N}])(?:ô[\s-]*đ[oô]|o[\s-]*do|odo)(?![\p{L}\p{N}])/iu' => 'Odo',
        '/(?<![\p{L}\p{N}])(?:vê[\s-]*đét|ve[\s-]*det|vedet(?:te)?)(?![\p{L}\p{N}])/iu' => 'Vedette',
        '/(?<![\p{L}\p{N}])(?:junghans|jun[\s-]*han(?:s)?|junhan)(?![\p{L}\p{N}])/iu' => 'Junghans',
    ];
    foreach ($aliases as $pattern => $replacement) {
        $normalized = preg_replace($pattern, $replacement, $text);
        if (is_string($normalized)) $text = $normalized;
    }
    return $text;
}

function nhk_v3_public_copy(string $text): string
{
    $text = nhk_v3_public_brand_text($text);
    $replacements = [
        '/\bMovement family\b/iu' => 'Dòng bộ máy',
        '/\bsub-configuration\b/iu' => 'cấu hình thành phần',
        '/\bmarking\b/iu' => 'ký hiệu',
        '/\bcatalog\b/iu' => 'catalogue',
        '/\bBrand identity\b/iu' => 'Danh tính thương hiệu',
        '/\bBrand\b/iu' => 'thương hiệu',
        '/\bModel\b/iu' => 'mẫu đồng hồ',
        '/\bVariant\b/iu' => 'biến thể',
        '/\bMovement\b/iu' => 'bộ máy',
        '/\bComponent\b/iu' => 'linh kiện',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $normalized = preg_replace($pattern, $replacement, $text);
        if (is_string($normalized)) $text = $normalized;
    }
    return $text;
}

function nhk_v3_public_html(string $html): string
{
    if (class_exists('NHK\\Core\\Application\\Projection\\PublicBrandNamePolicy')) {
        return \NHK\Core\Application\Projection\PublicBrandNamePolicy::normalizeHtml($html);
    }

    $parts = function_exists('wp_html_split') ? wp_html_split($html) : preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) return nhk_v3_public_brand_text($html);
    $protected = null;
    foreach ($parts as $index => $part) {
        if (!is_string($part) || $part === '') continue;
        if ($part[0] === '<') {
            if (preg_match('/^<\s*script\b[^>]*type=(?:["\'])application\/ld\+json(?:["\'])[^>]*>/iu', $part)) {
                $protected = 'jsonld';
            } elseif (preg_match('/^<\s*(script|style|textarea)\b/iu', $part, $opening)) {
                $protected = strtolower((string) $opening[1]);
            } elseif (preg_match('/^<\s*\/\s*(script|style|textarea)\b/iu', $part)) {
                $protected = null;
            }
            continue;
        }
        if (!in_array($protected, ['script', 'style', 'textarea'], true)) $parts[$index] = nhk_v3_public_brand_text($part);
    }
    return implode('', $parts);
}

function nhk_v3_public_title(string $title): string { return is_admin() ? $title : nhk_v3_public_brand_text($title); }
function nhk_v3_public_excerpt_filter(string $excerpt): string { return is_admin() ? $excerpt : nhk_v3_public_brand_text($excerpt); }
function nhk_v3_public_content_filter(string $content): string { return is_admin() ? $content : nhk_v3_public_html($content); }

add_filter('the_title', 'nhk_v3_public_title', 20);
add_filter('the_title_rss', 'nhk_v3_public_title', 20);
add_filter('get_the_excerpt', 'nhk_v3_public_excerpt_filter', 20);
add_filter('the_excerpt_rss', 'nhk_v3_public_excerpt_filter', 20);
add_filter('the_content', 'nhk_v3_public_content_filter', 20);
add_filter('the_content_feed', 'nhk_v3_public_content_filter', 20);

function nhk_v3_excerpt(): string { return wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 28); }

/**
 * Presentation-only media classification. Dimensions must come from the
 * canonical projection; filenames, titles and URLs are never used as hints.
 */
function nhk_v3_media_orientation(mixed $width, mixed $height): string
{
    $width = is_numeric($width) ? (int) $width : 0;
    $height = is_numeric($height) ? (int) $height : 0;
    if ($width < 1 || $height < 1) return 'unknown';
    $ratio = $width / $height;
    if (abs($ratio - 1.0) <= 0.08) return 'square';
    return $ratio < 1.0 ? 'portrait' : 'landscape';
}

function nhk_v3_media_orientation_class(mixed $width, mixed $height): string
{
    return 'nhk-media--' . nhk_v3_media_orientation($width, $height);
}

/** @return array{width:int,height:int} */
function nhk_v3_media_dimensions(array $item): array
{
    $thumbnail = is_array($item['thumbnail'] ?? null) ? $item['thumbnail'] : [];
    $representative = is_array($item['representative'] ?? null) ? $item['representative'] : [];
    if ($representative !== [] && $thumbnail === []) $thumbnail = is_array($representative['thumbnail'] ?? null) ? $representative['thumbnail'] : [];
    return [
        'width' => max(0, (int) ($item['width'] ?? $thumbnail['width'] ?? $representative['width'] ?? 0)),
        'height' => max(0, (int) ($item['height'] ?? $thumbnail['height'] ?? $representative['height'] ?? 0)),
    ];
}

/** @return array{url:string,srcset:string,sizes:string,width:int,height:int} */
function nhk_v3_media_presentation(array $item, bool $compact = true): array
{
    $representative = is_array($item['representative'] ?? null) ? $item['representative'] : [];
    $nested = is_array($item['thumbnail'] ?? null) ? $item['thumbnail'] : [];
    $representativeThumbnail = is_array($representative['thumbnail'] ?? null) ? $representative['thumbnail'] : [];
    $candidates = $compact
        ? [$item['thumbnail_url'] ?? null, $nested['url'] ?? null, $representative['thumbnail_url'] ?? null, $representativeThumbnail['url'] ?? null, $item['image_url'] ?? null, $representative['url'] ?? null, $item['url'] ?? null]
        : [$item['image_url'] ?? null, $representative['url'] ?? null, $item['url'] ?? null, $item['thumbnail_url'] ?? null, $nested['url'] ?? null];
    $url = '';
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') { $url = trim($candidate); break; }
    }
    $srcset = '';
    foreach ([$item['thumbnail_srcset'] ?? null, $item['srcset'] ?? null, $representative['thumbnail_srcset'] ?? null, $representative['srcset'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') { $srcset = trim($candidate); break; }
    }
    $sizes = '';
    foreach ([$item['thumbnail_sizes'] ?? null, $item['sizes'] ?? null, $representative['thumbnail_sizes'] ?? null, $representative['sizes'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') { $sizes = trim($candidate); break; }
    }
    $dimensions = nhk_v3_media_dimensions($item);
    return ['url' => $url, 'srcset' => $srcset, 'sizes' => $sizes, 'width' => $dimensions['width'], 'height' => $dimensions['height']];
}

function nhk_v3_media_content_url(array $item): string
{
    foreach (['detail_url', 'page_url', 'entity_url', 'article_url'] as $key) {
        $url = nhk_v3_public_url($item[$key] ?? null);
        if ($url !== '') return $url;
    }
    return '';
}

function nhk_v3_video_summary(mixed $value, string $title = ''): string
{
    $summary = trim(wp_strip_all_tags((string) $value));
    if ($summary === '' || ($title !== '' && mb_strtolower($summary) === mb_strtolower(trim($title)))) return '';
    if (preg_match('~^https?://\S+$~i', $summary)) return '';
    $boilerplate = [
        'mời các bác xem video', 'video đồng hồ cổ', 'xem thêm tại link',
        'xem video này', 'video tham chiếu được nhk chuẩn hóa từ nguồn bên ngoài',
    ];
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $summary)));
    foreach ($boilerplate as $phrase) if ($normalized === $phrase || str_starts_with($normalized, $phrase . ' ')) return '';
    $summary = preg_replace('~https?://\S+~i', '', $summary) ?: $summary;
    return trim($summary);
}

function nhk_v3_entity_label(string $type, string $profile = ''): string
{
    if ($profile === 'clock_type') return 'nhóm đồng hồ';
    return ['brand' => 'thương hiệu', 'model' => 'mẫu đồng hồ', 'variant' => 'biến thể', 'movement' => 'bộ máy', 'music' => 'bản nhạc', 'component' => 'linh kiện', 'classification' => 'phân loại', 'specimen' => 'hiện vật', 'product' => 'sản phẩm'][$type] ?? 'hồ sơ';
}

function nhk_v3_public_type(string $type, string $profile = ''): string
{
    if ($profile === 'clock_type') return 'nhóm đồng hồ';
    return ['wp_post' => 'bài viết', 'post' => 'bài viết', 'brand' => 'thương hiệu', 'model' => 'mẫu đồng hồ', 'variant' => 'biến thể', 'movement' => 'bộ máy', 'music' => 'bản nhạc', 'component' => 'linh kiện', 'classification' => 'phân loại', 'specimen' => 'hiện vật', 'product' => 'sản phẩm', 'media' => 'hình ảnh', 'video' => 'video', 'knowledge' => 'tri thức', 'source' => 'nguồn', 'evidence' => 'bằng chứng', 'publication' => 'ấn phẩm', 'website' => 'website', 'archive' => 'lưu trữ', 'catalog' => 'catalogue', 'interview' => 'phỏng vấn', 'fact' => 'dữ kiện', 'technical' => 'kỹ thuật', 'specification' => 'thông số'][$type] ?? 'nội dung liên quan';
}

/** @return array{eyebrow:string,heading:string,summary:string,search_placeholder:string,empty_title:string,empty_copy:string,allow_filter:bool} */
function nhk_v3_entity_archive_presentation(string $type, string $profile = ''): array
{
    $presentations = [
        'brand' => ['eyebrow' => 'Thương hiệu', 'heading' => 'Thương hiệu', 'summary' => 'Tra cứu các thương hiệu đồng hồ đã được xác định trong kho, cùng hình ảnh và tư liệu liên quan khi có.', 'search_placeholder' => 'Tìm thương hiệu...', 'empty_title' => 'Chưa có thương hiệu công khai.', 'empty_copy' => 'Kho thương hiệu hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'model' => ['eyebrow' => 'Mẫu đồng hồ', 'heading' => 'Mẫu đồng hồ', 'summary' => 'Tra cứu các mẫu đã được định danh và những mối liên hệ công khai với thương hiệu, bộ máy, hiện vật và tư liệu.', 'search_placeholder' => 'Tìm mẫu đồng hồ...', 'empty_title' => 'Chưa có mẫu đồng hồ công khai.', 'empty_copy' => 'Kho mẫu đồng hồ hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'movement' => ['eyebrow' => 'Bộ máy', 'heading' => 'Bộ máy', 'summary' => 'Tra cứu các bộ máy đã được định danh, cùng cấu hình kỹ thuật và quan hệ được ghi nhận khi có.', 'search_placeholder' => 'Tìm bộ máy...', 'empty_title' => 'Chưa có hồ sơ bộ máy công khai.', 'empty_copy' => 'Kho bộ máy hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'music' => ['eyebrow' => 'Bản nhạc', 'heading' => 'Bản nhạc', 'summary' => 'Tra cứu hồ sơ bản nhạc và chuông cùng các liên hệ đã được ghi nhận trong kho.', 'search_placeholder' => 'Tìm bản nhạc...', 'empty_title' => 'Chưa có hồ sơ bản nhạc công khai.', 'empty_copy' => 'Kho bản nhạc hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'component' => ['eyebrow' => 'Linh kiện', 'heading' => 'Linh kiện', 'summary' => 'Tra cứu các thành phần và linh kiện đã được định danh trong các hồ sơ công khai.', 'search_placeholder' => 'Tìm linh kiện...', 'empty_title' => 'Chưa có hồ sơ linh kiện công khai.', 'empty_copy' => 'Kho linh kiện hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'specimen' => ['eyebrow' => 'Hiện vật', 'heading' => 'Hiện vật', 'summary' => 'Tra cứu các vật thể thực đã có hồ sơ quan sát, hình ảnh hoặc tư liệu trực tiếp khi có.', 'search_placeholder' => 'Tìm hiện vật...', 'empty_title' => 'Chưa có hồ sơ hiện vật công khai.', 'empty_copy' => 'Kho hiện vật hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
        'product' => ['eyebrow' => 'Sản phẩm', 'heading' => 'Sản phẩm', 'summary' => 'Tra cứu các hồ sơ sản phẩm hoặc listing đủ điều kiện công khai; sản phẩm không đồng nhất với hiện vật.', 'search_placeholder' => 'Tìm sản phẩm...', 'empty_title' => 'Chưa có hồ sơ sản phẩm công khai.', 'empty_copy' => 'Kho sản phẩm chỉ hiển thị các hồ sơ đủ điều kiện công khai. Bạn vẫn có thể khám phá thương hiệu, loại đồng hồ hoặc hiện vật.', 'allow_filter' => true],
        'classification' => ['eyebrow' => 'Phân loại', 'heading' => 'Phân loại', 'summary' => 'Tra cứu kho phân loại rộng hơn để xem các nhóm và nhãn đã được xác định trong hệ thống.', 'search_placeholder' => 'Tìm phân loại...', 'empty_title' => 'Chưa có hồ sơ phân loại công khai.', 'empty_copy' => 'Kho phân loại hiện chưa có hồ sơ đủ điều kiện hiển thị.', 'allow_filter' => true],
    ];
    if ($profile === 'clock_type') return ['eyebrow' => 'Loại đồng hồ', 'heading' => 'Loại đồng hồ', 'summary' => 'Duyệt theo loại đồng hồ: bắt đầu từ các nhóm chính, sau đó đi sâu vào các nhóm con đã được ghi nhận.', 'search_placeholder' => '', 'empty_title' => 'Chưa có loại đồng hồ công khai.', 'empty_copy' => 'Kho loại đồng hồ hiện chưa có nhóm được tuyển chọn để hiển thị.', 'allow_filter' => false];
    return $presentations[$type] ?? ['eyebrow' => 'Khám phá', 'heading' => 'Khám phá', 'summary' => 'Tra cứu các hồ sơ công khai trong kho NHK.', 'search_placeholder' => 'Tìm hồ sơ...', 'empty_title' => 'Chưa có hồ sơ công khai.', 'empty_copy' => 'Kho hồ sơ hiện chưa có dữ liệu phù hợp.', 'allow_filter' => true];
}

function nhk_v3_dictionary_initial(string $title): string
{
    $title = trim($title);
    if ($title === '') return '#';
    $initial = function_exists('mb_substr') ? mb_substr($title, 0, 1, 'UTF-8') : substr($title, 0, 1);
    if (preg_match('/^[0-9]$/', $initial)) return '0–9';
    return function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
}

function nhk_v3_dictionary_anchor(string $initial): string
{
    $slug = function_exists('sanitize_title') ? sanitize_title($initial) : strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $initial));
    return trim($slug, '-') ?: 'other';
}

function nhk_v3_dictionary_label_kind(string $kind): string
{
    return ['ALIAS' => 'Tên gọi khác', 'COLLOQUIAL' => 'Cách gọi dân gian', 'TECHNICAL' => 'Tên gọi kỹ thuật', 'PHONETIC' => 'Cách đọc'][$kind] ?? '';
}

function nhk_v3_dictionary_scope_label(string $scope): string
{
    return ['VIETNAM' => 'Việt Nam', 'WORKSHOP' => 'Giới thợ', 'TECHNICAL' => 'Kỹ thuật', 'COLLOQUIAL' => 'Dân gian', 'GENERAL' => 'Thông dụng'][$scope] ?? '';
}

function nhk_v3_public_category_name(string $name): string
{
    return nhk_v3_public_brand_text(strcasecmp(trim($name), 'Uncategorized') === 0 ? 'Chưa phân loại' : $name);
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
    return ['tri-thuc' => 'Tri thức đồng hồ', 'goc-chia-se' => 'Góc chia sẻ'][$route] ?? '';
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
    return nhk_v3_public_brand_text(str_ireplace(array_keys($replacements), array_values($replacements), $text));
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

/** @return array<string,mixed> */
function nhk_v3_article_media_seo(int $postId): array
{
    $value = apply_filters('nhk_v3_article_media_seo', [], $postId);
    return is_array($value) ? $value : [];
}

/** @return list<array{question:string,answer:string}> */
function nhk_v3_article_faq(int $postId): array
{
    $value = apply_filters('nhk_v3_article_faq', [], $postId);
    if (!is_array($value)) return [];
    $items = [];
    foreach ($value as $item) {
        if (!is_array($item)) continue;
        $question = trim(wp_strip_all_tags((string) ($item['question'] ?? $item['q'] ?? '')));
        $answer = trim(wp_strip_all_tags((string) ($item['answer'] ?? $item['a'] ?? '')));
        if ($question === '' || $answer === '') continue;
        $items[] = ['question' => $question, 'answer' => $answer];
        if (count($items) >= 12) break;
    }
    return $items;
}

function nhk_v3_context_seo_projection(): array
{
    foreach (['nhk_core_dictionary_context', 'nhk_core_entity_context', 'nhk_core_media_context', 'nhk_core_video_context', 'nhk_core_knowledge_context', 'nhk_core_comparison_context'] as $key) {
        $context = $GLOBALS[$key] ?? null;
        if ($key === 'nhk_core_dictionary_context' && is_array($context) && is_array($context['seo_projection'] ?? null)) return $context['seo_projection'];
        if (is_array($context) && is_array($context['seo_projection'] ?? null)) return $context['seo_projection'];
        if ($key === 'nhk_core_entity_context' && is_array($context['entity']['seo_projection'] ?? null)) return $context['entity']['seo_projection'];
    }
    return [];
}

function nhk_v3_document_title(string $title): string
{
    $entity = $GLOBALS['nhk_core_entity_context'] ?? null;
    $media = $GLOBALS['nhk_core_media_context'] ?? null;
    $video = $GLOBALS['nhk_core_video_context'] ?? null;
    $knowledge = $GLOBALS['nhk_core_knowledge_context'] ?? null;
    $comparison = $GLOBALS['nhk_core_comparison_context'] ?? null;
    $dictionary = $GLOBALS['nhk_core_dictionary_context'] ?? null;
    if (is_array($dictionary) && ($dictionary['mode'] ?? '') === 'detail' && is_array($dictionary['result']['presentation']['seo'] ?? null)) return (string) ($dictionary['result']['presentation']['seo']['title'] ?? $title);
    if (is_array($dictionary) && ($dictionary['mode'] ?? '') === 'hub') return 'Từ điển đồng hồ cổ — Đồng Hồ Nhà Kho';
    if (is_array($entity) && ($entity['mode'] ?? '') === 'detail' && is_array($entity['entity'] ?? null)) return (string) $entity['entity']['name'] . ' — Đồng Hồ Nhà Kho';
    if (is_array($entity) && ($entity['mode'] ?? '') === 'archive') return 'Khám phá ' . nhk_v3_entity_label((string) ($entity['type'] ?? ''), (string) ($entity['profile'] ?? '')) . ' — Đồng Hồ Nhà Kho';
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
add_filter('pre_get_document_title', 'nhk_v3_public_brand_text', 99);

function nhk_v3_seo_head(): void
{
    if (is_admin()) return;
    $context = $GLOBALS['nhk_core_entity_context'] ?? null;
    $media_context = $GLOBALS['nhk_core_media_context'] ?? null;
    $video_context = $GLOBALS['nhk_core_video_context'] ?? null;
    $knowledge_context = $GLOBALS['nhk_core_knowledge_context'] ?? null;
    $comparison_context = $GLOBALS['nhk_core_comparison_context'] ?? null;
    $dictionary_context = $GLOBALS['nhk_core_dictionary_context'] ?? null;
    $title = wp_get_document_title(); $description = get_bloginfo('description'); $canonical = '';
    $sharedSeo = nhk_v3_context_seo_projection();
    if (is_array($sharedSeo['open_graph'] ?? null)) {
        if (trim((string) ($sharedSeo['open_graph']['title'] ?? '')) !== '') $title = (string) $sharedSeo['open_graph']['title'];
        if (trim((string) ($sharedSeo['open_graph']['description'] ?? '')) !== '') $description = (string) $sharedSeo['open_graph']['description'];
    }
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
        if (($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)) { $entity = $context['entity']; $title = (string) $entity['name'] . ' — Đồng Hồ Nhà Kho'; $description = 'Hồ sơ ' . (string) $entity['name'] . ' trong kho NHK.'; }
        elseif (($context['mode'] ?? '') === 'archive') { $label = nhk_v3_entity_label((string) ($context['type'] ?? ''), (string) ($context['profile'] ?? '')); $title = 'Khám phá ' . $label . ' — Đồng Hồ Nhà Kho'; $description = 'Khám phá ' . $label . ' trong kho tri thức NHK.'; }
    }
    if (is_array($media_context)) {
        if (($media_context['mode'] ?? '') === 'detail' && is_array($media_context['media'] ?? null)) { $media = $media_context['media']; $title = (string) ($media['name'] ?? 'Hình ảnh') . ' — Đồng Hồ Nhà Kho'; $description = 'Hình ảnh liên quan trong thư viện NHK.'; }
        elseif (($media_context['mode'] ?? '') === 'archive') { $title = 'Hình ảnh — Đồng Hồ Nhà Kho'; $description = 'Thư viện hình ảnh của NHK.'; }
    }
    if (is_array($video_context)) {
        if (($video_context['mode'] ?? '') === 'detail' && is_array($video_context['video'] ?? null)) { $video = $video_context['video']; $title = (string) (($video['title'] ?? '') ?: 'Video NHK') . ' — Đồng Hồ Nhà Kho'; $description = 'Video tham chiếu từ nguồn bên ngoài trong thư viện NHK.'; }
        elseif (($video_context['mode'] ?? '') === 'archive') { $title = 'Video — Đồng Hồ Nhà Kho'; $description = 'Các video từ nguồn bên ngoài được NHK kiểm soát.'; }
    }
    if (is_array($knowledge_context)) {
        if (($knowledge_context['mode'] ?? '') === 'detail' && is_array($knowledge_context['claim'] ?? null)) { $claim = $knowledge_context['claim']; $title = (string) $claim['text'] . ' — Tri thức NHK'; $description = 'Tri thức được kiểm soát trong kho NHK.'; }
        elseif (($knowledge_context['mode'] ?? '') === 'archive') { $title = 'Kho tri thức — Đồng Hồ Nhà Kho'; $description = 'Các tri thức đang hoạt động trong kho NHK.'; }
    }
    $sharedCanonical = $sharedSeo['canonical_url'] ?? $sharedSeo['canonical'] ?? '';
    if (is_string($sharedCanonical) && $sharedCanonical !== '') $canonical = $sharedCanonical;
    elseif (is_string($sharedSeo['canonical_path'] ?? null) && $sharedSeo['canonical_path'] !== '') $canonical = home_url((string) $sharedSeo['canonical_path']);
    if (is_array($comparison_context) && ($comparison_context['mode'] ?? '') === 'compare') { $title = 'So sánh hồ sơ — Đồng Hồ Nhà Kho'; $description = 'Đọc cạnh nhau các dữ kiện công khai của hai hồ sơ NHK.'; $canonical = home_url('/so-sanh/'); }
    if (is_array($dictionary_context) && ($dictionary_context['mode'] ?? '') === 'detail' && is_array($dictionary_context['result']['presentation'] ?? null)) {
        $dictionarySeo = is_array($dictionary_context['result']['presentation']['seo'] ?? null) ? $dictionary_context['result']['presentation']['seo'] : [];
        $title = (string) ($dictionarySeo['title'] ?? $title);
        $description = (string) ($dictionarySeo['meta_description'] ?? $description);
        $canonical = (string) ($dictionarySeo['canonical_url'] ?? $dictionarySeo['canonical'] ?? $canonical);
    }
    if ($canonical === '') {
        if (is_front_page() || is_home() || is_search()) $canonical = home_url('/');
        else $canonical = function_exists('wp_get_canonical_url') ? (string) wp_get_canonical_url() : home_url(add_query_arg([]));
        if ($canonical === '') $canonical = home_url('/');
    }
    $title = nhk_v3_public_brand_text($title);
    $description = nhk_v3_public_brand_text($description);
    echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr(is_singular('post') ? 'article' : 'website') . '"><meta property="og:title" content="' . esc_attr($title) . '"><meta property="og:description" content="' . esc_attr(wp_strip_all_tags($description)) . '"><meta property="og:url" content="' . esc_url($canonical) . '"><meta property="og:site_name" content="Đồng Hồ Nhà Kho">' . "\n";
    $articleSeo = is_singular('post') ? nhk_v3_article_media_seo((int) get_queried_object_id()) : [];
    $articleImage = (($articleSeo['eligible'] ?? false) === true && trim((string) ($articleSeo['image_url'] ?? '')) !== '') ? (string) $articleSeo['image_url'] : '';
    if (is_singular('post') && $articleImage !== '') echo '<meta property="og:image" content="' . esc_url($articleImage) . '">' . "\n";
    $breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'NHK', 'item' => home_url('/')]]];
    if (is_singular('post')) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink()];
    if (is_array($context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => nhk_v3_entity_label((string) ($context['type'] ?? ''), (string) ($context['profile'] ?? '')), 'item' => (string) ($context['archive_url'] ?? home_url('/'))];
    if (is_array($media_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Thư viện media', 'item' => home_url('/thu-vien/')];
    if (is_array($video_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Video', 'item' => home_url('/video/')];
    if (is_array($knowledge_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tri thức', 'item' => home_url('/tri-thuc/')];
    if (is_array($comparison_context)) $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'So sánh hồ sơ', 'item' => home_url('/so-sanh/')];
    if (is_array($dictionary_context) && ($dictionary_context['mode'] ?? '') === 'detail' && is_array($dictionary_context['result']['presentation'] ?? null)) {
        $dictionaryTitle = (string) ($dictionary_context['result']['presentation']['identity']['title'] ?? 'Từ điển');
        $dictionaryCanonical = (string) ($dictionary_context['result']['presentation']['seo']['canonical'] ?? home_url('/tu-dien/'));
        $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Từ điển', 'item' => home_url('/tu-dien/')];
        $breadcrumb['itemListElement'][] = ['@type' => 'ListItem', 'position' => 3, 'name' => $dictionaryTitle, 'item' => $dictionaryCanonical];
    }
    echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    if (!is_singular('post') && !is_array($video_context) && is_array($sharedSeo['json_ld'] ?? null) && $sharedSeo['json_ld'] !== []) echo '<script type="application/ld+json">' . wp_json_encode($sharedSeo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    if (is_singular('post')) {
        echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => get_the_title(), 'image' => $articleImage !== '' ? $articleImage : null, 'datePublished' => get_the_date('c'), 'dateModified' => get_the_modified_date('c'), 'author' => ['@type' => 'Person', 'name' => get_the_author()], 'mainEntityOfPage' => get_permalink()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        $faq = nhk_v3_article_faq((int) get_queried_object_id());
        if ($faq !== []) echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn (array $item): array => ['@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']]], $faq)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
    // VideoObject is emitted from the canonical Video SEO projection.
    if (is_array($video_context) && ($video_context['mode'] ?? '') === 'detail' && is_array($video_context['video'] ?? null)) { $video = $video_context['video']; $videoObject = is_array($video['seo_projection']['video_object'] ?? null) && $video['seo_projection']['video_object'] !== [] ? $video['seo_projection']['video_object'] : null; if ($videoObject !== null) { $videoObject['url'] = $canonical; echo '<script type="application/ld+json">' . wp_json_encode($videoObject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; } }
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
    $sharedSeo = nhk_v3_context_seo_projection();
    $mustNoindex = is_404() || is_search() || $isPaginated || (($sharedSeo['indexable'] ?? true) === false);
    $canonical = $sharedSeo['canonical_url'] ?? $sharedSeo['canonical'] ?? (function_exists('wp_get_canonical_url') ? wp_get_canonical_url() : home_url('/'));
    $decision = (new \NHK\Core\Application\Seo\SeoIndexabilityPolicy())->evaluate([
        'readiness' => $mustNoindex ? 'BLOCKED' : 'READY',
        'public_eligible' => !$mustNoindex,
        'canonical_url' => is_string($canonical) ? $canonical : '',
    ]);
    unset($robots['index'], $robots['noindex']);
    $robots[$decision->indexable() && !$mustNoindex ? 'index' : 'noindex'] = true;
    $robots['follow'] = true;
    return $robots;
}
add_filter('wp_robots', 'nhk_v3_robots', 20);
