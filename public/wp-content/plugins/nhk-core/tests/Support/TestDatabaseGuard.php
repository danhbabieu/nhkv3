<?php
declare(strict_types=1);
namespace NHK\Tests\Support;
use NHK\Core\Shared\TestRuntimeIdentityPolicy;
use PHPUnit\Framework\TestCase;
final class TestDatabaseGuard {
    public static function isInitialized(?object $wpdb): bool { return $wpdb !== null; }
    public static function requireTestDatabase(): void {
        self::assertAuthorizedRuntime();
    }

    public static function selectTestDatabase(): void {
        global $wpdb;
        self::assertAuthorizedRuntime();
        wp_cache_flush();
    }

    public static function assertDestructiveAllowed(string $database): void {
        if ($database !== TestRuntimeIdentityPolicy::DATABASE) throw new \RuntimeException('Destructive test operation rejected outside the authorized NHK V3 test runtime.');
        self::assertAuthorizedRuntime();
    }

    public static function assertAuthorizedRuntime(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) TestCase::fail('Authorized NHK V3 test runtime is unavailable.');
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        $site = function_exists('home_url') ? (string) home_url('/') : '';
        $decision = TestRuntimeIdentityPolicy::evaluate([
            'environment' => defined('WP_ENVIRONMENT_TYPE') ? (string) constant('WP_ENVIRONMENT_TYPE') : (string) getenv('WP_ENVIRONMENT_TYPE'),
            'database' => $database,
            'site_url' => $site,
            'project' => class_exists('NHK\\Core\\Plugin') ? TestRuntimeIdentityPolicy::PROJECT : '',
            'runtime_identity' => class_exists('NHK\\Core\\Plugin') ? TestRuntimeIdentityPolicy::PROJECT : '',
        ]);
        if ($decision['allowed'] !== true) TestCase::fail('Unauthorized NHK V3 test runtime: ' . (string) ($decision['reason'] ?? 'IDENTITY_REJECTED'));
    }
}
