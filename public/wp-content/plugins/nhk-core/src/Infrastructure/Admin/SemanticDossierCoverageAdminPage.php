<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Entity\SemanticDossierCoverageAudit;

/** Read-only diagnostic UI. It has no semantic mutation path. */
final class SemanticDossierCoverageAdminPage
{
    public function __construct(private SemanticDossierCoverageAudit $audit) {}

    public function register(): void
    {
        if (!function_exists('add_action')) return;
        add_action('admin_menu', function (): void {
            add_submenu_page('nhk-v3', 'Semantic dossier coverage', 'Dossier coverage', 'manage_options', 'nhk-v3-dossier-coverage', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) wp_die('You do not have permission to view this page.');
            return;
        }

        $report = $this->audit->run();
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $items = is_array($report['items'] ?? null) ? $report['items'] : [];
        $typeFilter = function_exists('sanitize_key') ? sanitize_key((string) ($_GET['entity_type'] ?? '')) : trim((string) ($_GET['entity_type'] ?? ''));
        $statusFilter = function_exists('sanitize_key') ? strtoupper(sanitize_key((string) ($_GET['coverage_status'] ?? ''))) : strtoupper(trim((string) ($_GET['coverage_status'] ?? '')));
        if ($typeFilter !== '') $items = array_values(array_filter($items, static fn(array $item): bool => ($item['type'] ?? '') === $typeFilter));
        if ($statusFilter !== '') $items = array_values(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === $statusFilter));

        echo '<div class="wrap"><h1>Semantic dossier coverage</h1>';
        echo '<p>Read-only audit: phản ánh dữ liệu hiện có thể chiếu ra frontend. Một khoảng trống không đồng nghĩa hệ thống được phép tự tạo quan hệ hoặc dữ liệu mới.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px;max-width:1000px;margin:18px 0">';
        $cards = [
            'Entity' => (int) ($summary['entity_count'] ?? 0),
            'Public ready' => (int) ($summary['public_ready_count'] ?? 0),
            'Not public ready' => (int) ($summary['not_public_ready_count'] ?? 0),
            'Complete core' => (int) ($summary['complete_core_count'] ?? 0),
            'Coverage gaps' => (int) ($summary['coverage_gap_count'] ?? 0),
        ];
        foreach ($cards as $label => $count) echo '<div class="card" style="padding:14px"><strong style="display:block;font-size:24px">' . esc_html((string) $count) . '</strong><span>' . esc_html($label) . '</span></div>';
        echo '</div>';

        echo '<form method="get" style="margin:18px 0"><input type="hidden" name="page" value="nhk-v3-dossier-coverage">';
        echo '<label>Type <input name="entity_type" value="' . esc_attr($typeFilter) . '" placeholder="movement"></label> ';
        echo '<label>Status <select name="coverage_status"><option value="">All</option>';
        foreach (['COMPLETE_CORE','COVERAGE_GAPS','NOT_PUBLIC_READY'] as $status) echo '<option value="' . esc_attr($status) . '"' . selected($statusFilter, $status, false) . '>' . esc_html($status) . '</option>';
        echo '</select></label> <button class="button">Filter</button></form>';

        echo '<table class="widefat striped"><thead><tr><th>Type / Name</th><th>Status</th><th>Graph</th><th>Knowledge</th><th>Evidence</th><th>Images</th><th>Video</th><th>Articles</th><th>Gaps</th></tr></thead><tbody>';
        if ($items === []) echo '<tr><td colspan="9">No records match the current filter.</td></tr>';
        foreach ($items as $item) {
            $url = trim((string) ($item['public_url'] ?? ''));
            $name = esc_html((string) ($item['name'] ?? ''));
            if ($url !== '') $name = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $name . '</a>';
            $gaps = is_array($item['gaps'] ?? null) ? $item['gaps'] : [];
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong><br><code>' . esc_html((string) ($item['type'] ?? '')) . ':' . esc_html((string) ($item['stable_key'] ?? '')) . '</code></td>';
            echo '<td><strong>' . esc_html((string) ($item['status'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html((string) ($item['relation_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($item['knowledge_claim_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($item['public_evidence_count'] ?? 0)) . '<br><small>unsourced: ' . esc_html((string) ($item['unsourced_public_claim_count'] ?? 0)) . '</small></td>';
            echo '<td>' . esc_html((string) ($item['media_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($item['video_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($item['article_count'] ?? 0)) . '</td>';
            echo '<td>' . ($gaps === [] ? '—' : '<code>' . esc_html(implode(', ', array_map('strval', $gaps))) . '</code>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Data repair rule:</strong> báo cáo này chỉ xác định coverage. Mọi bổ sung quan hệ/tri thức phải có căn cứ riêng và đi qua Governance cùng read-back.</p></div>';
    }
}
