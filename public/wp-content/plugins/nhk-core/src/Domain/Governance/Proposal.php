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
    ) {
        if ($id === '' || $subjectId === '' || $operation === '' || $contentFingerprint === '' || $dependencyFingerprint === '') {
            throw new InvalidArgumentException('Proposal identity and binding fields are required.');
        }
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be positive.');
        }
    }

    public function bindingFingerprint(): string
    {
        return hash('sha256', $this->subjectId . "\n" . $this->operation . "\n" . $this->contentFingerprint . "\n" . $this->expectedRevision . "\n" . $this->dependencyFingerprint);
    }
}
