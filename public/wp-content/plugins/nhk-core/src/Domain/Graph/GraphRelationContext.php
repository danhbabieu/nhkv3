<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Graph;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class GraphRelationContext
{
    public const ACTIVE = 'ACTIVE';
    public const RETIRED = 'RETIRED';

    public function __construct(
        public string $contextUuid,
        public string $edgeUuid,
        public int $sourceRevision,
        public int $targetRevision,
        public string $scopeCode,
        public string $scopeSubjectType,
        public string $scopeSubjectId,
        public string $provenanceClass,
        public array $evidenceRefs,
        public string $approvalFingerprint,
        public string $idempotencyKey,
        public string $state = self::ACTIVE,
        public int $revision = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $retiredAt = null,
    ) {
        if (!UuidCodec::isValid($contextUuid) || !UuidCodec::isValid($edgeUuid)) throw new \InvalidArgumentException('Graph relation context identity is invalid.');
        if ($sourceRevision < 1 || $targetRevision < 1 || $revision < 1) throw new \InvalidArgumentException('Graph relation context revision is invalid.');
        if ($scopeCode === '' || $scopeSubjectType === '' || $scopeSubjectId === '') throw new \InvalidArgumentException('Graph relation context scope is required.');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $scopeSubjectType)) throw new \InvalidArgumentException('Graph relation context scope subject type is invalid.');
        if (!in_array($state, [self::ACTIVE, self::RETIRED], true)) throw new \InvalidArgumentException('Graph relation context state is invalid.');
        if ($approvalFingerprint === '' || !preg_match('/^[a-f0-9]{64}$/i', $approvalFingerprint)) throw new \InvalidArgumentException('Graph relation context approval fingerprint is invalid.');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Graph relation context idempotency key is required.');
        foreach ($evidenceRefs as $ref) {
            if (!is_array($ref) || !UuidCodec::isValid((string) ($ref['evidence_id'] ?? ''))) throw new \InvalidArgumentException('Graph relation context Evidence reference is invalid.');
        }
    }

    public static function create(string $contextUuid, string $edgeUuid, int $sourceRevision, int $targetRevision, string $scopeCode, string $scopeSubjectType, string $scopeSubjectId, string $provenanceClass, array $evidenceRefs, string $approvalFingerprint, string $idempotencyKey): self
    {
        return new self($contextUuid, $edgeUuid, $sourceRevision, $targetRevision, $scopeCode, $scopeSubjectType, $scopeSubjectId, $provenanceClass, $evidenceRefs, $approvalFingerprint, $idempotencyKey);
    }

    public function retired(int $expectedRevision): self
    {
        if ($this->revision !== $expectedRevision) throw new \RuntimeException('GRAPH_RELATION_CONTEXT_REVISION_CONFLICT');
        if ($this->state === self::RETIRED) throw new \RuntimeException('GRAPH_RELATION_CONTEXT_ALREADY_RETIRED');
        return new self($this->contextUuid, $this->edgeUuid, $this->sourceRevision, $this->targetRevision, $this->scopeCode, $this->scopeSubjectType, $this->scopeSubjectId, $this->provenanceClass, $this->evidenceRefs, $this->approvalFingerprint, $this->idempotencyKey, self::RETIRED, $expectedRevision + 1, $this->createdAt, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u'));
    }

    public function reactivated(int $expectedRevision): self
    {
        if ($this->revision !== $expectedRevision) throw new \RuntimeException('GRAPH_RELATION_CONTEXT_REVISION_CONFLICT');
        if ($this->state === self::ACTIVE) throw new \RuntimeException('GRAPH_RELATION_CONTEXT_ALREADY_ACTIVE');
        return new self($this->contextUuid, $this->edgeUuid, $this->sourceRevision, $this->targetRevision, $this->scopeCode, $this->scopeSubjectType, $this->scopeSubjectId, $this->provenanceClass, $this->evidenceRefs, $this->approvalFingerprint, $this->idempotencyKey, self::ACTIVE, $expectedRevision + 1, $this->createdAt, gmdate('Y-m-d H:i:s.u'), null);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['context_uuid' => $this->contextUuid, 'edge_uuid' => $this->edgeUuid, 'source_revision' => $this->sourceRevision, 'target_revision' => $this->targetRevision, 'scope_code' => $this->scopeCode, 'scope_subject_type' => $this->scopeSubjectType, 'scope_subject_id' => $this->scopeSubjectId, 'provenance_class' => $this->provenanceClass, 'evidence_refs' => $this->evidenceRefs, 'approval_fingerprint' => $this->approvalFingerprint, 'idempotency_key' => $this->idempotencyKey, 'state' => $this->state, 'revision' => $this->revision, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt, 'retired_at' => $this->retiredAt];
    }
}
