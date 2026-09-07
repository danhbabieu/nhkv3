<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminListTable
{
    /** @param array<string,string> $columns @param list<array<string,mixed>> $rows @param array{label:string} $empty */
    public static function render(string $label, array $columns, array $rows, array $empty): void
    {
        echo '<section class="nhk-admin-list" aria-label="' . self::escape($label) . '">';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $key => $heading) echo '<th scope="col">' . self::escape($heading) . '</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="' . max(1, count($columns)) . '"><p class="nhk-admin-empty">' . self::escape((string) ($empty['label'] ?? 'Chưa có dữ liệu.')) . '</p></td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                foreach (array_keys($columns) as $key) echo '<td>' . self::cell($row[$key] ?? '') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></section>';
    }

    private static function cell(mixed $value): string
    {
        if (is_array($value)) return self::escape((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::escape((string) $value);
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
