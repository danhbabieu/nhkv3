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
        // Persistence stores dependency bindings as a fixed-width SHA-256
        // digest. Normalize the in-memory form so a reloaded proposal has the
        // exact same idempotency binding as the request that created it.
        $content = preg_match('/^[a-f0-9]{64}$/i', $this->contentFingerprint)
            ? strtolower($this->contentFingerprint)
            : hash('sha256', $this->contentFingerprint);
        $dependency = preg_match('/^[a-f0-9]{64}$/i', $this->dependencyFingerprint)
            ? strtolower($this->dependencyFingerprint)
            : hash('sha256', $this->dependencyFingerprint);
        $subject = $this->entityType ?: $this->subjectId;
        // When a target UUID is present it is the stable subject identity;
        // otherwise entity type is the persisted identity available to the
        // repository (the command payload remains part of the content hash).
        return hash('sha256', $subject . "\n" . $this->operation . "\n" . ($this->targetUuid ?: $subject) . "\n" . $content . "\n" . $this->expectedRevision . "\n" . $dependency);
    }

    public function transition(ProposalState $state, ?string $decisionActor = null, ?string $at = null, ?string $supersededBy = null): self
    {
        $allowed = match ($this->state) {
            ProposalState::DRAFT => [ProposalState::SUBMITTED, ProposalState::APPROVED, ProposalState::REJECTED, ProposalState::CANCELLED, ProposalState::SUPERSEDED],
            ProposalState::SUBMITTED => [ProposalState::APPROVED, ProposalState::REJECTED, ProposalState::CANCELLED, ProposalState::SUPERSEDED],
            ProposalState::APPROVED => [ProposalState::APPLIED, ProposalState::CANCELLED, ProposalState::SUPERSEDED],
            default => [],
        };
        if (!in_array($state, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Invalid proposal transition: %s -> %s.', $this->state->value, $state->value));
        }
        if ($state === ProposalState::SUPERSEDED && ($supersededBy === null || $supersededBy === $this->id)) {
            throw new InvalidArgumentException('A superseded proposal requires a different replacement proposal.');
        }
        $when=$at ?? gmdate('Y-m-d H:i:s.u');
        return new self($this->id, $this->subjectId, $this->operation, $this->payload, $this->contentFingerprint, $this->expectedRevision, $this->dependencyFingerprint, $state, $this->actor, $decisionActor ?? $this->decisionActor, $when, $this->idempotencyKey, $this->revision + 1, $this->submittedAt ?? ($state === ProposalState::SUBMITTED ? $when : null), $state === ProposalState::APPLIED ? $when : $this->appliedAt, $this->targetUuid, $this->entityType, $this->createdAt, gmdate('Y-m-d H:i:s.u'), $state === ProposalState::CANCELLED ? $when : $this->cancelledAt, $state === ProposalState::REJECTED ? $when : $this->rejectedAt, $state === ProposalState::SUPERSEDED ? $when : $this->supersededAt, $supersededBy ?? $this->supersededByProposalId);
    }
}
