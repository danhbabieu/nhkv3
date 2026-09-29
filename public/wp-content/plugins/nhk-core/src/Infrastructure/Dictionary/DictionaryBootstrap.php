<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Application\Dictionary\{DictionaryObservationRegistry, DictionaryRuntime};
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Infrastructure\Admin\{DictionaryAdminPage, DictionaryBackfillAdminPage};
use NHK\Core\Infrastructure\Migration\DictionaryMigration015;

final class DictionaryBootstrap
{
    private static ?DictionaryRuntime $runtime = null;

    public static function boot(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;

        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), DictionaryMigration015::VERSION), false);
        if (defined('NHK_RUN_MIGRATIONS') && NHK_RUN_MIGRATIONS === true && !DictionaryMigration015::schemaReady($wpdb)) (new DictionaryMigration015())->up();

        self::$runtime = new DictionaryRuntime($wpdb);
        $harvester = self::$runtime->harvester();
        DictionaryObservationRegistry::register(
            static function (string $kind, string $id, string $text, array $context = [], array $hints = []) use ($harvester): array {
                $result = $harvester->harvest([['source_kind' => $kind, 'source_id' => $id, 'text' => $text, 'context' => $context, 'hints' => $hints]], true);
                return is_array($result['items'][0]['plan'] ?? null) ? $result['items'][0]['plan'] : ['status' => 'UNAVAILABLE', 'blocking' => false];
            },
            static function (string $kind, string $text, array $context = [], array $hints = []) use ($harvester): array {
                $result = $harvester->harvest([['source_kind' => $kind, 'source_id' => 'preview', 'text' => $text, 'context' => $context, 'hints' => $hints]], false);
                return is_array($result['items'][0]['plan'] ?? null) ? $result['items'][0]['plan'] : ['status' => 'UNAVAILABLE', 'blocking' => false];
            },
        );
        (new DictionaryWordPressBridge(self::$runtime))->register();
        DictionaryAdminPage::register(self::$runtime);
        DictionaryBackfillAdminPage::register(self::$runtime);
        GovernanceCapabilities::register();

        if ((string) get_option('nhk_dictionary_rewrite_version', '') !== '1') {
            update_option('nhk_dictionary_rewrite_version', '1', false);
            add_action('init', static function (): void { flush_rewrite_rules(false); }, 100);
        }
    }

    public static function activate(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        if (!DictionaryMigration015::schemaReady($wpdb)) (new DictionaryMigration015())->up();
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), DictionaryMigration015::VERSION), false);
        update_option('nhk_dictionary_rewrite_version', '1', false);
        GovernanceCapabilities::register();
        flush_rewrite_rules(false);
    }

    public static function runtime(): ?DictionaryRuntime { return self::$runtime; }
}
