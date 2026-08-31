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
        public ?string $cancelledAt = null,
        public ?string $rejectedAt = null,
        public ?string $supersededAt = null,
        public ?string $supersededByProposalId = null,
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

    public function transition(ProposalState $state, ?string $decisionActor = null, ?string $at = null): self
    {
        $when=$at ?? gmdate('Y-m-d H:i:s.u');
        return new self($this->id, $this->subjectId, $this->operation, $this->payload, $this->contentFingerprint, $this->expectedRevision, $this->dependencyFingerprint, $state, $this->actor, $decisionActor ?? $this->decisionActor, $when, $this->idempotencyKey, $this->revision + 1, $this->submittedAt ?? ($state === ProposalState::SUBMITTED ? $when : null), $state === ProposalState::APPLIED ? $when : $this->appliedAt, $this->targetUuid, $this->entityType, $this->createdAt, gmdate('Y-m-d H:i:s.u'), $state === ProposalState::CANCELLED ? $when : $this->cancelledAt, $state === ProposalState::REJECTED ? $when : $this->rejectedAt, $state === ProposalState::SUPERSEDED ? $when : $this->supersededAt, $this->supersededByProposalId);
    }
}
