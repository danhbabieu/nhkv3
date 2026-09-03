<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Authority;

final readonly class SemanticMergeReceipt
{
    /** @param list<array<string,mixed>> $references */
    public function __construct(
        public string $sourceUuid,
        public string $targetUuid,
        public int $sourceRevision,
        public int $targetRevision,
        public string $planFingerprint,
        public array $references,
        public int $moved,
        public int $deduped,
        public int $remaining,
        public string $sourceLifecycle,
        public bool $readBackVerified,
        public string $operation = 'merge',
        public string $status = 'completed',
        public int $referencesDiscovered = 0,
        public int $referencesMoved = 0,
        public int $referencesDeduped = 0,
        public int $referencesRemaining = 0,
        public string $applyAttemptId = '',
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public string $idempotencyKey = '',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
