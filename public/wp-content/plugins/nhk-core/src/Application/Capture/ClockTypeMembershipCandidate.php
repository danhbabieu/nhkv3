<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Typed, revision-bound input to the governed relation lifecycle. */
final readonly class ClockTypeMembershipCandidate
{
    public const PREDICATE = 'classified_as';

    public function __construct(
        public string $candidateId,
        public string $sourceType,
        public string $sourceUuid,
        public int $sourceRevision,
        public string $targetClassificationUuid,
        public int $targetRevision,
        public string $targetFamily,
        public string $resolutionBasis,
        public string $provenanceClass,
        public string $supportStatus,
        public ?string $captureId = null,
        public ?int $captureRevision = null,
        public string $candidateStatus = 'QUALIFIED',
        public array $blockers = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'candidate_id' => $this->candidateId,
            'source_type' => $this->sourceType,
            'source_uuid' => $this->sourceUuid,
            'source_revision' => $this->sourceRevision,
            'target_type' => 'classification',
            'target_classification_uuid' => $this->targetClassificationUuid,
            'target_revision' => $this->targetRevision,
            'target_family' => $this->targetFamily,
            'predicate' => self::PREDICATE,
            'resolution_basis' => $this->resolutionBasis,
            'provenance_class' => $this->provenanceClass,
            'support_status' => $this->supportStatus,
            'capture_id' => $this->captureId,
            'capture_revision' => $this->captureRevision,
            'candidate_status' => $this->candidateStatus,
            'blockers' => array_values($this->blockers),
        ];
    }
}
