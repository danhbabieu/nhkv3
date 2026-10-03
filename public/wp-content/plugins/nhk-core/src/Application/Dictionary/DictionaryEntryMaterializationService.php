<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Applies only an exact, reviewed, safe one-Concept-to-one-Entry plan. */
final class DictionaryEntryMaterializationService
{
    /**
     * @param object $entries repository with findForConcept() and createWithSense()
     * @param callable(string,string):(?array)|null $receiptReader
     * @param callable(string,string,array):void|null $receiptWriter
     * @param callable(array):void|null $auditWriter
     * @param callable():bool|null $entrySenseReady
     */
    public function __construct(
        private DictionaryConceptRepository $concepts,
        private object $entries,
        private $receiptReader = null,
        private $receiptWriter = null,
        private $auditWriter = null,
        private $entrySenseReady = null,
    ) {}

    public function apply(array $plan, string $approvedFingerprint, string $idempotencyKey): array
    {
        if (is_callable($this->entrySenseReady) && !(bool) ($this->entrySenseReady)()) {
            throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE');
        }
        if (($plan['status'] ?? null) !== 'READY' || trim($approvedFingerprint) === '' || ($plan['fingerprint'] ?? '') !== $approvedFingerprint) {
            throw new \RuntimeException('PLAN_REAPPROVAL_REQUIRED');
        }
        if (!method_exists($this->entries, 'createWithSense')) {
            throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        }
        $key = trim($idempotencyKey);
        if ($key === '') throw new \InvalidArgumentException('DICTIONARY_IDEMPOTENCY_KEY_REQUIRED');
        $fingerprint = hash('sha256', $this->json(['plan' => $approvedFingerprint, 'items' => $plan['items'] ?? []]));
        if (is_callable($this->receiptReader)) {
            $existing = ($this->receiptReader)($key, $fingerprint);
            if (is_array($existing)) {
                if (($existing['fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('DICTIONARY_IDEMPOTENCY_CONFLICT');
                return (array) ($existing['result'] ?? []);
            }
        }

        $results = [];
        foreach ((array) ($plan['items'] ?? []) as $item) {
            $this->assertSafeItem($item);
            $conceptId = trim((string) ($item['concept_id'] ?? ''));
            $concept = $this->concepts->findById($conceptId);
            if (!$concept instanceof DictionaryConcept || $concept->revision !== (int) ($item['concept_revision'] ?? 0)) {
                throw new \RuntimeException('PLAN_REAPPROVAL_REQUIRED');
            }
            $existing = method_exists($this->entries, 'findDurableForConcept')
                ? $this->entries->findDurableForConcept($conceptId)
                : $this->entries->findForConcept($conceptId);
            if ($existing instanceof LexicalEntry) {
                throw new \RuntimeException('DICTIONARY_ENTRY_DUPLICATE');
            }
            $form = (array) ($item['form'] ?? []);
            $entry = new LexicalEntry(
                UuidCodec::newV7(),
                trim((string) ($form['text'] ?? $concept->preferredLabel)),
                trim((string) ($form['normalized_form'] ?? '')),
                $concept->status,
                isset($form['locale']) ? (string) $form['locale'] : 'vi-VN',
                $concept->context,
                1,
                [$concept->conceptId],
            );
            $read = $this->entries->createWithSense($entry, $concept, $concept->context);
            if (!is_array($read) || !($read['entry'] ?? null) instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            $results[] = ['concept_id' => $concept->conceptId, 'concept_revision' => $concept->revision, 'entry_id' => $entry->entryId, 'sense_id' => $concept->conceptId, 'form' => $entry->preferredForm, 'status' => 'MATERIALIZED'];
        }
        $result = ['status' => 'APPLIED', 'items' => $results, 'idempotency_key' => $key, 'plan_fingerprint' => $approvedFingerprint];
        if (is_callable($this->receiptWriter)) ($this->receiptWriter)($key, $fingerprint, $result);
        if (is_callable($this->auditWriter)) {
            foreach ($results as $item) ($this->auditWriter)(['actor' => 'dictionary-curator', 'timestamp' => gmdate('c'), 'concept_id' => $item['concept_id'], 'entry_id' => $item['entry_id'], 'sense_id' => $item['sense_id'], 'idempotency_key' => $key, 'plan_fingerprint' => $approvedFingerprint, 'read_back_status' => $item['status']]);
        }
        return $result;
    }

    private function assertSafeItem(array $item): void
    {
        if (($item['classification'] ?? '') !== 'UNMAPPED_CONCEPT' || ($item['eligibility'] ?? '') !== 'READY' || ($item['proposed_operation'] ?? '') !== 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE') {
            throw new \RuntimeException('MATERIALIZATION_REVIEW_REQUIRED');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($this->sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
