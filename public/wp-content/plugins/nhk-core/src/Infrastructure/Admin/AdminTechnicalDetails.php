<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminTechnicalDetails
{
    /** @param array<string,mixed> $details */
    public static function render(array $details): void
    {
        echo '<details class="nhk-admin-technical"><summary>Chi tiết kỹ thuật</summary><dl>';
        foreach ($details as $key => $value) {
            $display = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo '<div><dt>' . self::escape((string) $key) . '</dt><dd><code>' . self::escape($display) . '</code></dd></div>';
        }
        echo '</dl></details>';
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
