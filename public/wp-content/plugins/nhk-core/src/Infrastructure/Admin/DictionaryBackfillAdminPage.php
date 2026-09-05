<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Dictionary\DictionaryRuntime;

final class DictionaryBackfillAdminPage
{
    private static ?DictionaryRuntime $runtime = null;

    public static function register(DictionaryRuntime $runtime): void
    {
        self::$runtime = $runtime;
        add_action('admin_menu', static function (): void {
            add_submenu_page(
                'nhk-v3',
                'Từ điển — dry-run kho cũ',
                'Từ điển dry-run',
                'nhk_curate_dictionary',
                'nhk-v3-dictionary-backfill',
                [self::class, 'render']
            );
        }, 21);
    }

    public static function render(): void
    {
        if (!current_user_can('nhk_curate_dictionary')) wp_die('Dictionary curation capability required.');

        echo '<div class="wrap"><h1>Từ điển — dry-run kho cũ</h1>';
        echo '<p>Chỉ đọc và phân tích. Báo cáo này không ghi candidate, mention, Knowledge, Evidence hay Graph relation.</p>';

        $runtime = self::$runtime;
        if (!$runtime || !$runtime->available()) {
            echo '<div class="notice notice-warning"><p>Dictionary storage chưa sẵn sàng. Trạng thái: UNAVAILABLE.</p></div></div>';
            return;
        }

        try {
            $report = $runtime->backfillDryRun();
        } catch (\Throwable $error) {
            echo '<div class="notice notice-error"><p>Dry-run không khả dụng: ' . esc_html($error->getMessage()) . '</p></div></div>';
            return;
        }

        $noWrite = (bool) ($report['no_write'] ?? false);
        $mode = (string) ($report['mode'] ?? 'UNKNOWN');
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $sourceCounts = is_array($report['source_counts'] ?? null) ? $report['source_counts'] : [];
        $items = is_array($report['items'] ?? null) ? $report['items'] : [];

        echo '<p><strong>no_write:</strong> ' . esc_html($noWrite ? 'true' : 'false') . '</p>';
        echo '<p><strong>mode:</strong> ' . esc_html($mode) . '</p>';
        echo '<p><strong>scanned:</strong> ' . esc_html((string) ($totals['sources'] ?? 0)) . '</p>';

        echo '<h2>Tổng hợp</h2><table class="widefat striped"><thead><tr><th>Đã resolve</th><th>Candidate mới</th><th>Mơ hồ</th><th>Đã suppress</th><th>Unavailable</th></tr></thead><tbody><tr>';
        foreach (['resolved_existing', 'candidate_new', 'ambiguous', 'suppressed', 'unavailable'] as $key) {
            echo '<td>' . esc_html((string) ($totals[$key] ?? 0)) . '</td>';
        }
        echo '</tr></tbody></table>';

        if ($sourceCounts !== []) {
            echo '<h2>Theo nguồn</h2><table class="widefat striped"><thead><tr><th>Nguồn</th><th>Số lượng</th></tr></thead><tbody>';
            foreach ($sourceCounts as $kind => $count) {
                echo '<tr><td>' . esc_html((string) $kind) . '</td><td>' . esc_html((string) $count) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if ($items !== []) {
            echo '<h2>Chi tiết dry-run</h2><table class="widefat striped"><thead><tr><th>Nguồn</th><th>ID</th><th>Trạng thái</th><th>Đã resolve</th><th>Candidate mới</th><th>Mơ hồ</th><th>Đã suppress</th></tr></thead><tbody>';
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['kind'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['id'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['resolved_existing'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) ($item['candidate_new'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) ($item['ambiguous'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) ($item['suppressed'] ?? 0)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
