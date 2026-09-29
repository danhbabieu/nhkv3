<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\DictionaryConcept;

/**
 * The lexical mutation boundary. It owns Dictionary curation only; semantic
 * owner changes are deliberately handled by DictionaryRelationHandoff and
 * Governance elsewhere.
 */
final class DictionaryMutationService
{
    /** @param callable(string,string):(?array)|null $receiptReader @param callable(string,string,array):void|null $receiptWriter */
    public function __construct(
        private DictionaryConceptRepository $concepts,
        private $labels = null,
        private $receiptReader = null,
        private $receiptWriter = null,
        private $actor = null,
    ) {}

    public function updateConcept(string $conceptId, int $expectedRevision, string $preferredLabel, string $definition, array $context, string $idempotencyKey): array
    {
        $current = $this->requireConcept($conceptId);
        $payload = ['operation' => 'update', 'concept_id' => $conceptId, 'expected_revision' => $expectedRevision, 'preferred_label' => trim($preferredLabel), 'definition' => trim($definition), 'context' => $this->sort($context)];
        return $this->mutate($idempotencyKey, $payload, function () use ($current, $expectedRevision, $preferredLabel, $definition, $context): array {
            $updated = new DictionaryConcept($current->conceptId, trim($preferredLabel), trim($definition), $current->status, $current->destinationType, $current->destinationId, $current->destinationUrl, array_merge($current->context, $context), $current->revision);
            return ['concept' => $this->concepts->updateConcept($updated, $expectedRevision)];
        });
    }

    public function setConceptStatus(string $conceptId, int $expectedRevision, string $status, string $idempotencyKey): array
    {
        if (!in_array($status, [DictionaryConcept::APPROVED, DictionaryConcept::RETIRED, DictionaryConcept::DRAFT], true)) throw new \InvalidArgumentException('DICTIONARY_STATUS_INVALID');
        $current = $this->requireConcept($conceptId);
        $payload = ['operation' => 'set_status', 'concept_id' => $conceptId, 'expected_revision' => $expectedRevision, 'status' => $status];
        return $this->mutate($idempotencyKey, $payload, function () use ($current, $expectedRevision, $status): array {
            $updated = new DictionaryConcept($current->conceptId, $current->preferredLabel, $current->definition, $status, $current->destinationType, $current->destinationId, $current->destinationUrl, $current->context, $current->revision);
            return ['concept' => $this->concepts->updateConcept($updated, $expectedRevision)];
        });
    }

    private function requireConcept(string $conceptId): DictionaryConcept
    {
        $concept = $this->concepts->findById($conceptId);
        if (!$concept instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_CONCEPT_NOT_FOUND');
        return $concept;
    }

    private function mutate(string $idempotencyKey, array $payload, callable $operation): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') throw new \InvalidArgumentException('DICTIONARY_IDEMPOTENCY_KEY_REQUIRED');
        $fingerprint = hash('sha256', json_encode($this->sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (is_callable($this->receiptReader)) {
            $existing = ($this->receiptReader)($key, $fingerprint);
            if (is_array($existing)) {
                if (($existing['fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('DICTIONARY_IDEMPOTENCY_CONFLICT');
                return (array) ($existing['result'] ?? []);
            }
        }
        $result = $operation();
        if (is_callable($this->receiptWriter)) ($this->receiptWriter)($key, $fingerprint, $result + ['actor_user_id' => is_callable($this->actor) ? ($this->actor)() : null]);
        return $result;
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
