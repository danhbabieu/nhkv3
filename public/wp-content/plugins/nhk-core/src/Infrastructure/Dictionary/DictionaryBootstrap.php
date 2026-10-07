<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Application\Dictionary\{DictionaryObservationRegistry, DictionaryRuntime};
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Infrastructure\Admin\{DictionaryAdminPage, DictionaryBackfillAdminPage};

final class DictionaryBootstrap
{
    private static ?DictionaryRuntime $runtime = null;
    private static bool $booted = false;

    public static function boot(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        if (self::$booted) return;
        self::$booted = true;

        self::$runtime = new DictionaryRuntime($wpdb);
        $harvester = self::$runtime->harvester();
        $naturalCapture = self::$runtime->naturalCaptureService();
        DictionaryObservationRegistry::register(
            static function (string $kind, string $id, string $text, array $context = [], array $hints = []) use ($harvester): array {
                $result = $harvester->harvest([['source_kind' => $kind, 'source_id' => $id, 'text' => $text, 'context' => $context, 'hints' => $hints]], true);
                return is_array($result['items'][0]['plan'] ?? null) ? $result['items'][0]['plan'] : ['status' => 'UNAVAILABLE', 'blocking' => false];
            },
            static function (string $kind, string $text, array $context = [], array $hints = []) use ($harvester): array {
                $result = $harvester->harvest([['source_kind' => $kind, 'source_id' => 'preview', 'text' => $text, 'context' => $context, 'hints' => $hints]], false);
                return is_array($result['items'][0]['plan'] ?? null) ? $result['items'][0]['plan'] : ['status' => 'UNAVAILABLE', 'blocking' => false];
            },
            static function (string $kind, string $id, string $text, array $context = [], array $observation = []) use ($naturalCapture): array {
                $sharedCommand = is_array($observation['natural_owner_command'] ?? null) ? $observation['natural_owner_command'] : null;
                return $naturalCapture->plan($text, $id, $context, $sharedCommand);
            },
            static function (array $plan, string $idempotencyKey) use ($naturalCapture): array {
                return $naturalCapture->apply($plan, $idempotencyKey);
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
