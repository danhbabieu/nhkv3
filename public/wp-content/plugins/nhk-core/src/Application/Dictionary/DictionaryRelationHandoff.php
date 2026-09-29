<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Prepares a governed semantic handoff; it intentionally has no apply method. */
final class DictionaryRelationHandoff
{
    /** @param callable(string,string):array|object|null $ownerResolver @param callable(string,string,string):bool $predicateRegistry */
    public function __construct(private $ownerResolver, private $predicateRegistry) {}

    public function prepare(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') throw new \InvalidArgumentException('DICTIONARY_HANDOFF_IDEMPOTENCY_KEY_REQUIRED');
        $sourceType = trim((string) ($input['owner_type'] ?? ''));
        $sourceId = trim((string) ($input['owner_id'] ?? ''));
        $predicate = trim((string) ($input['predicate'] ?? ''));
        $targetType = trim((string) ($input['target_type'] ?? ''));
        $targetId = trim((string) ($input['target_id'] ?? ''));
        if (!is_callable($this->predicateRegistry) || !($this->predicateRegistry)($sourceType, $predicate, $targetType)) return ['status' => 'REGISTRY_GAP', 'applied' => false, 'reason' => 'REGISTERED_PREDICATE_REQUIRED'];
        $source = is_callable($this->ownerResolver) ? ($this->ownerResolver)($sourceType, $sourceId) : null;
        $target = is_callable($this->ownerResolver) ? ($this->ownerResolver)($targetType, $targetId) : null;
        if (!$this->uniqueOwner($source) || !$this->uniqueOwner($target)) return ['status' => 'IDENTITY_CONFLICT', 'applied' => false, 'reason' => 'CANONICAL_OWNER_NOT_UNIQUE'];
        $packet = [
            'lexical_concept_id' => trim((string) ($input['concept_id'] ?? '')),
            'source' => ['type' => $sourceType, 'id' => $sourceId, 'revision' => (int) ($source['revision'] ?? 0)],
            'predicate' => $predicate,
            'target' => ['type' => $targetType, 'id' => $targetId, 'revision' => (int) ($target['revision'] ?? 0)],
            'provenance' => is_array($input['provenance'] ?? null) ? $input['provenance'] : [],
            'idempotency_key' => $key,
        ];
        return ['status' => 'REVIEW_REQUIRED', 'applied' => false, 'source' => $packet['source'], 'target' => $packet['target'], 'handoff' => $packet, 'next_step' => 'GOVERNANCE_PROPOSAL'];
    }

    private function uniqueOwner(mixed $owner): bool
    {
        return is_array($owner) && trim((string) ($owner['id'] ?? '')) !== '' && (int) ($owner['revision'] ?? 0) > 0;
    }
}
