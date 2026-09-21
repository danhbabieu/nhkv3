<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Typed, bounded request for repairing one existing canonical Knowledge claim. */
final readonly class KnowledgeRepairIntent
{
    public const CLEANUP_CLASSES = ['PROCESS_CONTAMINATION', 'EDITORIAL_FRAGMENT', 'DUPLICATE_DISCLAIMER', 'INTERNAL_WORKFLOW_KNOWLEDGE'];

    public function __construct(
        public string $targetUuid,
        public int $expectedRevision,
        public string $operation,
        public ?string $text,
        public ?string $claimType,
        public string $reason,
        public array $provenance,
        public string $cleanupClass,
    ) {
        if (!UuidCodec::isValid($targetUuid) || $expectedRevision < 1) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_TARGET_REVISION_INVALID');
        if (!in_array($operation, ['update', 'retire'], true)) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_OPERATION_INVALID');
        if (!in_array($cleanupClass, self::CLEANUP_CLASSES, true)) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_CLEANUP_CLASS_INVALID');
        if ($reason === '' || strlen($reason) > 2000 || $provenance === []) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_PROVENANCE_REQUIRED');
        if ($operation === 'update' && $text === null && $claimType === null) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_DELTA_REQUIRED');
        if ($text !== null && ($text === '' || strlen($text) > 10000)) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_TEXT_DELTA_INVALID');
        if ($claimType !== null && !in_array($claimType, ['fact', 'specification', 'history', 'technical', 'provenance', 'other'], true)) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_TYPE_DELTA_INVALID');
    }

    public static function fromArray(array $input): self
    {
        if (array_key_exists('stable_key', $input) || array_key_exists('stableKey', $input)) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_STABLE_KEY_FORBIDDEN');
        $delta = is_array($input['delta'] ?? null) ? $input['delta'] : $input;
        $text = array_key_exists('text', $delta) ? trim((string) $delta['text']) : null;
        $claimType = array_key_exists('claim_type', $delta) ? trim((string) $delta['claim_type']) : null;
        return new self(trim((string) ($input['canonical_knowledge_uuid'] ?? $input['target_uuid'] ?? '')), (int) ($input['expected_revision'] ?? 0), strtolower(trim((string) ($input['operation'] ?? ''))), $text, $claimType, trim((string) ($input['reason'] ?? '')), is_array($input['provenance'] ?? null) ? $input['provenance'] : [], strtoupper(trim((string) ($input['cleanup_class'] ?? ''))));
    }

    public function toArray(): array
    {
        return ['canonical_knowledge_uuid' => $this->targetUuid, 'expected_revision' => $this->expectedRevision, 'operation' => $this->operation, 'delta' => array_filter(['text' => $this->text, 'claim_type' => $this->claimType], static fn (mixed $value): bool => $value !== null), 'reason' => $this->reason, 'provenance' => $this->provenance, 'cleanup_class' => $this->cleanupClass];
    }
}
