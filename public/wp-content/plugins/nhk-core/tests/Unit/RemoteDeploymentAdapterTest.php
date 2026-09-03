<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Infrastructure\Demo\RemoteDeploymentAdapter;
use PHPUnit\Framework\TestCase;

final class RemoteDeploymentAdapterTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'nhk-deploy-');
        putenv('NHK_DEMO_DEPLOY_CONFIG=' . $this->configPath);
    }

    protected function tearDown(): void
    {
        putenv('NHK_DEMO_DEPLOY_CONFIG');
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function test_missing_external_deployment_config_fails_closed_with_actionable_reason(): void
    {
        unlink($this->configPath);
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static fn (): array => [0, '', '']);

        $result = $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-1'));

        self::assertSame('blocked', $result->status);
        self::assertSame('REMOTE_DEPLOYMENT_CONFIG_REQUIRED', $result->reasonCode);
    }

    public function test_target_allowlist_is_enforced_before_transport(): void
    {
        file_put_contents($this->configPath, "ssh_target=production.example\nremote_path=/srv/nhk-core\n");
        $called = false;
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static function () use (&$called): array { $called = true; return [0, '', '']; });

        $result = $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-2'));

        self::assertSame('DEPLOYMENT_TARGET_NOT_ALLOWLISTED', $result->reasonCode);
        self::assertFalse($called);
    }

    public function test_transfer_is_deterministic_and_verified_without_semantic_transport(): void
    {
        file_put_contents($this->configPath, "ssh_target=demo.1945.vn\nremote_path=/srv/nhk-core\nssh_key=/dev/null\n");
        $commands = [];
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static function (array $command) use (&$commands): array { $commands[] = $command; return [0, '', '']; });

        $result = $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-3'));

        self::assertSame('pass', $result->status);
        self::assertCount(2, $commands);
        self::assertSame('rsync', $commands[0][0]);
        self::assertContains('--checksum', $commands[0]);
        self::assertContains('--exclude', $commands[0]);
        self::assertSame('ssh', $commands[1][0]);
        self::assertSame('test', $commands[1][6]);
        self::assertStringContainsString('nhk-core.php', implode(' ', $commands[1]));
        self::assertStringNotContainsString('odo', implode(' ', $commands[0]));
        self::assertNotNull($result->fingerprint);
    }

    public function test_remote_failure_and_verification_failure_are_distinct(): void
    {
        file_put_contents($this->configPath, "ssh_target=demo.1945.vn\nremote_path=/srv/nhk-core\nssh_key=/dev/null\n");
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static fn (array $command): array => [1, '', 'transport failed']);
        self::assertSame('REMOTE_DEPLOYMENT_FAILED', $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-4'))->reasonCode);

        $calls = 0;
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static function () use (&$calls): array { $calls++; return $calls === 1 ? [0, '', ''] : [1, '', 'missing']; });
        self::assertSame('REMOTE_DEPLOYMENT_VERIFICATION_FAILED', $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-5'))->reasonCode);
    }

    public function test_missing_ssh_key_and_agent_fails_closed(): void
    {
        file_put_contents($this->configPath, "ssh_target=demo.1945.vn\nremote_path=/srv/nhk-core\n");
        putenv('SSH_AUTH_SOCK');
        $adapter = RemoteDeploymentAdapter::fromEnvironment(dirname(__DIR__, 6), static fn (): array => [0, '', '']);

        self::assertSame('REMOTE_DEPLOYMENT_CREDENTIAL_UNAVAILABLE', $adapter->deploy(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-6'))->reasonCode);
    }
}
