<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use Closure;
use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\StageResult;
use NHK\Core\Infrastructure\Migration\SpecimenProductRelationMigration026;

/** Executes only the versioned NHK maintenance entrypoint over SSH. */
final class RemoteRuntimeAdapter
{
    private const OPERATIONS = [
        'health', 'inventory', 'canonical-inventory', 'graph-inventory', 'relation-dry-run', 'clock-type-audit', 'migration-up', 'dry-run', 'backup/snapshot', 'v3-snapshot-pre-migration-export',
        'governance-plan', 'controlled-apply', 'read-back',
    ];

    /** @param Closure(list<string>): array{0:int,1:string,2:string} $executor */
    public function __construct(
        private readonly string $target,
        private readonly string $pluginPath,
        private readonly Closure $executor,
        private readonly ?string $sshKey = null,
        /** @var array{migration_runtime:string,authorized_database:string,environment:string}|null */
        private readonly ?array $migrationConfig = null,
    ) {}

    public static function fromEnvironment(?Closure $executor = null): self
    {
        $path = getenv('NHK_DEMO_DEPLOY_CONFIG');
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return new self('demo.1945.vn', '', $executor ?? self::defaultExecutor());
        }
        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($values) || !is_string($values['ssh_target'] ?? null) || !is_string($values['remote_path'] ?? null)) {
            return new self('demo.1945.vn', '', $executor ?? self::defaultExecutor());
        }
        $key = $values['ssh_key'] ?? null;
        $migrationRuntime = $values['migration_runtime'] ?? null;
        $authorizedDatabase = $values['authorized_migration_database'] ?? null;
        $environment = $values['environment_type'] ?? null;
        $migrationConfig = is_string($migrationRuntime) && $migrationRuntime !== ''
            && is_string($authorizedDatabase) && $authorizedDatabase !== ''
            && is_string($environment) && $environment !== ''
            ? ['migration_runtime' => $migrationRuntime, 'authorized_database' => $authorizedDatabase, 'environment' => $environment]
            : null;
        return new self(
            (string) $values['ssh_target'],
            rtrim((string) $values['remote_path'], '/'),
            $executor ?? self::defaultExecutor(),
            is_string($key) && $key !== '' ? $key : null,
            $migrationConfig,
        );
    }

    public function run(DemoCutoverContext $context, string $operation): StageResult
    {
        if ($context->target !== $this->target || $this->target !== 'demo.1945.vn') {
            return StageResult::blocked('RUNTIME_TARGET_NOT_ALLOWLISTED');
        }
        if (!in_array($operation, self::OPERATIONS, true)) {
            return StageResult::blocked('REMOTE_OPERATION_NOT_ALLOWLISTED');
        }
        if ($this->pluginPath === '' || preg_match('#^/[^\0]+$#', $this->pluginPath) !== 1) {
            return StageResult::blocked('REMOTE_PLUGIN_PATH_INVALID');
        }
        if ($operation === 'migration-up' && $this->migrationConfig === null) {
            return StageResult::blocked('MIGRATION_AUTHORIZATION_CONFIG_REQUIRED');
        }
        if ($operation === 'migration-up' && preg_match('/^[a-f0-9]{40}$/i', $context->sourceRevision) !== 1) {
            return StageResult::blocked('MIGRATION_SOURCE_REVISION_INVALID');
        }

        $command = [
            'ssh', '-o', 'BatchMode=yes',
        ];
        if ($this->sshKey !== null) $command = array_merge($command, ['-i', $this->sshKey]);
        $command = array_merge($command, [
            $this->target,
        ]);
        if ($operation === 'migration-up') {
            $command[] = 'env';
            $command[] = 'NHK_MIGRATION_RUNTIME=' . $this->migrationConfig['migration_runtime'];
            $command[] = 'NHK_AUTHORIZED_MIGRATION_DATABASE=' . $this->migrationConfig['authorized_database'];
            $command[] = 'WP_ENVIRONMENT_TYPE=' . $this->migrationConfig['environment'];
        }
        $command = array_merge($command, [
            'php', $this->pluginPath . '/bin/nhk-core-maintenance.php',
            '--operation=' . $operation,
            '--pack=' . $context->pack,
            '--run-id=' . $context->runId,
            '--source-revision=' . $context->sourceRevision,
            '--json',
        ]);
        if ($operation === 'v3-snapshot-pre-migration-export') {
            $command[] = '--output=/tmp/nhk-v3-pre-migration-' . hash('sha256', $context->runId) . '.json';
        }
        $result = ($this->executor)($command);
        if ($result[0] !== 0) {
            $decoded = json_decode($result[1], true);
            if ($operation === 'clock-type-audit' && is_array($decoded) && in_array(($decoded['reason_code'] ?? null), ['REMOTE_OPERATION_NOT_ALLOWLISTED', 'LIVE_AUDIT_SURFACE_NOT_EXPOSED'], true)) {
                return StageResult::blocked('LIVE_AUDIT_SURFACE_NOT_EXPOSED');
            }
            if (in_array($operation, ['migration-up', 'v3-snapshot-pre-migration-export'], true) && is_array($decoded) && is_string($decoded['reason_code'] ?? null) && $decoded['reason_code'] !== '') {
                return StageResult::failed($decoded['reason_code']);
            }
            return StageResult::failed('REMOTE_RUNTIME_EXECUTION_FAILED');
        }
        try {
            $payload = json_decode($result[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return StageResult::failed('REMOTE_RUNTIME_INVALID_RECEIPT');
        }
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'pass') {
            return StageResult::blocked((string) ($payload['reason_code'] ?? 'REMOTE_RUNTIME_UNAVAILABLE'));
        }
        if ($operation === 'migration-up') {
            foreach (['pack' => $context->pack, 'run_id' => $context->runId, 'source_revision' => $context->sourceRevision] as $field => $expected) {
                if (!is_string($payload[$field] ?? null) || !hash_equals($expected, $payload[$field])) {
                    return StageResult::failed($field === 'source_revision' ? 'REMOTE_SOURCE_REVISION_MISMATCH' : 'REMOTE_MAINTENANCE_CONTEXT_MISMATCH');
                }
            }
        }
        if ($operation === 'migration-up') {
            $expectedTarget = SpecimenProductRelationMigration026::VERSION;
            if ((int) ($payload['current'] ?? 0) !== $expectedTarget || (int) ($payload['target'] ?? 0) !== $expectedTarget) return StageResult::failed('MIGRATION_TARGET_NOT_REACHED');
            if (($payload['dictionary_entry_sense_schema_ready'] ?? false) !== true) return StageResult::failed('DICTIONARY_ENTRY_SENSE_SCHEMA_NOT_READY');
            if (($payload['specimen_product_relation_schema_ready'] ?? false) !== true) return StageResult::failed('SPECIMEN_PRODUCT_RELATION_SCHEMA_NOT_READY');
        }
        if ($operation === 'v3-snapshot-pre-migration-export') {
            $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
            $migration = is_array($manifest['migration_level'] ?? null) ? $manifest['migration_level'] : [];
            if (($manifest['export_mode'] ?? null) !== 'pre_migration'
                || (int) ($migration['current'] ?? -1) !== SpecimenProductRelationMigration026::VERSION - 1
                || (int) ($migration['target'] ?? -1) !== SpecimenProductRelationMigration026::VERSION
                || ($manifest['source_environment'] ?? null) !== 'staging'
                || ($this->migrationConfig !== null && ($manifest['source_database_identity'] ?? null) !== $this->migrationConfig['authorized_database'])) {
                return StageResult::failed('STAGING_BACKUP_RECEIPT_INVALID');
            }
            if (($payload['receipt']['status'] ?? null) !== 'backup_created') return StageResult::failed('STAGING_BACKUP_RECEIPT_INVALID');
        }
        $metadata = in_array($operation, ['migration-up', 'v3-snapshot-pre-migration-export'], true) ? $payload : [];
        return StageResult::pass(
            is_string($payload['identifier'] ?? null) ? $payload['identifier'] : 'remote-' . $operation,
            is_string($payload['fingerprint'] ?? null) ? $payload['fingerprint'] : null,
            $metadata,
        );
    }

    /** @return Closure(list<string>): array{0:int,1:string,2:string} */
    private static function defaultExecutor(): Closure
    {
        return static function (array $command): array {
            $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) return [127, '', 'Unable to start runtime transport.'];
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process), (string) $stdout, (string) $stderr];
        };
    }
}
