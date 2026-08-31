<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Governance;

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
    ) {}
}
