<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class RecoveryRuntimeGuard
{
    /** @param list<string> $allowedDatabases */
    public function __construct(private readonly array $allowedDatabases) {}

    public function assertImportAllowed(SnapshotEnvironment $source, SnapshotEnvironment $target, bool $explicitRecoveryMode): void
    {
        if (!$explicitRecoveryMode) throw new \RuntimeException('RECOVERY_MODE_CONFIRMATION_REQUIRED');
        if ($target->isStaging()) throw new \RuntimeException('SNAPSHOT_IMPORT_STAGING_FORBIDDEN');
        if ($target->isProduction()) throw new \RuntimeException('SNAPSHOT_IMPORT_PRODUCTION_FORBIDDEN');
        if (strtolower($target->runtimeMode) !== 'recovery' || in_array(strtolower($target->name), ['staging', 'production', 'test'], true)) {
            throw new \RuntimeException('SNAPSHOT_IMPORT_RECOVERY_RUNTIME_REQUIRED');
        }
        if ($target->site === 'https://demo.1945.vn' || $target->site === 'http://demo.1945.vn') throw new \RuntimeException('SNAPSHOT_IMPORT_DEMO_SITE_FORBIDDEN');
        if ($source->database === $target->database) throw new \RuntimeException('SNAPSHOT_IMPORT_SOURCE_DATABASE_FORBIDDEN');
        if ($this->allowedDatabases === [] || !in_array($target->database, $this->allowedDatabases, true)) throw new \RuntimeException('SNAPSHOT_IMPORT_DATABASE_NOT_ALLOWLISTED');
    }
}
