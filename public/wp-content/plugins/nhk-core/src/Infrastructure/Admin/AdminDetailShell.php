<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminDetailShell
{
    public static function render(string $title, string $description, callable $content): void
    {
        echo '<section class="nhk-admin-detail" aria-labelledby="nhk-admin-detail-heading"><header><h2 id="nhk-admin-detail-heading">' . self::escape($title) . '</h2><p>' . self::escape($description) . '</p></header><div class="nhk-admin-detail__body">';
        $content();
        echo '</div></section>';
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
