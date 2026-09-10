<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog;
use NHK\Core\Domain\Governance\ProposalState;

final class GovernanceQueueRenderer
{
    /** @param array<string,mixed> $page */
    public static function render(array $page): void
    {
        $filters = is_array($page['filters'] ?? null) ? $page['filters'] : [];
        echo '<section class="nhk-admin-panel nhk-governance-queue" aria-labelledby="nhk-governance-queue-heading">';
        echo '<h2 id="nhk-governance-queue-heading">Duyệt dữ liệu</h2><p>Hàng đợi Proposal hiện có; mọi thay đổi vẫn đi qua Governance và đọc lại canonical. Chi tiết kỹ thuật chỉ được gửi qua snapshot ẩn khi thực hiện thao tác.</p>';
        self::renderNoticeFromRequest();
        self::renderFilters($filters);

        $availability = (string) ($page['availability'] ?? 'unavailable');
        if ($availability === 'unavailable') {
            echo '<div class="notice notice-error"><p>Hàng đợi hiện không khả dụng. Không thể kết luận rằng không có dữ liệu.</p></div></section>';
            return;
        }
        if ($availability === 'blocked') echo '<div class="notice notice-warning"><p>Hàng đợi có bản ghi bị chặn; các chẩn đoán được giữ nguyên để rà soát.</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nhk-governance-bulk-form" data-nhk-governance-bulk-form>';
        echo '<input type="hidden" name="action" value="nhk_governance_queue_bulk">';
        wp_nonce_field('nhk_governance_queue_action');
        echo '<label for="nhk-governance-bulk-action">Thao tác đã chọn</label><select id="nhk-governance-bulk-action" name="bulk_action"><option value="">Chọn thao tác</option><option value="submit">Gửi duyệt</option><option value="approve">Approve</option><option value="reject">Từ chối</option><option value="apply">Apply</option></select> <button class="button" type="submit">Áp dụng cho trang hiện tại</button>';
        echo '<table class="widefat striped"><thead><tr><th scope="col" class="check-column"><input type="checkbox" id="select-all" data-nhk-select-all aria-label="Chọn tất cả trang hiện tại"></th><th>proposal_id</th><th>Loại</th><th>Chủ thể / tên</th><th>Tóm tắt</th><th>Trạng thái</th><th>Nguồn</th><th>Ngày tạo</th><th>Cập nhật</th><th>Thao tác</th></tr></thead><tbody>';
        $items = is_array($page['items'] ?? null) ? $page['items'] : [];
        if ($items === []) echo '<tr><td colspan="10">Chưa có Proposal phù hợp.</td></tr>';
        foreach ($items as $item) self::renderRow(is_array($item) ? $item : []);
        echo '</tbody></table></form>';
        self::renderPagination($page, $filters);
        echo '</section>';
    }

