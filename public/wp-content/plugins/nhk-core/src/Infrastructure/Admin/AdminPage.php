<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Shared\Health\HealthCheck;

final class AdminPage
{
    public static function register(): void
    {
        add_menu_page('NHK V3', 'NHK V3', 'manage_options', 'nhk-v3', [self::class, 'render'], 'dashicons-book-alt', 26);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to view this page.');
        $health = (new HealthCheck(new \NHK\Core\Shared\Migration\MigrationStatus()))->read();
        echo '<div class="wrap"><h1>NHK V3</h1><p>Trạng thái vận hành domain và dữ liệu semantic.</p><table class="widefat striped"><tbody>';
        foreach ($health as $key => $value) echo '<tr><th scope="row">' . esc_html((string) $key) . '</th><td>' . esc_html(is_bool($value) ? ($value ? 'OK' : 'NO') : (string) $value) . '</td></tr>';
        echo '</tbody></table><p>Mutation phải đi qua Governance; trang này chỉ hiển thị health evidence.</p></div>';
    }
}
