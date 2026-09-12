<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Snapshot;

use NHK\Core\Application\Snapshot\SnapshotEnvironment;

/** Binds snapshot ports without exposing a recovery writer on Demo/staging. */
final class SnapshotRuntimeComposition
{
    public static function register(object $database): void
    {
        if (!function_exists('add_filter')) return;
        static $registered = [];
        $key = spl_object_id($database);
        if (isset($registered[$key])) return;
        $registered[$key] = true;
        $runtime = self::environment($database);
        add_filter('nhk_v3_snapshot_source', static fn (mixed $current): mixed => $current ?? new WpdbCanonicalSnapshotSource($database, $runtime), 10, 1);
        if (strtolower($runtime->runtimeMode) === 'recovery' && !in_array(strtolower($runtime->name), ['staging', 'production', 'test'], true)) {
            add_filter('nhk_v3_snapshot_writer', static fn (mixed $current): mixed => $current ?? new WpdbCanonicalSnapshotWriter($database, $runtime, self::allowedDatabases()), 10, 1);
        }
    }

    public static function environment(object $database): SnapshotEnvironment
    {
        $mode = self::env('NHK_RUNTIME_MODE', self::env('WP_ENVIRONMENT_TYPE', 'unknown'));
        $name = self::env('NHK_RUNTIME_ENVIRONMENT', $mode);
        $site = function_exists('home_url') ? (string) home_url('/') : self::env('WP_HOME', 'http://localhost');
        $databaseName = (string) $database->get_var('SELECT DATABASE()');
        return new SnapshotEnvironment($name, rtrim($site, '/') . '/', $databaseName, $mode);
    }

    /** @return list<string> */
    private static function allowedDatabases(): array
    {
        $value = self::env('NHK_RECOVERY_ALLOWED_DATABASES', '');
        return $value === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
