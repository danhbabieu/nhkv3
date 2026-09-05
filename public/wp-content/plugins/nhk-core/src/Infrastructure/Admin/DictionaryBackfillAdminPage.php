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
        echo '<p><strong>no_write:</strong> ' . esc_html($noWrite ? 'true' : 'false') . '</p>';
        echo '<p><strong>status:</strong> ' . esc_html((string) ($report['status'] ?? 'UNKNOWN')) . '</p>';
        echo '<p><strong>scanned:</strong> ' . esc_html((string) ($report['scanned'] ?? 0)) . '</p>';

        $byKind = is_array($report['by_kind'] ?? null) ? $report['by_kind'] : [];
        if ($byKind !== []) {
            echo '<h2>Theo nguồn</h2><table class="widefat striped"><thead><tr><th>Nguồn</th><th>Số lượng</th></tr></thead><tbody>';
            foreach ($byKind as $kind => $count) {
                echo '<tr><td>' . esc_html((string) $kind) . '</td><td>' . esc_html((string) $count) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $candidates = is_array($report['candidates'] ?? null) ? $report['candidates'] : [];
        if ($candidates !== []) {
            echo '<h2>Candidate phát hiện</h2><table class="widefat striped"><thead><tr><th>Thuật ngữ</th><th>Nguồn</th><th>Trạng thái</th></tr></thead><tbody>';
            foreach ($candidates as $item) {
                if (!is_array($item)) continue;
                echo '<tr><td>' . esc_html((string) ($item['term'] ?? $item['normalized_term'] ?? '')) . '</td><td>' . esc_html((string) ($item['source_kind'] ?? '')) . '</td><td>' . esc_html((string) ($item['status'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
