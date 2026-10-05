<?php
declare(strict_types=1);

namespace NHK\Core\Shared;

final class TestRuntimeIdentityPolicy
{
    public const ENVIRONMENT = 'staging';
    public const DATABASE = 'erourxcg_nhkv3';
    public const LEGACY_DATABASE = 'nhk_v3_test';
    public const SITE_URL = 'https://demo.1945.vn';
    public const PROJECT = 'nhk-v3';

    /** @param array<string,mixed> $identity */
    public static function evaluate(array $identity): array
    {
        $environment = strtolower(trim((string) ($identity['environment'] ?? '')));
        $database = trim((string) ($identity['database'] ?? ''));
        $site = self::normalizeSite((string) ($identity['site_url'] ?? ''));
        $project = strtolower(trim((string) ($identity['project'] ?? '')));
        $runtime = strtolower(trim((string) ($identity['runtime_identity'] ?? '')));
        $actual = ['environment' => $environment, 'database' => $database, 'site_url' => $site, 'project' => $project, 'runtime_identity' => $runtime];
        foreach ($actual as $field => $value) if ($value === '') return ['allowed' => false, 'reason' => 'TEST_RUNTIME_IDENTITY_INCOMPLETE', 'identity' => $actual];
        if ($environment !== self::ENVIRONMENT) return ['allowed' => false, 'reason' => 'TEST_RUNTIME_ENVIRONMENT_MISMATCH', 'identity' => $actual];
        if ($database !== self::DATABASE) return ['allowed' => false, 'reason' => 'TEST_RUNTIME_DATABASE_MISMATCH', 'identity' => $actual];
        if ($site !== self::SITE_URL) return ['allowed' => false, 'reason' => 'TEST_RUNTIME_SITE_MISMATCH', 'identity' => $actual];
        if ($project !== self::PROJECT || $runtime !== self::PROJECT) return ['allowed' => false, 'reason' => 'TEST_RUNTIME_PROJECT_MISMATCH', 'identity' => $actual];
        return ['allowed' => true, 'reason' => null, 'identity' => $actual];
    }

    public static function normalizeSite(string $site): string
    {
        $site = trim($site);
        if ($site === '') return '';
        $parts = parse_url($site);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return '';
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }
}
