<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Demo\RemoteRuntimeAdapter;
use PHPUnit\Framework\TestCase;

final class ClockTypeAuditSurfaceContractTest extends TestCase
{
    public function test_remote_adapter_allowlists_the_read_only_clock_type_audit_operation(): void
    {
        $commands = [];
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static function (array $command) use (&$commands): array {
            $commands[] = $command;
            return [0, '{"status":"pass","identifier":"remote-clock-type-audit","fingerprint":"abc"}', ''];
        });

        $result = $adapter->run(new \NHK\Core\Application\Demo\DemoCutoverContext('demo.1945.vn', 'odo', 'rev', 'run'), 'clock-type-audit');

        self::assertTrue($result->isPass());
        self::assertSame('remote-clock-type-audit', $result->identifier);
        self::assertStringContainsString('--operation=clock-type-audit', implode(' ', $commands[0]));
    }

    public function test_remote_adapter_maps_an_unexposed_audit_surface_to_typed_gap(): void
    {
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [2, '{"status":"blocked","reason_code":"REMOTE_OPERATION_NOT_ALLOWLISTED"}', '']);

        $result = $adapter->run(new \NHK\Core\Application\Demo\DemoCutoverContext('demo.1945.vn', 'odo', 'rev', 'run'), 'clock-type-audit');

        self::assertSame('blocked', $result->status);
        self::assertSame('LIVE_AUDIT_SURFACE_NOT_EXPOSED', $result->reasonCode);
    }

    public function test_remote_adapter_preserves_typed_gap_when_remote_surface_exists_but_is_unavailable(): void
    {
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [2, '{"status":"blocked","reason_code":"LIVE_AUDIT_SURFACE_NOT_EXPOSED"}', '']);

        $result = $adapter->run(new \NHK\Core\Application\Demo\DemoCutoverContext('demo.1945.vn', 'odo', 'rev', 'run'), 'clock-type-audit');

        self::assertSame('LIVE_AUDIT_SURFACE_NOT_EXPOSED', $result->reasonCode);
    }

    public function test_audit_and_factory_contain_no_governance_or_writer_dependency(): void
    {
        $audit = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Audit/ClockTypeClassificationAudit.php');
        $factory = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Audit/WpdbClockTypeClassificationAuditFactory.php');

        foreach (['Proposal', 'Governance', 'ControlledApply'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $audit . $factory, $forbidden);
        }
    }

    public function test_maintenance_entrypoint_and_plugin_register_only_read_only_audit_filter(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php');
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString("'clock-type-audit'", $entrypoint);
        self::assertStringContainsString("nhk_v3_clock_type_classification_audit", $entrypoint . $plugin);
        self::assertStringContainsString('LIVE_AUDIT_SURFACE_NOT_EXPOSED', $entrypoint);
    }
}
