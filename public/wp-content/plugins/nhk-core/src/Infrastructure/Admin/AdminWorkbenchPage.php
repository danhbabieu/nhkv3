<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Task-first Admin shell. Existing domain/application writers remain unchanged.
 */
final class AdminWorkbenchPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu'], 11);
    }

    public static function registerMenu(): void
    {
        // AdminPage registers the historical top-level item at priority 10.
        // Detach that page callback before reusing the same slug so the new
        // dashboard cannot render together with the legacy technical surface.
        remove_action('toplevel_page_nhk-v3', [AdminPage::class, 'render']);
        remove_menu_page('nhk-v3');
        add_menu_page(
            'NHK V3',
            'NHK V3',
            'manage_options',
            'nhk-v3',
            [self::class, 'render'],
            'dashicons-book-alt',
            26
        );
        add_submenu_page('nhk-v3', 'Tổng quan', 'Tổng quan', 'manage_options', 'nhk-v3', [self::class, 'render']);
        add_submenu_page('nhk-v3', 'Nâng cao', 'Nâng cao', 'manage_options', 'nhk-v3-advanced', [AdminPage::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền xem khu vực này.');

        $registry = new AdminWorkbenchRegistry();
        $sections = array_values(array_filter(
            $registry->sections(),
            static fn (array $section): bool => current_user_can((string) $section['capability'])
        ));

        echo '<div class="wrap nhk-admin-workbench">';
        echo '<header class="nhk-admin-hero">';
        echo '<div><p class="nhk-admin-eyebrow">NHK V3 · Admin Workbench</p><h1>Trung tâm quản trị</h1>';
        echo '<p class="nhk-admin-lead">Chọn công việc cần làm. Mỗi khu vực hiển thị rõ chủ sở hữu dữ liệu và chỉ dẫn đến writer hoặc màn hình đã có trong hệ thống.</p></div>';
        echo '<div class="nhk-admin-hero__boundary"><strong>Luật vận hành</strong><span>Admin là adapter; semantic mutation vẫn phải qua Governance và read-back.</span></div>';
        echo '</header>';

        self::renderNavigation($sections);
        self::renderCards($sections);
        self::renderStateGuide();
        self::renderOwnershipGuide();
        echo '</div>';
    }

    /** @param list<array<string,string>> $sections */
    private static function renderNavigation(array $sections): void
    {
        echo '<nav class="nhk-admin-nav" aria-label="Khu vực quản trị NHK V3"><ul>';
        foreach ($sections as $section) {
            if ($section['id'] === 'overview') continue;
            echo '<li><a href="' . esc_url(self::adminHref($section['href'])) . '">' . esc_html($section['label']) . '</a></li>';
        }
        echo '</ul></nav>';
    }

    /** @param list<array<string,string>> $sections */
    private static function renderCards(array $sections): void
    {
        echo '<section aria-labelledby="nhk-admin-work-heading"><div class="nhk-admin-section-heading">';
        echo '<div><h2 id="nhk-admin-work-heading">Công việc</h2><p>Đi theo tác vụ hằng ngày; công cụ kỹ thuật được tách xuống khu vực Nâng cao.</p></div></div>';
        echo '<div class="nhk-admin-grid">';
        foreach ($sections as $section) {
            if ($section['id'] === 'overview') continue;
            $mode = match ($section['kind']) {
                'native' => 'WordPress gốc',
                'advanced' => 'Nâng cao',
                default => 'Workbench',
            };
            echo '<article class="nhk-admin-card" data-workbench-section="' . esc_attr($section['id']) . '">';
            echo '<div class="nhk-admin-card__top"><span class="nhk-admin-pill">' . esc_html($mode) . '</span><span class="nhk-admin-access">Có quyền truy cập</span></div>';
            echo '<h3><a href="' . esc_url(self::adminHref($section['href'])) . '">' . esc_html($section['label']) . '</a></h3>';
            echo '<p>' . esc_html($section['description']) . '</p>';
            echo '<dl class="nhk-admin-card__meta"><div><dt>Chủ sở hữu</dt><dd>' . esc_html($section['owner']) . '</dd></div><div><dt>Quyền</dt><dd><code>' . esc_html($section['capability']) . '</code></dd></div></dl>';
            echo '<a class="button button-secondary nhk-admin-card__action" href="' . esc_url(self::adminHref($section['href'])) . '">Mở ' . esc_html($section['label']) . '</a>';
            echo '</article>';
        }
        echo '</div></section>';
    }

    private static function renderStateGuide(): void
    {
        $state = new AdminWorkbenchState([
            ['label' => 'Sẵn sàng', 'value' => 'Đã có điều kiện cần thiết ở lớp đang xem', 'tone' => 'ready'],
            ['label' => 'Cần chú ý', 'value' => 'Còn bước duyệt, bổ sung hoặc xác minh', 'tone' => 'attention'],
            ['label' => 'Bị chặn', 'value' => 'Đóng an toàn; không được tự tạo đường tắt', 'tone' => 'blocked'],
            ['label' => 'Thông tin', 'value' => 'Chưa đủ dữ liệu để kết luận hoặc không áp dụng', 'tone' => 'neutral'],
        ]);

        echo '<section class="nhk-admin-panel" aria-labelledby="nhk-admin-state-heading"><h2 id="nhk-admin-state-heading">Cách đọc trạng thái</h2>';
        echo '<p>Màu chỉ hỗ trợ nhận biết; nội dung chữ mới là tín hiệu chính. Trạng thái biên tập, vòng đời, hiển thị, Governance, readiness và verification không bị gộp thành một nhãn.</p>';
        echo '<div class="nhk-admin-state-list">';
        foreach ($state->rows() as $row) {
            echo '<div class="nhk-admin-state nhk-admin-state--' . esc_attr($row['tone']) . '"><strong>' . esc_html($row['label']) . '</strong><span>' . esc_html($row['value']) . '</span></div>';
        }
        echo '</div></section>';
    }

    private static function renderOwnershipGuide(): void
    {
        echo '<section class="nhk-admin-panel" aria-labelledby="nhk-admin-owner-heading"><h2 id="nhk-admin-owner-heading">Ranh giới dữ liệu</h2>';
        echo '<div class="nhk-admin-owner-grid">';
        $owners = [
            ['WordPress', 'Tiêu đề, nội dung bài, excerpt, thứ tự biên tập và URL editorial.'],
            ['Authority / Knowledge / Evidence', 'Canonical identity, atomic claim và provenance theo đúng bounded context.'],
            ['Media / Video', 'Identity riêng; attachment hoặc nguồn ngoài chỉ là storage/projection theo contract.'],
            ['Governance', 'Proposal → duyệt → eligibility → Controlled Apply → read-back cho semantic mutation.'],
        ];
        foreach ($owners as [$name, $description]) echo '<div><strong>' . esc_html($name) . '</strong><span>' . esc_html($description) . '</span></div>';
        echo '</div></section>';
    }

    private static function adminHref(string $href): string
    {
        return admin_url(ltrim($href, '/'));
    }
}
