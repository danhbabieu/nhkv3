<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class VideoRelationCandidate
{
    public function __construct(
        public string $sourceType,
        public string $sourceKey,
        public string $targetId,
        public string $targetType,
        public string $predicate,
        public string $origin,
        public array $evidenceRefs,
        public string $reason = '',
        public float $confidence = 0.0,
    ) {
    }

    /** @return array<string,mixed> */
    public function toProposalPayload(): array
    {
        // Relation endpoint identity is canonical UUID identity. Keep the
        // older key names as read-compatible aliases for existing packets;
        // new proposals must always carry the explicit UUID fields.
        return ['source_type' => $this->sourceType, 'source_uuid' => $this->sourceKey, 'source_key' => $this->sourceKey, 'target_type' => $this->targetType, 'target_uuid' => $this->targetId, 'target_key' => $this->targetId, 'predicate' => $this->predicate, 'origin' => $this->origin, 'evidence_refs' => $this->evidenceRefs, 'reason' => $this->reason, 'confidence' => $this->confidence];
    }
}
