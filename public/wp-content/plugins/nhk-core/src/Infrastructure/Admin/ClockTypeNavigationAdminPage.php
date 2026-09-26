<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Presentation\ClockTypeNavigationProjection;
use NHK\Core\Contracts\PresentationNavigation\NavigationRepository;
use NHK\Core\Domain\PresentationNavigation\NavigationNode;

final class ClockTypeNavigationAdminPage
{
    public static function register(): void
    {
        add_submenu_page('nhk-v3', 'LOẠI đồng hồ', 'LOẠI đồng hồ', 'manage_options', 'nhk-v3-clock-type-navigation', [self::class, 'render']);
        add_action('admin_post_nhk_clock_type_navigation_save', [self::class, 'save']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền quản trị LOẠI.');
        $repository = self::repository();
        try { $nodes = $repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY); } catch (\Throwable $error) { echo '<div class="wrap"><h1>LOẠI đồng hồ</h1><p class="notice notice-warning">Navigation storage chưa sẵn sàng: ' . esc_html($error->getMessage()) . '</p></div>'; return; }
        $byParent = [];
        foreach ($nodes as $node) $byParent[(string) ($node->parentId ?? '')][] = $node;
        foreach ($byParent as &$siblings) usort($siblings, static fn (NavigationNode $left, NavigationNode $right): int => [$left->sortOrder, $left->id] <=> [$right->sortOrder, $right->id]);
        unset($siblings);
        echo '<div class="wrap"><h1>LOẠI đồng hồ</h1><p>Đây là cây Presentation Navigation; Classification và Graph semantic không bị thay đổi.</p><ul class="nhk-navigation-tree" data-navigation-key="clock_type">';
        self::renderTree($byParent, '', 0);
        echo '</ul><p class="description">Kéo node lên một node khác để đổi cha và đặt nó ngay sau node đó; mọi lưu thay đổi đều dùng optimistic revision.</p><script>(function(){document.querySelectorAll(".nhk-navigation-tree [draggable]").forEach(function(row){row.addEventListener("dragstart",function(e){e.dataTransfer.setData("text/plain",row.dataset.nodeId);});row.addEventListener("dragover",function(e){e.preventDefault();});row.addEventListener("drop",function(e){e.preventDefault();var source=e.dataTransfer.getData("text/plain"),target=row.dataset.nodeId;if(source&&target&&source!==target){var form=document.querySelector("form[data-node-id=\""+source+"\"]"),targetForm=document.querySelector("form[data-node-id=\""+target+"\"]");if(form&&targetForm){form.querySelector("[name=parent_id]").value=targetForm.querySelector("[name=parent_id]").value;form.querySelector("[name=sort_order]").value=parseInt(targetForm.querySelector("[name=sort_order]").value||"0",10)+1;form.submit();}}});});})();</script></div>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Bạn không có quyền quản trị LOẠI.');
        check_admin_referer('nhk_clock_type_navigation_save');
        $id = sanitize_text_field((string) ($_POST['id'] ?? ''));
        $repository = self::repository();
        foreach ($repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY) as $node) {
            if ($node->id !== $id) continue;
            $row = $node->toArray();
            $row['parent_id'] = trim((string) ($_POST['parent_id'] ?? '')) ?: null;
            $row['sort_order'] = max(0, (int) ($_POST['sort_order'] ?? $node->sortOrder));
            foreach (['enabled', 'show_in_type_index', 'show_in_header_menu', 'show_in_mobile_menu', 'show_in_sidebar', 'featured'] as $field) $row[$field] = isset($_POST[$field]);
            $repository->save(NavigationNode::fromArray($row), $node->revision);
            break;
        }
        wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-clock-type-navigation', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /** @param array<string,list<NavigationNode>> $byParent */
    private static function renderTree(array $byParent, string $parentId, int $depth): void
    {
        foreach ($byParent[$parentId] ?? [] as $node) {
            echo '<li class="nhk-navigation-node" draggable="true" data-node-id="' . esc_attr($node->id) . '" style="margin-left:' . esc_attr((string) ($depth * 24)) . 'px"><strong>' . esc_html($node->canonicalUuid) . '</strong><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-node-id="' . esc_attr($node->id) . '"><input type="hidden" name="action" value="nhk_clock_type_navigation_save"><input type="hidden" name="id" value="' . esc_attr($node->id) . '"><input type="hidden" name="parent_id" value="' . esc_attr((string) ($node->parentId ?? '')) . '"><label>Thứ tự <input type="number" min="0" name="sort_order" value="' . esc_attr((string) $node->sortOrder) . '"></label> ';
            wp_nonce_field('nhk_clock_type_navigation_save');
            foreach (['enabled', 'show_in_type_index', 'show_in_header_menu', 'show_in_mobile_menu', 'show_in_sidebar', 'featured'] as $field) echo '<label><input type="checkbox" name="' . esc_attr($field) . '" value="1"' . ($node->{self::property($field)} ? ' checked' : '') . '> ' . esc_html($field) . '</label> ';
            echo '<button class="button" type="submit">Lưu</button></form></li>';
            self::renderTree($byParent, $node->id, $depth + 1);
        }
    }

    private static function property(string $field): string { return match ($field) { 'enabled' => 'enabled', 'show_in_type_index' => 'showInTypeIndex', 'show_in_header_menu' => 'showInHeaderMenu', 'show_in_mobile_menu' => 'showInMobileMenu', 'show_in_sidebar' => 'showInSidebar', 'featured' => 'featured' }; }

    private static function repository(): NavigationRepository
    {
        global $wpdb;
        return new \NHK\Core\Infrastructure\Presentation\WpdbNavigationRepository($wpdb);
    }
}
