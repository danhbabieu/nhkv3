<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Infrastructure\Demo\RemoteRuntimeAdapter;
use PHPUnit\Framework\TestCase;

final class RemoteRuntimeAdapterTest extends TestCase
{
    public function test_health_delegates_to_allowlisted_remote_entrypoint_and_decodes_json(): void
    {
        $commands = [];
        $adapter = new RemoteRuntimeAdapter(
            'demo.1945.vn',
            '/home/erourxcg/apps/nhkv3/public/wp-content/plugins/nhk-core',
            static function (array $command) use (&$commands): array {
                $commands[] = $command;
                return [0, '{"status":"pass","database":"nhk_v3"}', ''];
            },
        );

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-1'), 'health');

        self::assertSame('pass', $result->status);
        self::assertSame('remote-health', $result->identifier);
        self::assertSame('ssh', $commands[0][0]);
        self::assertStringContainsString('nhk-core-maintenance.php', implode(' ', $commands[0]));
        self::assertStringContainsString('--operation=health', implode(' ', $commands[0]));
    }

    public function test_unknown_operation_fails_before_transport(): void
    {
        $called = false;
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static function () use (&$called): array {
            $called = true;
            return [0, '{}', ''];
        });

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-2'), 'sql');

        self::assertSame('blocked', $result->status);
        self::assertSame('REMOTE_OPERATION_NOT_ALLOWLISTED', $result->reasonCode);
        self::assertFalse($called);
    }

    public function test_malformed_or_failed_remote_json_is_distinguished(): void
    {
        $malformed = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [0, 'not-json', '']);
        self::assertSame('REMOTE_RUNTIME_INVALID_RECEIPT', $malformed->run(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-3'), 'inventory')->reasonCode);

        $failed = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [1, '', 'runtime failed']);
        self::assertSame('REMOTE_RUNTIME_EXECUTION_FAILED', $failed->run(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-4'), 'inventory')->reasonCode);
    }
}
