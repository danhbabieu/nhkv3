<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminStatusBadge
{
    public static function render(string $label, string $state, ?string $reason = null): void
    {
        $state = strtolower(trim($state));
        $labels = ['ready' => 'Sẵn sàng', 'applied' => 'Đã áp dụng', 'pending' => 'Đang chờ', 'blocked' => 'Bị chặn', 'unavailable' => 'Không khả dụng', 'empty' => 'Chưa có dữ liệu'];
        echo '<span class="nhk-admin-status nhk-admin-status--' . self::escape(preg_replace('/[^a-z0-9_-]/', '-', $state) ?: 'unknown') . '"><strong>' . self::escape($label) . ':</strong> ' . self::escape($labels[$state] ?? $state);
        if ($reason !== null && trim($reason) !== '') echo ' <span class="nhk-admin-status__reason">— ' . self::escape($reason) . '</span>';
        echo '</span>';
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
