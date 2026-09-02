<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class SemanticProposalCommand
{
    /** @param array<string,mixed> $payload @param list<string> $dependencySlots */
    public function __construct(
        public string $slot,
        public string $operation,
        public string $entityType,
        public string $subjectId,
        public ?string $targetUuid,
        public int $expectedRevision,
        public array $payload,
        public array $dependencySlots,
        public string $idempotencyKey,
        public string $contentFingerprint,
        public string $dependencyFingerprint,
    ) {}
}
