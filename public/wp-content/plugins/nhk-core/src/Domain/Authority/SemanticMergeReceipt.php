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
    ) {}
}
