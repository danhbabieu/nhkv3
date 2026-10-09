<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use NHK\Core\Shared\TestRuntimeIdentityPolicy;

/** Fail-closed release policy for the authorized NHK V3 staging runtime. */
final class StagingReleasePolicy
{
    public const SITE_URL = TestRuntimeIdentityPolicy::SITE_URL;
    public const ENVIRONMENT = TestRuntimeIdentityPolicy::ENVIRONMENT;
    public const DATABASE = TestRuntimeIdentityPolicy::DATABASE;
    public const TARGET_MIGRATION = 26;

    /** @param array<string,mixed> $config */
    public static function validateDeploymentConfig(array $config): ?string
    {
        foreach (['environment_type', 'wp_home', 'wp_siteurl'] as $field) {
            if (!is_string($config[$field] ?? null) || trim((string) $config[$field]) === '') {
                return 'STAGING_DEPLOYMENT_IDENTITY_REQUIRED';
            }
        }

        if (strtolower(trim((string) $config['environment_type'])) !== self::ENVIRONMENT
            || self::normalizeUrl((string) $config['wp_home']) !== self::SITE_URL
            || self::normalizeUrl((string) $config['wp_siteurl']) !== self::SITE_URL) {
            return 'STAGING_DEPLOYMENT_IDENTITY_MISMATCH';
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    public static function validateRuntimeHealth(array $payload): ?string
    {
        $health = is_array($payload['health'] ?? null) ? $payload['health'] : $payload;
        foreach (['environment', 'database', 'site_url', 'siteurl'] as $field) {
            if (!is_string($health[$field] ?? null) || trim((string) $health[$field]) === '') {
                return 'STAGING_RUNTIME_IDENTITY_UNAVAILABLE';
            }
        }

        if (strtolower(trim((string) $health['environment'])) !== self::ENVIRONMENT
            || trim((string) $health['database']) !== self::DATABASE
            || self::normalizeUrl((string) $health['site_url']) !== self::SITE_URL
            || self::normalizeUrl((string) $health['siteurl']) !== self::SITE_URL) {
            return 'STAGING_RUNTIME_IDENTITY_MISMATCH';
        }

        return null;
    }

    /** @param array<string,mixed> $payload @return array{backup_required:bool,migration_required:bool} */
    public static function migrationPlan(array $payload): array
    {
        $health = is_array($payload['health'] ?? null) ? $payload['health'] : $payload;
        $current = (int) ($health['migration_current'] ?? -1);
        $target = (int) ($health['migration_target'] ?? -1);

        if ($current === self::TARGET_MIGRATION && $target === self::TARGET_MIGRATION) {
            return ['backup_required' => false, 'migration_required' => false];
        }
        if ($current === self::TARGET_MIGRATION - 1 && $target === self::TARGET_MIGRATION) {
            return ['backup_required' => true, 'migration_required' => true];
        }

        throw new \RuntimeException('MIGRATION_STATE_UNSUPPORTED');
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'demo.1945.vn'
            || isset($parts['port'], $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            return '';
        }

        return self::SITE_URL;
    }
}
