<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

use NHK\Core\Shared\TestRuntimeIdentityPolicy;

final class MigrationDatabaseGuard
{
    public static function isUpAllowed(
        string $database,
        ?string $authorizedDatabase,
        ?string $runtime,
        ?string $environment,
        ?string $siteUrl = null,
        ?string $project = null,
        ?string $runtimeIdentity = null,
    ): bool {
        if ($environment === 'production') return false;

        // Recovery is an explicitly isolated, non-staging migration lane. It
        // is allow-listed by database identity and cannot be enabled merely by
        // naming a runtime "recovery" on staging or production.
        if ($runtime === 'recovery') {
            if (in_array($environment, ['staging', 'production', 'test'], true)
                || $authorizedDatabase === null
                || $authorizedDatabase === ''
            ) {
                return false;
            }

            return hash_equals($authorizedDatabase, $database);
        }

        if ($database === 'nhk_v3' && in_array($environment, [null, '', 'development'], true)) return true;
        if ($authorizedDatabase === null || $authorizedDatabase === '' || !hash_equals($authorizedDatabase, $database)) return false;
        $decision = TestRuntimeIdentityPolicy::evaluate([
            'environment' => $environment,
            'database' => $database,
            'site_url' => $siteUrl,
            'project' => $project,
            'runtime_identity' => $runtimeIdentity,
        ]);
        return $decision['allowed'] === true;
    }

    public static function assertUpAllowed(string $database, string $migration): void
    {
        $authorizedDatabase = defined('NHK_AUTHORIZED_MIGRATION_DATABASE')
            ? (string) constant('NHK_AUTHORIZED_MIGRATION_DATABASE')
            : getenv('NHK_AUTHORIZED_MIGRATION_DATABASE');
        $runtime = defined('NHK_MIGRATION_RUNTIME')
            ? (string) constant('NHK_MIGRATION_RUNTIME')
            : getenv('NHK_MIGRATION_RUNTIME');
        $environment = defined('WP_ENVIRONMENT_TYPE')
            ? (string) constant('WP_ENVIRONMENT_TYPE')
            : getenv('WP_ENVIRONMENT_TYPE');
        $siteUrl = function_exists('home_url') ? (string) home_url('/') : (string) getenv('NHK_TEST_SITE_URL');
        $project = defined('NHK_PROJECT_ID') ? (string) constant('NHK_PROJECT_ID') : (class_exists('NHK\\Core\\Plugin') ? TestRuntimeIdentityPolicy::PROJECT : '');
        $runtimeIdentity = defined('NHK_RUNTIME_PROJECT') ? (string) constant('NHK_RUNTIME_PROJECT') : $project;

        if (!self::isUpAllowed($database, $authorizedDatabase === false ? null : $authorizedDatabase, $runtime === false ? null : $runtime, $environment === false ? null : $environment, $siteUrl, $project, $runtimeIdentity)) {
            throw new \RuntimeException($migration . '_UP_REQUIRES_AUTHORIZED_RUNTIME_DATABASE');
        }
    }

    public static function assertDownAllowed(string $migration): void
    {
        global $wpdb;
        $database = isset($wpdb) && is_object($wpdb) ? (string) $wpdb->get_var('SELECT DATABASE()') : '';
        $environment = defined('WP_ENVIRONMENT_TYPE') ? (string) constant('WP_ENVIRONMENT_TYPE') : (string) getenv('WP_ENVIRONMENT_TYPE');
        $siteUrl = function_exists('home_url') ? (string) home_url('/') : (string) getenv('NHK_TEST_SITE_URL');
        $project = class_exists('NHK\\Core\\Plugin') ? TestRuntimeIdentityPolicy::PROJECT : '';
        $decision = TestRuntimeIdentityPolicy::evaluate([
            'environment' => $environment,
            'database' => $database,
            'site_url' => $siteUrl,
            'project' => $project,
            'runtime_identity' => $project,
        ]);
        if ($decision['allowed'] !== true) {
            throw new \RuntimeException($migration . '_DOWN_REQUIRES_AUTHORIZED_TEST_RUNTIME');
        }
    }
}
