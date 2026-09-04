<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\PublicIdentity;

interface PublicIdentityRepository
{
    public function allocate(array $record, string $idempotencyKey): array;
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array;
    public function findCurrentById(string $identityId): ?array;
    public function resolveHistoric(string $path): array;
}
