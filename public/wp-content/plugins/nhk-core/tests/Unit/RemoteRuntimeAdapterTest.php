<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Infrastructure\Demo\RemoteRuntimeAdapter;
use NHK\Core\Infrastructure\Migration\SpecimenProductRelationMigration026;
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
                return [0, '{"status":"pass","health":{"database":"erourxcg_nhkv3"}}', ''];
            },
            null,
            ['migration_runtime' => 'demo', 'authorized_database' => 'erourxcg_nhkv3', 'environment' => 'staging', 'wp_home' => 'https://demo.1945.vn', 'wp_siteurl' => 'https://demo.1945.vn'],
        );

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-1'), 'health');

        self::assertSame('pass', $result->status);
        self::assertSame('remote-health', $result->identifier);
        self::assertSame('erourxcg_nhkv3', $result->metadata['health']['database']);
        self::assertSame('ssh', $commands[0][0]);
        self::assertStringContainsString('nhk-core-maintenance.php', implode(' ', $commands[0]));
        self::assertStringContainsString('--operation=health', implode(' ', $commands[0]));
        self::assertContains('HTTPS=on', $commands[0]);
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

    public function test_migration_up_requires_exact_context_and_schema_receipt(): void
    {
        $sourceRevision = str_repeat('a', 40);
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [0, json_encode([
            'status' => 'pass', 'identifier' => 'remote-migration-up', 'current' => 26, 'target' => 26,
            'dictionary_entry_sense_schema_ready' => true, 'specimen_product_relation_schema_ready' => true, 'pack' => 'dictionary', 'run_id' => 'run-7',
            'source_revision' => $sourceRevision,
        ]), ''], null, ['migration_runtime' => 'demo', 'authorized_database' => 'nhk_v3', 'environment' => 'staging']);

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'dictionary', $sourceRevision, 'run-7'), 'migration-up');

        self::assertSame('pass', $result->status);
        self::assertSame(SpecimenProductRelationMigration026::VERSION, $result->metadata['current']);
        self::assertTrue($result->metadata['dictionary_entry_sense_schema_ready']);
    }

    public function test_pre_migration_snapshot_is_allowlisted_and_returns_the_backup_receipt(): void
    {
        $commands = [];
        $sourceRevision = str_repeat('a', 40);
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static function (array $command) use (&$commands): array {
            $commands[] = $command;
            return [0, json_encode([
                'status' => 'pass',
                'identifier' => 'v3-snapshot-pre-migration-export',
                'manifest' => [
                    'export_mode' => 'pre_migration',
                    'migration_level' => ['current' => 25, 'target' => 26],
                    'source_environment' => 'staging',
                    'source_database_identity' => 'erourxcg_nhkv3',
                    'manifest_hash' => str_repeat('b', 64),
                ],
                'receipt' => ['status' => 'backup_created', 'manifest_hash' => str_repeat('b', 64)],
            ]), ''];
        });

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'specimen', $sourceRevision, 'run-backup'), 'v3-snapshot-pre-migration-export');

        self::assertSame('pass', $result->status);
        self::assertSame('v3-snapshot-pre-migration-export', $result->identifier);
        self::assertSame('backup_created', $result->metadata['receipt']['status']);
        self::assertStringContainsString('--operation=v3-snapshot-pre-migration-export', implode(' ', $commands[0]));
        self::assertStringContainsString('--output=/tmp/nhk-v3-pre-migration-', implode(' ', $commands[0]));
    }

    public function test_pre_migration_snapshot_preserves_the_exact_remote_gate_reason(): void
    {
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [2, '{"status":"failed","reason_code":"SNAPSHOT_PRE_MIGRATION_LEVEL_INVALID"}', '']);

        self::assertSame(
            'SNAPSHOT_PRE_MIGRATION_LEVEL_INVALID',
            $adapter->run(new DemoCutoverContext('demo.1945.vn', 'specimen', str_repeat('a', 40), 'run-backup-failed'), 'v3-snapshot-pre-migration-export')->reasonCode,
        );
    }

    public function test_migration_up_rejects_stale_source_revision_and_incomplete_schema(): void
    {
        $sourceRevision = str_repeat('a', 40);
        $stale = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [0, json_encode([
            'status' => 'pass', 'pack' => 'dictionary', 'run_id' => 'run-8', 'source_revision' => str_repeat('b', 40),
            'current' => SpecimenProductRelationMigration026::VERSION - 1, 'target' => SpecimenProductRelationMigration026::VERSION, 'dictionary_entry_sense_schema_ready' => true, 'specimen_product_relation_schema_ready' => true,
        ]), ''], null, ['migration_runtime' => 'demo', 'authorized_database' => 'nhk_v3', 'environment' => 'staging']);
        self::assertSame('REMOTE_SOURCE_REVISION_MISMATCH', $stale->run(new DemoCutoverContext('demo.1945.vn', 'dictionary', $sourceRevision, 'run-8'), 'migration-up')->reasonCode);

        $incomplete = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [0, json_encode([
            'status' => 'pass', 'pack' => 'dictionary', 'run_id' => 'run-9', 'source_revision' => $sourceRevision,
            'current' => SpecimenProductRelationMigration026::VERSION - 1, 'target' => SpecimenProductRelationMigration026::VERSION, 'dictionary_entry_sense_schema_ready' => false, 'specimen_product_relation_schema_ready' => true,
        ]), ''], null, ['migration_runtime' => 'demo', 'authorized_database' => 'nhk_v3', 'environment' => 'staging']);
        self::assertSame('MIGRATION_TARGET_NOT_REACHED', $incomplete->run(new DemoCutoverContext('demo.1945.vn', 'dictionary', $sourceRevision, 'run-9'), 'migration-up')->reasonCode);
    }

    public function test_migration_failure_preserves_remote_reason_code(): void
    {
        $adapter = new RemoteRuntimeAdapter('demo.1945.vn', '/remote/plugin', static fn (): array => [2, '{"status":"failed","reason_code":"MIGRATION_SOURCE_REVISION_MISMATCH"}', ''], null, ['migration_runtime' => 'demo', 'authorized_database' => 'nhk_v3', 'environment' => 'staging']);

        self::assertSame('MIGRATION_SOURCE_REVISION_MISMATCH', $adapter->run(new DemoCutoverContext('demo.1945.vn', 'dictionary', str_repeat('a', 40), 'run-11'), 'migration-up')->reasonCode);
    }

    public function test_migration_config_is_transmitted_only_for_explicit_migration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-runtime-');
        file_put_contents($path, "ssh_target=demo.1945.vn\nremote_path=/remote/plugin\nssh_key=/dev/null\nmigration_runtime=demo\nauthorized_migration_database=nhk_v3\nenvironment_type=staging\n");
        putenv('NHK_DEMO_DEPLOY_CONFIG=' . $path);
        $commands = [];
        $adapter = RemoteRuntimeAdapter::fromEnvironment(static function (array $command) use (&$commands): array {
            $commands[] = $command;
            return [0, json_encode(['status' => 'pass', 'pack' => 'dictionary', 'run_id' => 'run-10', 'source_revision' => str_repeat('a', 40), 'current' => 26, 'target' => 26, 'dictionary_entry_sense_schema_ready' => true, 'specimen_product_relation_schema_ready' => true]), ''];
        });

        $result = $adapter->run(new DemoCutoverContext('demo.1945.vn', 'dictionary', str_repeat('a', 40), 'run-10'), 'migration-up');

        putenv('NHK_DEMO_DEPLOY_CONFIG');
        unlink($path);
        self::assertSame('pass', $result->status);
        self::assertContains('NHK_MIGRATION_RUNTIME=demo', $commands[0]);
        self::assertContains('NHK_AUTHORIZED_MIGRATION_DATABASE=nhk_v3', $commands[0]);
        self::assertContains('WP_ENVIRONMENT_TYPE=staging', $commands[0]);
    }
}
