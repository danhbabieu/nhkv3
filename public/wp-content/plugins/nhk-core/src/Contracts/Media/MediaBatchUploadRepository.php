<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface MediaBatchUploadRepository
{
    /** @return array<string,mixed>|null */
    public function find(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $record */
    public function save(string $idempotencyKey, array $record): void;
}
