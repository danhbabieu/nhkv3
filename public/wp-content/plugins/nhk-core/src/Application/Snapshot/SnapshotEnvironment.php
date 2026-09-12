<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotEnvironment
{
    public function __construct(
        public readonly string $name,
        public readonly string $site,
        public readonly string $database,
        public readonly string $runtimeMode,
    ) {
        if ($this->name === '' || $this->site === '' || $this->database === '' || $this->runtimeMode === '') {
            throw new \InvalidArgumentException('SNAPSHOT_ENVIRONMENT_IDENTITY_REQUIRED');
        }
    }

    public function isStaging(): bool
    {
        return strtolower($this->runtimeMode) === 'staging'
            || strtolower($this->name) === 'staging'
            || str_contains(strtolower(parse_url($this->site, PHP_URL_HOST) ?: $this->site), 'demo.1945.vn');
    }

    public function isProduction(): bool
    {
        return strtolower($this->runtimeMode) === 'production' || strtolower($this->name) === 'production';
    }

    /** @return array{name:string,site:string,database:string,runtime_mode:string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'site' => $this->site,
            'database' => $this->database,
            'runtime_mode' => $this->runtimeMode,
        ];
    }
}
