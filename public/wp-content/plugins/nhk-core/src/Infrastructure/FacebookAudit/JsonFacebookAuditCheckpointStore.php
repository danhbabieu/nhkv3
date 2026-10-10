<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\FacebookAudit;

use InvalidArgumentException;
use NHK\Core\Application\FacebookAudit\FacebookAuditCheckpoint;
use NHK\Core\Contracts\FacebookAudit\FacebookAuditCheckpointStore;
use NHK\Core\Domain\FacebookAudit\FacebookAuditScope;

final class JsonFacebookAuditCheckpointStore implements FacebookAuditCheckpointStore
{
    public function __construct(private string $path) {}

    public function load(FacebookAuditScope $scope): ?FacebookAuditCheckpoint
    {
        if (!is_file($this->path)) return null;
        $contents = trim((string) file_get_contents($this->path));
        if ($contents === '') return null;
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('INVALID_CHECKPOINT');
        return FacebookAuditCheckpoint::fromArray($decoded, $scope);
    }

    public function save(FacebookAuditCheckpoint $checkpoint): void
    {
        $json = json_encode($checkpoint->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) throw new InvalidArgumentException('CHECKPOINT_WRITE_FAILED');
    }
}
