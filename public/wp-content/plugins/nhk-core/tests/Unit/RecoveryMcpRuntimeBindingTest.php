<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\RecoveryMcpRuntimeBinding;
use PHPUnit\Framework\TestCase;

final class RecoveryMcpRuntimeBindingTest extends TestCase
{
    public function test_exact_recovery_environment_and_database_pass(): void
    {
        $binding = new RecoveryMcpRuntimeBinding(
            new RecoveryMcpRuntimeBindingFakeDatabase('nhk_v3_video_recovery'),
            static fn (string $key): ?string => [
                'NHK_RUNTIME_ENVIRONMENT' => 'v3-video-recovery-1309',
                'NHK_RUNTIME_MODE' => 'recovery',
            ][$key] ?? null,
        );

        self::assertSame([
            'ok' => true,
            'reason_code' => null,
            'environment' => 'v3-video-recovery-1309',
            'database' => 'nhk_v3_video_recovery',
        ], $binding->check());
    }

    /** @dataProvider mismatchedRuntimeProvider */
    public function test_mismatched_runtime_fails_closed(string $environment, string $mode, string $database, string $reason): void
    {
        $binding = new RecoveryMcpRuntimeBinding(
            new RecoveryMcpRuntimeBindingFakeDatabase($database),
            static fn (string $key): ?string => [
                'NHK_RUNTIME_ENVIRONMENT' => $environment,
                'NHK_RUNTIME_MODE' => $mode,
            ][$key] ?? null,
        );

        self::assertFalse($binding->check()['ok']);
        self::assertSame($reason, $binding->check()['reason_code']);
    }

    public static function mismatchedRuntimeProvider(): array
    {
        return [
            'environment' => ['v3-staging', 'recovery', 'nhk_v3_video_recovery', 'RECOVERY_MCP_ENVIRONMENT_MISMATCH'],
            'mode' => ['v3-video-recovery-1309', 'staging', 'nhk_v3_video_recovery', 'RECOVERY_MCP_MODE_MISMATCH'],
            'database' => ['v3-video-recovery-1309', 'recovery', 'nhk_v3', 'RECOVERY_MCP_DATABASE_MISMATCH'],
        ];
    }

    public function test_unavailable_database_fails_closed(): void
    {
        $binding = new RecoveryMcpRuntimeBinding(
            new class {
                public function get_var(string $query): string
                {
                    throw new \RuntimeException('database unavailable');
                }
            },
            static fn (string $key): ?string => $key === 'NHK_RUNTIME_ENVIRONMENT'
                ? 'v3-video-recovery-1309'
                : 'recovery',
        );

        self::assertSame('RECOVERY_MCP_DATABASE_UNAVAILABLE', $binding->check()['reason_code']);
    }
}

final class RecoveryMcpRuntimeBindingFakeDatabase
{
    public function __construct(private string $database) {}

    public function get_var(string $query): string
    {
        return $this->database;
    }
}
