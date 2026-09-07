<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MigrationDatabaseGuard
{
    public static function isUpAllowed(
        string $database,
        ?string $authorizedDatabase,
        ?string $runtime,
        ?string $environment
    ): bool {
        if (in_array($environment, ['production', 'staging'], true)) return false;
        if (in_array($database, ['nhk_v3', 'nhk_v3_test'], true)) return true;

        return $runtime === 'demo'
            && $authorizedDatabase !== null
            && $authorizedDatabase !== ''
            && hash_equals($authorizedDatabase, $database);
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

        if (!self::isUpAllowed($database, $authorizedDatabase === false ? null : $authorizedDatabase, $runtime === false ? null : $runtime, $environment === false ? null : $environment)) {
            throw new \RuntimeException($migration . '_UP_REQUIRES_AUTHORIZED_RUNTIME_DATABASE');
        }
    }
}
