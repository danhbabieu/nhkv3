<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use Closure;
use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\StageResult;

/** Executes only the versioned NHK maintenance entrypoint over SSH. */
final class RemoteRuntimeAdapter
{
    private const OPERATIONS = [
        'health', 'inventory', 'dry-run', 'backup/snapshot',
        'governance-plan', 'controlled-apply', 'read-back',
    ];

    /** @param Closure(list<string>): array{0:int,1:string,2:string} $executor */
    public function __construct(
        private readonly string $target,
        private readonly string $pluginPath,
        private readonly Closure $executor,
        private readonly ?string $sshKey = null,
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
        return new self(
            (string) $values['ssh_target'],
            rtrim((string) $values['remote_path'], '/'),
            $executor ?? self::defaultExecutor(),
            is_string($key) && $key !== '' ? $key : null,
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

        $command = [
            'ssh', '-o', 'BatchMode=yes',
        ];
        if ($this->sshKey !== null) $command = array_merge($command, ['-i', $this->sshKey]);
        $command = array_merge($command, [
            $this->target,
            'php', $this->pluginPath . '/bin/nhk-core-maintenance.php',
            '--operation=' . $operation,
            '--pack=' . $context->pack,
            '--run-id=' . $context->runId,
            '--source-revision=' . $context->sourceRevision,
            '--json',
        ]);
        $result = ($this->executor)($command);
        if ($result[0] !== 0) {
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
        return StageResult::pass(
            is_string($payload['identifier'] ?? null) ? $payload['identifier'] : 'remote-' . $operation,
            is_string($payload['fingerprint'] ?? null) ? $payload['fingerprint'] : null,
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
