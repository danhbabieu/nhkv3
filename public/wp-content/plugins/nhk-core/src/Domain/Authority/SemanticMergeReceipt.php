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
        $legacy = get_object_vars($this);
        return $legacy + [
            'operation' => $this->operation,
            'idempotency_key' => $this->idempotencyKey,
            'source_uuid' => $this->sourceUuid,
            'target_uuid' => $this->targetUuid,
            'source_revision' => $this->sourceRevision,
            'target_revision' => $this->targetRevision,
            'plan_fingerprint' => $this->planFingerprint,
            'references_discovered' => $this->referencesDiscovered,
            'references_moved' => $this->referencesMoved,
            'references_deduped' => $this->referencesDeduped,
            'references_remaining' => $this->referencesRemaining,
            'source_final_state' => $this->sourceLifecycle,
            'target_final_state' => 'active',
            'verification_result' => $this->readBackVerified ? 'PASS' : 'PENDING',
            'apply_attempt_id' => $this->applyAttemptId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