    /** @param array<string,mixed> $result */
    public static function renderNotice(array $result): void
    {
        $failed = (int) ($result['failed'] ?? 0);
        $class = $failed > 0 ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . '" role="status" tabindex="-1" data-nhk-governance-result><p>Đã chọn <strong>' . esc_html((string) ($result['selected'] ?? 0)) . '</strong>; thành công <strong>' . esc_html((string) ($result['succeeded'] ?? 0)) . '</strong>; thất bại <strong>' . esc_html((string) $failed) . '</strong>.</p>';
        if ($failed > 0) {
            echo '<ul>';
            foreach ((array) ($result['failures'] ?? []) as $failure) {
                if (!is_array($failure)) continue;
                echo '<li>' . esc_html((string) ($failure['proposal_id'] ?? '')) . ': ' . esc_html(self::failureReason($failure['reason'] ?? 'OPERATION_FAILED')) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $filters */
    private static function renderFilters(array $filters): void
    {
        echo '<form method="get" class="nhk-governance-filters"><input type="hidden" name="page" value="nhk-v3-governance"><label for="nhk-governance-search">Tìm kiếm</label><input id="nhk-governance-search" type="search" name="search" value="' . esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="ID, tên, subject hoặc entity">';
        echo '<label for="nhk-governance-status">Trạng thái</label><select id="nhk-governance-status" name="status"><option value="">Tất cả</option>';
        foreach (ProposalState::cases() as $state) echo '<option value="' . esc_attr($state->value) . '"' . selected((string) ($filters['status'] ?? ''), $state->value, false) . '>' . esc_html(self::statusLabel($state)) . '</option>';
        echo '</select><label for="nhk-governance-type">Loại</label><select id="nhk-governance-type" name="type"><option value="">Tất cả</option>';
        $types = array_map(static fn ($definition): string => $definition->type, CanonicalEntityTypeCatalog::definitions());
        foreach (['knowledge', 'source', 'evidence', 'media', 'video', 'wp_post', 'relation'] as $type) $types[] = $type;
        foreach (array_values(array_unique($types)) as $type) echo '<option value="' . esc_attr($type) . '"' . selected((string) ($filters['type'] ?? ''), $type, false) . '>' . esc_html($type) . '</option>';
        echo '</select><label for="nhk-governance-sort">Sắp xếp</label><select id="nhk-governance-sort" name="order_by">';
        foreach (['created' => 'Ngày tạo', 'updated' => 'Cập nhật', 'name' => 'Tên', 'id' => 'ID', 'status' => 'Trạng thái'] as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected((string) ($filters['order_by'] ?? 'updated'), $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select><select name="order" aria-label="Thứ tự"><option value="desc"' . selected((string) ($filters['order'] ?? 'desc'), 'desc', false) . '>Giảm dần</option><option value="asc"' . selected((string) ($filters['order'] ?? 'desc'), 'asc', false) . '>Tăng dần</option></select><button class="button button-primary" type="submit">Lọc</button></form>';
    }

    /** @param array<string,mixed> $item */
    private static function renderRow(array $item): void
    {
        $id = (string) ($item['proposal_id'] ?? '');
        $status = (string) ($item['status'] ?? 'blocked');
        $actionable = ($item['actionable'] ?? true) === true;
        $bulkFields = '<input type="hidden" name="snapshots[' . esc_attr($id) . '][revision]" value="' . esc_attr((string) ($item['revision'] ?? 0)) . '"><input type="hidden" name="snapshots[' . esc_attr($id) . '][state]" value="' . esc_attr($status) . '"><input type="hidden" name="snapshots[' . esc_attr($id) . '][content_fingerprint]" value="' . esc_attr((string) ($item['content_fingerprint'] ?? '')) . '"><input type="hidden" name="snapshots[' . esc_attr($id) . '][dependency_fingerprint]" value="' . esc_attr((string) ($item['dependency_fingerprint'] ?? '')) . '">';
        echo '<tr><td class="check-column">' . ($actionable ? '<input type="checkbox" name="selected_ids[]" value="' . esc_attr($id) . '" data-nhk-queue-item>' . $bulkFields : '') . '</td><td><code>' . esc_html($id) . '</code></td><td>' . esc_html((string) ($item['entity_type'] ?? '')) . '</td><td>' . esc_html((string) ($item['name'] ?? ($item['subject_id'] ?? 'Không khả dụng'))) . '<br><small>' . esc_html((string) ($item['subject_id'] ?? '')) . '</small></td><td>' . esc_html((string) ($item['summary'] ?? '')) . '</td><td>' . esc_html((string) ($item['status_label'] ?? $status)) . '</td><td>' . esc_html((string) ($item['provenance_summary'] ?? 'Chưa có')) . '</td><td>' . esc_html((string) ($item['created_at'] ?? '')) . '</td><td>' . esc_html((string) ($item['updated_at'] ?? '')) . '</td><td>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=nhk-v3-advanced&proposal=' . rawurlencode($id) . '#governance')) . '">Xem chi tiết / Review</a>';
        if ($actionable) {
            foreach (self::actionsFor($status) as $action => $label) self::renderActionForm($action, $label, $item);
        }
        echo '</td></tr>';
    }

    /** @param array<string,mixed> $item */
    private static function renderActionForm(string $action, string $label, array $item): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nhk-governance-row-action"><input type="hidden" name="action" value="nhk_governance_queue_action"><input type="hidden" name="queue_action" value="' . esc_attr($action) . '"><input type="hidden" name="proposal_id" value="' . esc_attr((string) ($item['proposal_id'] ?? '')) . '"><input type="hidden" name="revision" value="' . esc_attr((string) ($item['revision'] ?? 0)) . '"><input type="hidden" name="state" value="' . esc_attr((string) ($item['status'] ?? '')) . '"><input type="hidden" name="content_fingerprint" value="' . esc_attr((string) ($item['content_fingerprint'] ?? '')) . '"><input type="hidden" name="dependency_fingerprint" value="' . esc_attr((string) ($item['dependency_fingerprint'] ?? '')) . '">';
        wp_nonce_field('nhk_governance_queue_action');
        echo '<button class="button-link" type="submit">' . esc_html($label) . '</button></form>';
    }

    /** @return array<string,string> */
    private static function actionsFor(string $status): array
    {
        return match ($status) {
            'draft' => ['submit' => 'Gửi duyệt', 'approve' => 'Approve', 'reject' => 'Từ chối'],
            'submitted' => ['approve' => 'Approve', 'reject' => 'Từ chối'],
            'approved' => ['apply' => 'Apply'],
            default => [],
        };
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $filters */
    private static function renderPagination(array $page, array $filters): void
    {
        $total = (int) ($page['total_pages'] ?? 0); $current = (int) ($page['page'] ?? 1);
        if ($total < 2) return;
        echo '<nav class="tablenav bottom" aria-label="Phân trang">';
        for ($number = 1; $number <= $total; $number++) {
            $args = array_merge(['page' => 'nhk-v3-governance', 'paged' => $number], $filters);
            echo $number === $current ? '<span class="page-numbers current">' . esc_html((string) $number) . '</span> ' : '<a class="page-numbers" href="' . esc_url(add_query_arg($args, admin_url('admin.php'))) . '">' . esc_html((string) $number) . '</a> ';
        }
        echo '</nav>';
    }

    private static function renderNoticeFromRequest(): void
    {
        $encoded = isset($_GET['nhk_queue_result']) ? sanitize_text_field((string) $_GET['nhk_queue_result']) : '';
        if ($encoded === '') return;
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $result = is_string($json) ? json_decode($json, true) : null;
        if (is_array($result)) self::renderNotice($result);
    }

    private static function statusLabel(ProposalState $state): string
    {
        return ['draft' => 'Bản nháp', 'submitted' => 'Đã gửi duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy', 'superseded' => 'Đã thay thế', 'applied' => 'Đã áp dụng'][$state->value] ?? $state->value;
    }

    private static function failureReason(mixed $reason): string
    {
        if (is_array($reason)) return implode(', ', array_map('strval', $reason));
        return (string) $reason;
    }
}
