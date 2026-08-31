<?php
declare(strict_types=1);
namespace NHK\Tests\Support;
use PHPUnit\Framework\TestCase;
final class TestDatabaseGuard {
    public static function requireTestDatabase(): void {
        if (getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test') TestCase::markTestSkipped('Integration tests require NHK_WP_TEST_DB=nhk_v3_test.');
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && defined('DB_NAME') && DB_NAME !== 'nhk_v3_test') {
            TestCase::fail('Integration tests must connect to nhk_v3_test, got '.DB_NAME.'.');
        }
    }

    public static function assertDestructiveAllowed(string $database): void {
        if ($database !== 'nhk_v3_test') {
            throw new \RuntimeException('Destructive test operation rejected outside nhk_v3_test.');
        }
    }
}
