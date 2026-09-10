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
