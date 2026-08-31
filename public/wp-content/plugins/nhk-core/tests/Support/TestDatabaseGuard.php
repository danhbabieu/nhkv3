<?php
declare(strict_types=1);
namespace NHK\Tests\Support;
use PHPUnit\Framework\TestCase;
final class TestDatabaseGuard {
    public static function requireTestDatabase(): void {
        if (getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test') TestCase::markTestSkipped('Integration tests require NHK_WP_TEST_DB=nhk_v3_test.');
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $database = (string) $wpdb->get_var('SELECT DATABASE()');
            if ($database !== 'nhk_v3_test') {
                TestCase::fail('Integration tests must connect to nhk_v3_test, got '.$database.'.');
            }
        }
    }

    public static function selectTestDatabase(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            TestCase::fail('Unable to select integration database nhk_v3_test.');
        }
        $wpdb->select('nhk_v3_test');
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') {
            TestCase::fail('Unable to select integration database nhk_v3_test.');
        }
        wp_cache_flush();
    }

    public static function assertDestructiveAllowed(string $database): void {
        if ($database !== 'nhk_v3_test') {
            throw new \RuntimeException('Destructive test operation rejected outside nhk_v3_test.');
        }
    }
}
