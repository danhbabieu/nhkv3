<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Governance;

use InvalidArgumentException;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class ApplyAttempt
{
    public function __construct(
        public string $id,
        public string $proposalId,
        public int $number,
        public string $state,
        public ?string $resultEntityUuid = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {
        if (!UuidCodec::isValid($id) || !UuidCodec::isValid($proposalId)) throw new InvalidArgumentException('Apply attempt identity is invalid.');
        if ($number < 1 || !in_array($state, ['pending', 'running', 'succeeded', 'failed'], true)) throw new InvalidArgumentException('Apply attempt state or number is invalid.');
        if ($resultEntityUuid !== null && !UuidCodec::isValid($resultEntityUuid)) throw new InvalidArgumentException('Apply attempt result identity is invalid.');
    }
}
