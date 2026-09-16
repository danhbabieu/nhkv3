<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class RecoveryMcpRuntimeBinding
{
    public const EXPECTED_DATABASE = 'nhk_v3_video_recovery';
    public const EXPECTED_ENVIRONMENT = 'v3-video-recovery-1309';
    public const EXPECTED_MODE = 'recovery';

    /**
     * @param object|null $database WordPress' wpdb-compatible database object.
     * @param callable(string):(?string)|null $runtimeValue Runtime configuration reader.
     */
    public function __construct(
        private readonly ?object $database = null,
        ?callable $runtimeValue = null,
    ) {
        $this->runtimeValue = $runtimeValue === null ? null : \Closure::fromCallable($runtimeValue);
    }

    private readonly ?\Closure $runtimeValue;

    /**
     * @return array{ok: bool, reason_code: string|null, environment: string, database: string}
     */
    public function check(): array
    {
        $environment = $this->runtimeValue('NHK_RUNTIME_ENVIRONMENT');
        $mode = $this->runtimeValue('NHK_RUNTIME_MODE');

        if ($environment !== self::EXPECTED_ENVIRONMENT) {
            return $this->failure('RECOVERY_MCP_ENVIRONMENT_MISMATCH', $environment, '');
        }

        if (strtolower((string) $mode) !== self::EXPECTED_MODE) {
            return $this->failure('RECOVERY_MCP_MODE_MISMATCH', $environment, '');
        }

        $database = $this->database ?? ($GLOBALS['wpdb'] ?? null);
        if (!is_object($database) || !method_exists($database, 'get_var')) {
            return $this->failure('RECOVERY_MCP_DATABASE_UNAVAILABLE', $environment, '');
        }

        try {
            $databaseName = (string) $database->get_var('SELECT DATABASE()');
        } catch (\Throwable) {
            return $this->failure('RECOVERY_MCP_DATABASE_UNAVAILABLE', $environment, '');
        }

        if ($databaseName !== self::EXPECTED_DATABASE) {
            return $this->failure('RECOVERY_MCP_DATABASE_MISMATCH', $environment, $databaseName);
        }

        return [
            'ok' => true,
            'reason_code' => null,
            'environment' => $environment,
            'database' => $databaseName,
        ];
    }

    private function runtimeValue(string $key): ?string
    {
        if (is_callable($this->runtimeValue)) {
            $value = ($this->runtimeValue)($key);
            return $value === null ? null : (string) $value;
        }

        if (defined($key)) {
            return (string) constant($key);
        }

        $value = getenv($key);
        return $value === false ? null : (string) $value;
    }

    /** @return array{ok: false, reason_code: string, environment: string, database: string} */
    private function failure(string $reason, ?string $environment, string $database): array
    {
        return [
            'ok' => false,
            'reason_code' => $reason,
            'environment' => (string) $environment,
            'database' => $database,
        ];
    }
}
