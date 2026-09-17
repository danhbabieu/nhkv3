<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaBindingOperation;

interface MediaBindingOperationRepository
{
    public function findByOperationId(string $operationId): ?MediaBindingOperation;
    public function findByIdempotencyKey(string $idempotencyKey): ?MediaBindingOperation;
    public function create(MediaBindingOperation $operation): MediaBindingOperation;
    public function save(MediaBindingOperation $operation, int $expectedRevision): MediaBindingOperation;
}
