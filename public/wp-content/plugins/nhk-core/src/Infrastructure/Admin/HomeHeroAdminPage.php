<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use InvalidArgumentException;
use NHK\Core\Application\Home\HomeHeroMediaConfig;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository};

/** Admin adapter for presentation-only homepage hero configuration. */
final class HomeHeroAdminPage
{
    public static function register(): void
    {
        add_action('admin_post_nhk_save_home_hero_media', [self::class, 'save']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;
        $config = new HomeHeroMediaConfig();
        $candidates = self::candidates();
        $current = (array) get_option(HomeHeroMediaConfig::OPTION, []);
        $currentIds = array_values(array_filter(array_map('strval', $current)));
        $candidateById = [];
        foreach ($candidates as $candidate) $candidateById[(string) $candidate['id']] = $candidate;
        $status = $config->status(array_values(array_filter($currentIds, static fn (string $id): bool => isset($candidateById[$id]))));

        echo '<section class="nhk-admin-panel nhk-home-hero-config" data-nhk-hero-config><h2>Ảnh Hero trang chủ</h2>';
        echo '<p>Ảnh chọn thủ công luôn được ưu tiên. Nếu chưa đủ số lượng, hệ thống sẽ tự bổ sung ảnh phù hợp.</p>';
        if (isset($_GET['hero_saved'])) echo '<div class="notice notice-success inline"><p>Đã lưu cấu hình ảnh Hero trang chủ.</p></div>';
        if (isset($_GET['hero_error'])) echo '<div class="notice notice-error inline"><p>' . esc_html((string) wp_unslash($_GET['hero_error'])) . '</p></div>';
        echo '<p class="nhk-hero-status" data-nhk-hero-status>Manual: <strong>' . esc_html((string) $status['manual']) . '</strong> ảnh · Auto fallback: <strong>' . esc_html((string) $status['auto_fallback']) . '</strong> ảnh</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-nhk-hero-form>';
        echo '<input type="hidden" name="action" value="nhk_save_home_hero_media">';
        wp_nonce_field('nhk_save_home_hero_media');
        echo '<ol class="nhk-hero-slots" data-nhk-hero-slots aria-label="Thứ tự ảnh Hero">';
        foreach ($currentIds as $id) {
            $item = $candidateById[$id] ?? null;
            echo '<li class="nhk-hero-slot" draggable="true" data-hero-id="' . esc_attr($id) . '">';
            if (is_array($item)) {
                echo '<img src="' . esc_url((string) $item['image_url']) . '" alt="" width="96" height="72">';
                echo '<span><strong>' . esc_html((string) ($item['title'] ?? $id)) . '</strong><small>' . esc_html($id . ' · ' . self::dimensions($item)) . '</small></span>';
            } else {
                echo '<span><strong>Media không còn khả dụng</strong><small>' . esc_html($id) . '</small></span>';
            }
            echo '<input type="hidden" name="media_ids[]" value="' . esc_attr($id) . '"><button type="button" class="button-link-delete" data-hero-remove>Xóa</button></li>';
        }
        echo '</ol>';
        echo '<div class="nhk-hero-add"><label for="nhk-hero-add-media"><strong>Thêm ảnh từ Media hiện có</strong></label><select id="nhk-hero-add-media" data-hero-add><option value="">Chọn ảnh…</option>';
        foreach ($candidates as $item) {
            $id = (string) $item['id'];
            if (in_array($id, $currentIds, true)) continue;
            echo '<option value="' . esc_attr($id) . '">' . esc_html((string) ($item['title'] ?? $id) . ' · ' . self::dimensions($item)) . '</option>';
        }
        echo '</select> <button type="button" class="button" data-hero-add-button>Thêm</button></div>';
        echo '<p><button class="button button-primary" type="submit">Lưu cấu hình Hero</button></p></form></section>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền thay đổi cấu hình Hero.', '', ['response' => 403]);
        check_admin_referer('nhk_save_home_hero_media');
        try {
            $raw = isset($_POST['media_ids']) && is_array($_POST['media_ids']) ? wp_unslash($_POST['media_ids']) : [];
            $ids = (new HomeHeroMediaConfig())->validate($raw, self::candidates());
            update_option(HomeHeroMediaConfig::OPTION, $ids, false);
            wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-media', 'hero_saved' => '1'], admin_url('admin.php')));
        } catch (InvalidArgumentException $error) {
            wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-media', 'hero_error' => $error->getMessage()], admin_url('admin.php')));
        }
        exit;
    }

    /** @return list<array<string,mixed>> */
    private static function candidates(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return [];
        $gallery = new PublicMediaGalleryQuery(new WpdbMediaRepository($wpdb), new WpdbMediaAssetRepository($wpdb));
        $items = [];
        foreach ((new WpdbMediaRepository($wpdb))->list() as $media) {
            $visual = $gallery->forMedia($media->canonicalId);
            if (!is_array($visual) || trim((string) ($visual['image_url'] ?? '')) === '' || ($visual['has_real_image'] ?? false) !== true) continue;
            $visual['id'] = $media->canonicalId;
            $items[] = $visual;
        }
        return $items;
    }

    private static function dimensions(array $item): string
    {
        $width = (int) ($item['width'] ?? 0);
        $height = (int) ($item['height'] ?? 0);
        if ($width <= 0 || $height <= 0) return 'Kích thước chưa có';
        $orientation = $width === $height ? 'vuông' : ($width > $height ? 'ngang' : 'dọc');
        return $width . '×' . $height . ' · ' . $orientation;
    }
}
