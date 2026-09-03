<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use Closure;
use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\StageResult;

/** Generic, target-allowlisted transport for the nhk-core artifact only. */
final class RemoteDeploymentAdapter
{
    /** @param Closure(array<string>): array{0:int,1:string,2:string} $executor */
    public function __construct(
        private readonly string $root,
        private readonly ?string $configPath,
        private readonly Closure $executor,
    ) {}

    /** @param Closure(array<string>): array{0:int,1:string,2:string}|null $executor */
    public static function fromEnvironment(string $root, ?Closure $executor = null): self
    {
        $path = getenv('NHK_DEMO_DEPLOY_CONFIG');
        return new self($root, is_string($path) && $path !== '' ? $path : null, $executor ?? static function (array $command): array {
            $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                return [127, '', 'Unable to start deployment transport.'];
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), (string) $stdout, (string) $stderr];
        });
    }

    public function deploy(DemoCutoverContext $context): StageResult
    {
        if ($context->target !== 'demo.1945.vn') {
            return StageResult::blocked('DEPLOYMENT_TARGET_NOT_ALLOWLISTED');
        }
        if (!is_file($this->root . '/public/wp-content/plugins/nhk-core/nhk-core.php')) {
            return StageResult::blocked('NHK_CORE_ARTIFACT_UNAVAILABLE');
        }
        $config = $this->loadConfig();
        if ($config === null) {
            return StageResult::blocked('REMOTE_DEPLOYMENT_CONFIG_REQUIRED');
        }
        if ($config['ssh_target'] !== 'demo.1945.vn') {
            return StageResult::blocked('DEPLOYMENT_TARGET_NOT_ALLOWLISTED');
        }
        if ($config['remote_path'] === '') {
            return StageResult::blocked('REMOTE_DEPLOYMENT_PATH_REQUIRED');
        }
        if ($config['ssh_key'] !== null && !is_readable($config['ssh_key'])) {
            return StageResult::blocked('REMOTE_DEPLOYMENT_CREDENTIAL_UNAVAILABLE');
        }
        if ($config['ssh_key'] === null && (!is_string(getenv('SSH_AUTH_SOCK')) || getenv('SSH_AUTH_SOCK') === '')) {
            return StageResult::blocked('REMOTE_DEPLOYMENT_CREDENTIAL_UNAVAILABLE');
        }

        $source = $this->root . '/public/wp-content/plugins/nhk-core/';
        $fingerprint = $this->artifactFingerprint($source);
        if ($fingerprint === null) {
            return StageResult::blocked('NHK_CORE_ARTIFACT_INVALID');
        }
        $ssh = ['ssh', '-o', 'BatchMode=yes'];
        if ($config['ssh_key'] !== null) {
            $ssh = array_merge($ssh, ['-i', $config['ssh_key']]);
        }
        $rsync = ['rsync', '--archive', '--delete', '--checksum', '--safe-links', '--exclude', 'tests/', '--exclude', '*.env', '--exclude', '*.pem', '-e', implode(' ', array_map('escapeshellarg', $ssh)), $source, $config['ssh_target'] . ':' . rtrim($config['remote_path'], '/') . '/'];
        $transfer = ($this->executor)($rsync);
        if ($transfer[0] !== 0) {
            return StageResult::failed('REMOTE_DEPLOYMENT_FAILED');
        }
        $verification = ($this->executor)(array_merge($ssh, [$config['ssh_target'], 'test', '-f', rtrim($config['remote_path'], '/') . '/nhk-core.php']));
        if ($verification[0] !== 0) {
            return StageResult::failed('REMOTE_DEPLOYMENT_VERIFICATION_FAILED');
        }
        return StageResult::pass('nhk-core:' . $fingerprint, $fingerprint);
    }

    /** @return array{ssh_target:string,remote_path:string,ssh_key:?string}|null */
    private function loadConfig(): ?array
    {
        if ($this->configPath === null || !is_readable($this->configPath)) {
            return null;
        }
        $values = parse_ini_file($this->configPath, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            return null;
        }
        $target = $values['ssh_target'] ?? null;
        $remotePath = $values['remote_path'] ?? null;
        if (!is_string($target) || !is_string($remotePath)) {
            return null;
        }
        $key = $values['ssh_key'] ?? null;
        return ['ssh_target' => $target, 'remote_path' => $remotePath, 'ssh_key' => is_string($key) && $key !== '' ? $key : null];
    }

    private function artifactFingerprint(string $directory): ?string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($directory));
            if (preg_match('/(^|\/)(?:\.env|.*\.pem)$/i', $relative) === 1) {
                return null;
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
