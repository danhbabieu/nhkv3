<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

use InvalidArgumentException;

final readonly class Proposal
{
    public function __construct(
        public string $id,
        public string $subjectId,
        public string $operation,
        public array $payload,
        public string $contentFingerprint,
        public int $expectedRevision,
        public string $dependencyFingerprint,
        public ProposalState $state = ProposalState::DRAFT,
        public ?string $actor = null,
        public ?string $decisionActor = null,
        public ?string $decidedAt = null,
        public string $idempotencyKey = '',
        public int $revision = 1,
        public ?string $submittedAt = null,
        public ?string $appliedAt = null,
        public ?string $targetUuid = null,
        public string $entityType = '',
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if ($id === '' || $subjectId === '' || $operation === '' || $contentFingerprint === '' || $dependencyFingerprint === '') {
            throw new InvalidArgumentException('Proposal identity and binding fields are required.');
        }
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be positive.');
        }
        if ($revision < 1) throw new InvalidArgumentException('Proposal revision must be positive.');
    }

    public function bindingFingerprint(): string
    {
        return hash('sha256', ($this->entityType ?: $this->subjectId) . "\n" . $this->operation . "\n" . ($this->targetUuid ?: $this->subjectId) . "\n" . $this->contentFingerprint . "\n" . $this->expectedRevision . "\n" . $this->dependencyFingerprint);
    }
}
