<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminReadBackPanel
{
    /** @param array<string,array{state:string,label:string,reason?:string}> $layers */
    public static function render(array $layers): void
    {
        echo '<section class="nhk-admin-readback" aria-labelledby="nhk-admin-readback-heading"><h3 id="nhk-admin-readback-heading">Đọc lại sau thao tác</h3><div class="nhk-admin-readback__grid">';
        foreach ($layers as $key => $layer) {
            $label = (string) ($layer['label'] ?? ucfirst((string) $key));
            self::item($label, (string) ($layer['state'] ?? 'unavailable'), isset($layer['reason']) ? (string) $layer['reason'] : null);
        }
        echo '</div></section>';
    }

    private static function item(string $label, string $state, ?string $reason): void
    {
        echo '<div class="nhk-admin-readback__item">';
        AdminStatusBadge::render($label, $state, $reason);
        echo '</div>';
    }
}
