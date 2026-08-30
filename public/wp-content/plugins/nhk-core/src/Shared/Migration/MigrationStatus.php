<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Migration;
final class MigrationStatus {
    public function status(): array {
        return ['current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 0)];
    }
}
