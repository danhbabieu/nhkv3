<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Governance;

use InvalidArgumentException;

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
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (!preg_match($uuid, $id) || !preg_match($uuid, $proposalId)) throw new InvalidArgumentException('Apply attempt identity is invalid.');
        if ($number < 1 || !in_array($state, ['pending', 'running', 'succeeded', 'failed'], true)) throw new InvalidArgumentException('Apply attempt state or number is invalid.');
        if ($resultEntityUuid !== null && !preg_match($uuid, $resultEntityUuid)) throw new InvalidArgumentException('Apply attempt result identity is invalid.');
    }
}
