<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;

/**
 * Task-first Admin shell. Existing domain/application writers remain unchanged.
 */
final class AdminWorkbenchPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu'], 11);
        add_action('admin_post_nhk_governance_automation_policy', [self::class, 'saveAutomationPolicy']);
        GovernanceQueueAdminPage::register();
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
        add_submenu_page('nhk-v3', 'Nội dung', 'Nội dung', 'edit_posts', 'nhk-v3-content', [self::class, 'renderContent']);
        add_submenu_page('nhk-v3', 'Media', 'Media', 'upload_files', 'nhk-v3-media', [self::class, 'renderMedia']);
        add_submenu_page('nhk-v3', 'Tri thức', 'Tri thức', 'nhk_view_governance', 'nhk-v3-knowledge', [self::class, 'renderKnowledge']);
        add_submenu_page('nhk-v3', 'Duyệt dữ liệu', 'Duyệt dữ liệu', 'nhk_view_governance', 'nhk-v3-governance', [GovernanceQueueAdminPage::class, 'render']);
        add_submenu_page('nhk-v3', 'Hệ thống', 'Hệ thống', 'manage_options', 'nhk-v3-system', [self::class, 'renderSystem']);
        add_submenu_page('nhk-v3', 'Phê duyệt & xuất bản tự động', 'Phê duyệt & xuất bản tự động', 'manage_options', 'nhk-v3-automation-policy', [self::class, 'renderAutomationPolicy']);
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

    public static function renderContent(): void { self::renderWorkspace('Nội dung', 'Bài viết và Video trong một workbench chung.', 'content'); }
    public static function renderMedia(): void { self::renderWorkspace('Media', 'Media identity, assets, usage và projection theo contract.', 'media'); }
    public static function renderKnowledge(): void { self::renderWorkspace('Tri thức', 'Entity, Claim, Source, Evidence và Relation trong một ô tìm kiếm.', 'knowledge'); }
    // Chi tiết kỹ thuật remains available through the existing Nâng cao screen.
    public static function renderGovernance(): void { GovernanceQueueAdminPage::render(); }
    public static function renderSystem(): void { self::renderWorkspace('Hệ thống', 'Health, readiness và runtime diagnostics read-only.', 'system'); }

    public static function renderAutomationPolicy(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền thay đổi chính sách tự động.');
        $types = self::automationTypes();
        $storage = new \NHK\Core\Infrastructure\Governance\WpOptionAutomationPolicyStorage(array_keys($types));
        $resolver = new \NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver(array_keys($types), $storage);
        $authorityPolicyStorage = new \NHK\Core\Infrastructure\Governance\WpOptionConversationalAuthorityPolicyStorage();
        $authorityPolicy = $authorityPolicyStorage->read();
        echo '<div class="wrap nhk-admin-workbench"><header class="nhk-admin-hero"><div><p class="nhk-admin-eyebrow">NHK V3 · Hệ thống</p><h1>Phê duyệt &amp; xuất bản tự động</h1><p class="nhk-admin-lead">Cấu hình cách dữ liệu từ MCP đi qua Governance. Human review is configurable; Governance gates are not.</p></div></header>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Đã lưu chính sách tự động.</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error"><p>Không thể lưu chính sách tự động. Kiểm tra quyền và giá trị đã chọn.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="nhk_governance_automation_policy">';
        wp_nonce_field('nhk_governance_automation_policy_save');
        echo '<table class="widefat striped"><thead><tr><th>Loại dữ liệu</th><th>Chế độ</th><th>Giải thích</th></tr></thead><tbody>';
        foreach ($types as $type => $definition) {
            $mode = $resolver->resolve($type)->value;
            echo '<tr><th scope="row">' . esc_html($definition['label']) . '</th><td><select name="policies[' . esc_attr($type) . ']" aria-label="Chế độ ' . esc_attr($definition['label']) . '">';
            foreach ([
                'REVIEW_REQUIRED' => 'Cần phê duyệt',
                'AUTO_APPROVE' => 'Tự động phê duyệt',
                'AUTO_PUBLISH' => 'Tự động phê duyệt & xuất bản',
            ] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($mode, $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select></td><td>' . esc_html($definition['description']);
            if ($mode === 'AUTO_PUBLISH') echo '<br><strong>Cảnh báo:</strong> Dữ liệu hợp lệ từ MCP sẽ được tự động phê duyệt, Apply và xuất bản mà không cần thao tác thủ công.';
            echo '</td></tr>';
        }
        echo '</tbody></table><h2>Conversational Authority Creation</h2><p>AI luôn phải preview và owner phải xác nhận. Chế độ này không tạo semantic writer riêng và không vượt qua Governance tổng thể.</p><p><label for="nhk-conversational-authority-policy">Chế độ tạo Authority qua hội thoại</label> <select id="nhk-conversational-authority-policy" name="conversational_authority_policy">';
        foreach (['OFF' => 'Tắt', 'REVIEW_REQUIRED' => 'Cần review', 'AUTO_APPROVE_AFTER_OWNER_CONFIRMATION' => 'Tự động sau owner xác nhận'] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($authorityPolicy->value, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></p><p><button class="button button-primary" type="submit">Lưu</button></p></form></div>';
    }

    public static function saveAutomationPolicy(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền thay đổi chính sách tự động.', '', ['response' => 403]);
        check_admin_referer('nhk_governance_automation_policy_save');
        $types = self::automationTypes();
        try {
            $raw = isset($_POST['policies']) && is_array($_POST['policies']) ? wp_unslash($_POST['policies']) : [];
            $policies = [];
            foreach ($raw as $type => $mode) $policies[(string) $type] = (string) $mode;
            $authorityPolicy = \NHK\Core\Domain\Governance\ConversationalAuthorityPolicy::tryFrom((string) ($_POST['conversational_authority_policy'] ?? ''));
            if ($authorityPolicy === null) throw new \InvalidArgumentException('Invalid conversational Authority policy.');
            (new \NHK\Core\Infrastructure\Governance\WpOptionAutomationPolicyStorage(array_keys($types)))->write($policies);
            (new \NHK\Core\Infrastructure\Governance\WpOptionConversationalAuthorityPolicyStorage())->write($authorityPolicy);
            wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-automation-policy', 'saved' => '1'], admin_url('admin.php')));
        } catch (\Throwable) {
            wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-automation-policy', 'error' => '1'], admin_url('admin.php')));
        }
        exit;
    }

    private static function renderWorkspace(string $title, string $description, string $workspace): void
    {
        if (!current_user_can('manage_options') && !current_user_can('nhk_view_governance') && !current_user_can('edit_posts') && !current_user_can('upload_files')) wp_die('Bạn không có quyền xem khu vực này.');
        echo '<div class="wrap nhk-admin-workbench" data-nhk-workspace="' . esc_attr($workspace) . '">';
        echo '<header class="nhk-admin-hero"><div><p class="nhk-admin-eyebrow">NHK V3 · Admin Workbench</p><h1>' . esc_html($title) . '</h1><p class="nhk-admin-lead">' . esc_html($description) . '</p></div><div class="nhk-admin-hero__boundary"><strong>Luật vận hành</strong><span>Admin là adapter; semantic mutation vẫn đi qua Governance và canonical read-back.</span></div></header>';
        self::renderNavigation((new AdminWorkbenchRegistry())->sections());
        if ($workspace === 'content') self::renderContentWorkspace();
        elseif ($workspace === 'media') self::renderMediaWorkspace();
        elseif ($workspace === 'knowledge') self::renderKnowledgeWorkspace();
        elseif ($workspace === 'governance') GovernanceQueueAdminPage::render();
        else self::renderSystemWorkspace();
        echo '</div>';
    }

    private static function renderContentWorkspace(): void
    {
        echo '<section class="nhk-admin-panel"><h2>Capture nội dung mới</h2><p>Submission mới bắt đầu tại một cửa duy nhất; tìm kiếm và đọc lại nằm bên dưới.</p>';
        AdminPage::renderEditorialCapture('Capture canonical trong Admin Workbench.');
        AdminPage::captureScripts();
        echo '</section>';
        echo '<section class="nhk-admin-panel"><nav class="nhk-admin-tabs" aria-label="Loại nội dung"><a class="is-active" href="#nhk-content-articles">Bài viết</a><a href="#nhk-content-videos">Video</a></nav><form class="nhk-admin-search" data-nhk-search="content"><label for="nhk-content-query">Tìm kiếm</label><input id="nhk-content-query" name="q" type="search" minlength="2" placeholder="Tiêu đề, YouTube ID hoặc canonical UUID"><button class="button button-primary">Tìm</button></form><div id="nhk-content-results" aria-live="polite"><p class="nhk-admin-empty">Nhập từ khóa để tra cứu Nội dung.</p></div></section>';
        echo '<section id="nhk-content-videos" class="nhk-admin-panel"><h2>Video workspace</h2><p>Chọn Video từ kết quả tìm kiếm để mở detail, player, provenance, relation và read-back.</p><div id="nhk-video-detail" class="nhk-admin-detail" aria-live="polite"></div></section>';
    }

    private static function renderMediaWorkspace(): void
    {
        echo '<section class="nhk-admin-panel"><nav class="nhk-admin-tabs" aria-label="Không gian Media"><a class="is-active" href="#nhk-media-all">Tất cả</a><a href="#nhk-media-images">Hình ảnh</a><a href="#nhk-media-videos">Video</a></nav><form class="nhk-admin-search" data-nhk-search="media"><label for="nhk-media-query">Tìm Media</label><input id="nhk-media-query" name="q" type="search" minlength="2" placeholder="Tên, stable key hoặc UUID"><button class="button button-primary">Tìm</button></form><div id="nhk-media-results" aria-live="polite"><p class="nhk-admin-empty">Nhập từ khóa để tra cứu Media.</p></div></section>';
        echo '<section id="nhk-media-all" class="nhk-admin-panel"><h2>Tất cả Media</h2><p>Ảnh và Video được đọc từ canonical owner và projection hiện có.</p></section>';
        echo '<section id="nhk-media-images" class="nhk-admin-panel"><h2>Hình ảnh</h2><p>Chi tiết asset, semantic role, usage, readiness và provenance được hiển thị theo policy.</p><div id="nhk-image-detail" class="nhk-admin-detail" aria-live="polite"></div></section>';
        echo '<section id="nhk-media-videos" class="nhk-admin-panel"><h2>Video</h2><p>Chọn Video để xem player, canonical identity, relation, evidence và frontend read-back.</p><nav class="nhk-admin-tabs" aria-label="Chi tiết Media/Video">';
        foreach (['Tổng quan', 'Nội dung & SEO', 'Vai trò & Quan hệ', 'Tri thức', 'Nguồn & Evidence', 'Sử dụng', 'Governance', 'Kỹ thuật'] as $tab) echo '<a href="#nhk-media-video-' . esc_attr(sanitize_title($tab)) . '">' . esc_html($tab) . '</a>';
        echo '</nav><div id="nhk-video-detail" class="nhk-admin-detail" aria-live="polite"></div></section>';
        echo '<section class="nhk-admin-panel"><h2>Guided attachment</h2><p>Attachment/relation chỉ khả dụng khi writer Media V3 hiện có và đủ quyền. Không nhập UUID Evidence hoặc proposal trong workflow này.</p><div id="nhk-media-guided-state" class="nhk-admin-state nhk-admin-state--neutral">Chưa chọn Media.</div></section>';
    }

    private static function renderKnowledgeWorkspace(): void
    {
        echo '<section class="nhk-admin-panel"><h2>Tri thức</h2><form class="nhk-admin-search" data-nhk-search="knowledge"><label for="nhk-knowledge-query">Tìm semantic</label><input id="nhk-knowledge-query" name="q" type="search" minlength="2" placeholder="Tên entity, claim, source hoặc evidence"><select name="tab" aria-label="Loại tri thức"><option value="entity">Thực thể</option><option value="claim">Claim</option><option value="source">Nguồn</option><option value="evidence">Evidence</option><option value="relation">Quan hệ</option></select><button class="button button-primary">Tìm</button></form><div id="nhk-knowledge-results" aria-live="polite"><p class="nhk-admin-empty">Nhập từ khóa semantic để bắt đầu.</p></div></section>';
    }

    private static function renderSystemWorkspace(): void
    {
        $status = new MigrationStatus();
        $workspace = AdminWorkspaceViewModel::fromHealth((new HealthCheck($status))->read(), [], []);
        echo '<section class="nhk-admin-panel"><h2>Runtime health</h2><p>Read-only. Runtime failure không được hiển thị thành empty success.</p><table class="widefat striped"><tbody>';
        foreach ($workspace['health'] as $item) echo '<tr><th>' . esc_html((string) ($item['label'] ?? '')) . '</th><td><strong>' . esc_html((string) ($item['state_label'] ?? 'Không khả dụng')) . '</strong> — ' . esc_html((string) ($item['display'] ?? 'Không khả dụng')) . '</td></tr>';
        echo '</tbody></table></section><section class="nhk-admin-panel"><h2>Phê duyệt &amp; xuất bản tự động</h2><p>Cấu hình mode theo loại dữ liệu; các gate Governance vẫn bắt buộc.</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=nhk-v3-automation-policy')) . '">Mở cấu hình</a></p></section><section class="nhk-admin-panel"><h2>Raw/technical tooling</h2><p>Các thao tác migration, proposal raw và diagnostics chi tiết vẫn ở <a href="' . esc_url(admin_url('admin.php?page=nhk-v3-advanced#system')) . '">Nâng cao</a>.</p></section>';
    }

    /** @return array<string,array{label:string,description:string}> */
    private static function automationTypes(): array
    {
        $registry = new \NHK\Core\Domain\Authority\EntityTypeRegistry();
        \NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::registerInto($registry);
        $labels = ['wp_post' => 'Bài viết / Content', 'media' => 'Hình ảnh / Media', 'video' => 'Video', 'knowledge' => 'Tri thức / Knowledge', 'source' => 'Nguồn / Source', 'evidence' => 'Evidence'];
        $types = [];
        foreach ($registry->all() as $definition) $types[$definition->type] = ['label' => $labels[$definition->type] ?? $definition->type, 'description' => 'Dữ liệu canonical được xử lý qua Governance.'];
        foreach ($labels as $type => $label) if (!isset($types[$type])) $types[$type] = ['label' => $label, 'description' => 'Dữ liệu được tiếp nhận qua boundary hiện hành.'];
        return $types;
    }

    /** @param list<array<string,string>> $sections */
    private static function renderNavigation(array $sections): void
    {
        echo '<nav class="nhk-admin-nav" aria-label="Khu vực quản trị NHK V3"><ul>';
        foreach ($sections as $section) {
            if (!self::isPrimarySection((string) $section['id'])) continue;
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
            if (!self::isPrimarySection((string) $section['id'])) continue;
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

    private static function isPrimarySection(string $id): bool
    {
        return in_array($id, ['content', 'media', 'knowledge', 'governance', 'system', 'advanced'], true);
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
