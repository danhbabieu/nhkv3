<?php
declare(strict_types=1);

namespace {
    if (!function_exists('esc_html')) { function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
    if (!function_exists('esc_attr')) { function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
    if (!function_exists('esc_url')) { function esc_url($value) { return esc_attr($value); } }
    if (!function_exists('admin_url')) { function admin_url($path = '') { return '/wp-admin/' . ltrim((string) $path, '/'); } }
    if (!function_exists('add_query_arg')) { function add_query_arg($args, $url = '') { return (string) $url . '?' . http_build_query((array) $args); } }
    if (!function_exists('selected')) { function selected($selected, $current, $echo = true) { $value = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ($echo) echo $value; return $value; } }
    if (!function_exists('wp_nonce_field')) { function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce">'; } }
}

namespace NHK\Tests\Unit\Admin {

use NHK\Core\Infrastructure\Admin\GovernanceQueueRenderer;
use PHPUnit\Framework\TestCase;

final class GovernanceQueueRendererTest extends TestCase
{
    private string $id = '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0001';

    public function test_queue_renders_current_page_selection_and_required_columns(): void
    {
        ob_start();
        GovernanceQueueRenderer::render($this->pageWithTwoItems());
        $html = (string) ob_get_clean();

        foreach (['Duyệt dữ liệu', 'select-all', 'proposal_id', 'Loại', 'Chủ thể / tên', 'Tóm tắt', 'Trạng thái', 'Nguồn', 'Ngày tạo', 'Cập nhật', 'name="bulk_action"'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringContainsString('method="post"', $html);
    }

    public function test_bulk_result_reports_partial_success_and_failed_ids(): void
    {
        ob_start();
        GovernanceQueueRenderer::renderNotice(['selected' => 3, 'succeeded' => 2, 'failed' => 1, 'failures' => [['proposal_id' => $this->id, 'reason' => 'TARGET_REVISION_CHANGED']]]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('2', $html);
        self::assertStringContainsString($this->id, $html);
        self::assertStringContainsString('TARGET_REVISION_CHANGED', $html);
    }

    public function test_bulk_result_reports_success_skipped_failed_and_reason_for_each(): void
    {
        ob_start();
        GovernanceQueueRenderer::renderNotice([
            'selected' => 3, 'succeeded' => 1, 'skipped' => 1, 'failed' => 1,
            'skipped_items' => [['proposal_id' => 'skip-1', 'reason' => 'INVALID_LIFECYCLE_ACTION']],
            'failures' => [['proposal_id' => 'fail-1', 'reason' => 'STALE_SNAPSHOT']],
        ]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('thành công <strong>1</strong>', $html);
        self::assertStringContainsString('bỏ qua <strong>1</strong>', $html);
        self::assertStringContainsString('thất bại <strong>1</strong>', $html);
        self::assertStringContainsString('skip-1', $html);
        self::assertStringContainsString('fail-1', $html);
        self::assertStringContainsString('Bản ghi đã thay đổi, cần tải lại (STALE_SNAPSHOT)', $html);
    }

    public function test_reconciliation_statuses_and_domain_message_are_rendered(): void
    {
        ob_start();
        GovernanceQueueRenderer::renderNotice([
            'selected' => 2, 'succeeded' => 1, 'skipped' => 0, 'failed' => 1,
            'failures' => [
                ['proposal_id' => 'video-1', 'status' => 'REBUILT_AND_APPLIED', 'reason' => 'NO_SEMANTIC_ATTACHMENT'],
                ['proposal_id' => 'video-2', 'status' => 'BLOCKED', 'reason' => 'EVIDENCE_REQUIRED'],
            ],
        ]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Đã dựng lại và áp dụng', $html);
        self::assertStringContainsString('Thiếu quan hệ semantic bắt buộc cho Video (NO_SEMANTIC_ATTACHMENT)', $html);
        self::assertStringContainsString('Thiếu Evidence cho quan hệ Video (EVIDENCE_REQUIRED)', $html);
    }

    public function test_payload_names_are_escaped_and_terminal_rows_have_no_mutation_form(): void
    {
        $page = $this->pageWithTwoItems();
        $page['items'][1]['name'] = '<script>alert(1)</script>';
        $page['items'][1]['status'] = 'rejected';
        ob_start();
        GovernanceQueueRenderer::render($page);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        $terminalRow = substr($html, (int) strpos($html, '0198f8d5-1d55-7a12'));
        self::assertStringNotContainsString('queue_action', $terminalRow);
    }

    public function test_row_actions_follow_the_sequential_workflow_and_use_vietnamese_labels(): void
    {
        $page = $this->pageWithTwoItems();
        $page['items'] = [
            array_replace($page['items'][0], ['status' => 'draft', 'status_label' => 'Chờ kiểm tra']),
            array_replace($page['items'][1], ['status' => 'submitted', 'status_label' => 'Chờ phê duyệt']),
            array_replace($page['items'][1], ['proposal_id' => '0198f8d5-1d55-7a13-8d4e-5f0d9d8d0004', 'status' => 'approved', 'status_label' => 'Đã phê duyệt']),
            array_replace($page['items'][1], ['proposal_id' => '0198f8d5-1d55-7a13-8d4e-5f0d9d8d0005', 'status' => 'applied', 'status_label' => 'Đã áp dụng']),
        ];
        ob_start();
        GovernanceQueueRenderer::render($page);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Kiểm tra', $html);
        self::assertStringContainsString('Phê duyệt', $html);
        self::assertStringContainsString('Từ chối', $html);
        self::assertStringContainsString('Áp dụng', $html);
        self::assertStringNotContainsString('Approve', $html);
        self::assertStringNotContainsString('Apply', $html);
        self::assertStringNotContainsString('Xem chi tiết / Review', $html);
        $appliedRow = substr($html, (int) strpos($html, '0198f8d5-1d55-7a13-8d4e-5f0d9d8d0005'));
        self::assertStringNotContainsString('queue_action', $appliedRow);
        $bulkStart = strpos($html, 'data-nhk-governance-bulk-form');
        $bulkEnd = strpos($html, '</form>', $bulkStart);
        self::assertNotFalse($bulkStart);
        self::assertNotFalse($bulkEnd);
        self::assertStringNotContainsString('nhk-governance-row-action', substr($html, (int) $bulkStart, (int) $bulkEnd - (int) $bulkStart));
    }

    public function test_pagination_uses_requested_page_and_preserves_all_filters(): void
    {
        $page = $this->pageWithTwoItems();
        $page['page'] = 2;
        $page['total_pages'] = 3;
        $page['filters'] = ['search' => 'vertical', 'status' => 'submitted', 'type' => 'knowledge', 'order_by' => 'name', 'order' => 'asc', 'page' => 2, 'per_page' => 10];
        ob_start();
        GovernanceQueueRenderer::render($page);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('paged=1', $html);
        self::assertStringContainsString('paged=3', $html);
        self::assertStringContainsString('search=vertical', $html);
        self::assertStringContainsString('status=submitted', $html);
        self::assertStringContainsString('type=knowledge', $html);
        self::assertStringContainsString('order_by=name', $html);
        self::assertStringContainsString('order=asc', $html);
        self::assertStringContainsString('per_page=10', $html);
    }

    /** @return array<string,mixed> */
    private function pageWithTwoItems(): array
    {
        return [
            'availability' => 'available', 'diagnostics' => [], 'total_items' => 2, 'total_pages' => 1,
            'page' => 1, 'per_page' => 20,
            'filters' => ['search' => '', 'status' => '', 'type' => '', 'order_by' => 'updated', 'order' => 'desc', 'page' => 1, 'per_page' => 20],
            'items' => [
                ['proposal_id' => $this->id, 'entity_type' => 'brand', 'subject_id' => '0198f8d5-1d55-7a11-8d4e-5f0d9d8d0002', 'name' => 'Vertical Brand', 'summary' => 'Đổi tên', 'status' => 'draft', 'status_label' => 'Bản nháp', 'provenance_summary' => 'Catalog', 'created_at' => '2026-09-10 01:02:03', 'updated_at' => '2026-09-10 04:05:06', 'revision' => 3, 'content_fingerprint' => str_repeat('a', 64), 'dependency_fingerprint' => str_repeat('b', 64), 'actionable' => true, 'operation' => 'rename'],
                ['proposal_id' => '0198f8d5-1d55-7a12-8d4e-5f0d9d8d0003', 'entity_type' => 'knowledge', 'subject_id' => 'subject', 'name' => 'Other', 'summary' => 'Summary', 'status' => 'approved', 'status_label' => 'Đã duyệt', 'provenance_summary' => '', 'created_at' => '2026-09-10 01:02:03', 'updated_at' => '2026-09-10 04:05:06', 'revision' => 2, 'content_fingerprint' => str_repeat('c', 64), 'dependency_fingerprint' => str_repeat('d', 64), 'actionable' => true, 'operation' => 'update'],
            ],
        ];
    }
}
}
