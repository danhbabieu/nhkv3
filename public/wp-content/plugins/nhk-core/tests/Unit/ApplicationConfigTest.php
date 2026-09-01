<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ApplicationConfigTest extends TestCase
{
    public function test_application_config_defines_wordpress_runtime_from_environment(): void
    {
        $result = $this->runConfig([
            'DB_NAME' => 'erourxcg_nhkv3',
            'DB_USER' => 'erourxcg_nhakho_user',
            'DB_PASSWORD' => 'test-only-password',
            'DB_HOST' => 'localhost',
            'WP_ENVIRONMENT_TYPE' => 'staging',
            'WP_HOME' => 'https://demo.1945.vn',
            'WP_SITEURL' => 'https://demo.1945.vn',
            'AUTH_KEY' => 'test-auth-key',
            'SECURE_AUTH_KEY' => 'test-secure-auth-key',
            'LOGGED_IN_KEY' => 'test-logged-in-key',
            'NONCE_KEY' => 'test-nonce-key',
            'AUTH_SALT' => 'test-auth-salt',
            'SECURE_AUTH_SALT' => 'test-secure-auth-salt',
            'LOGGED_IN_SALT' => 'test-logged-in-salt',
            'NONCE_SALT' => 'test-nonce-salt',
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame([
            'db' => 'erourxcg_nhkv3',
            'user' => 'erourxcg_nhakho_user',
            'host' => 'localhost',
            'prefix' => 'wp_',
            'environment' => 'staging',
            'home' => 'https://demo.1945.vn',
            'siteurl' => 'https://demo.1945.vn',
        ], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_application_config_fails_closed_when_a_required_value_is_missing(): void
    {
        $environment = [
            'DB_NAME' => 'erourxcg_nhkv3',
            'DB_USER' => 'erourxcg_nhakho_user',
            'DB_HOST' => 'localhost',
            'WP_ENVIRONMENT_TYPE' => 'staging',
            'WP_HOME' => 'https://demo.1945.vn',
            'WP_SITEURL' => 'https://demo.1945.vn',
        ];
        $result = $this->runConfig($environment);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('DB_PASSWORD', $result['stderr']);
    }

    /** @param array<string, string> $variables @return array{exit_code:int,stdout:string,stderr:string} */
    private function runConfig(array $variables): array
    {
        $repo = dirname(__DIR__, 6);
        $config = $repo . '/config/application.php';
        $code = 'require ' . var_export($config, true) . '; echo json_encode(['
            . "'db' => DB_NAME, 'user' => DB_USER, 'host' => DB_HOST, 'prefix' => \$table_prefix,"
            . " 'environment' => WP_ENVIRONMENT_TYPE, 'home' => WP_HOME, 'siteurl' => WP_SITEURL"
            . '], JSON_THROW_ON_ERROR);';
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $code], $descriptor, $pipes, $repo, array_merge($_ENV, $variables));
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
