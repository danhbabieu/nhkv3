<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\DictionaryConcept;
use NHK\Core\Domain\Dictionary\DictionaryLabel;
use NHK\Core\Domain\Dictionary\{DictionaryPreCreateResolution, LexicalEntry, LexicalEntryForm};
use NHK\Core\Shared\Uuid\UuidCodec;

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
        private $entryRepository = null,
        private $knowledgeValidator = null,
        private $entrySenseReady = null,
        private $entryPublicIdentityWriter = null,
        private $cacheInvalidator = null,
        private ?DictionaryPreCreateResolver $preCreateResolver = null,
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

    /** Append a bounded lexical note without replacing the existing definition. */
    public function enrichConcept(string $conceptId, int $expectedRevision, string $definitionDelta, array $context, string $idempotencyKey): array
    {
        $current = $this->requireConcept($conceptId);
        $definitionDelta = trim($definitionDelta);
        if ($definitionDelta === '') throw new \InvalidArgumentException('DICTIONARY_ENRICHMENT_CONTENT_REQUIRED');
        $definition = trim($current->definition);
        if ($definition === '') $definition = $definitionDelta;
        elseif ($definition !== $definitionDelta && !str_contains($definition, $definitionDelta)) $definition .= "\n\n" . $definitionDelta;
        return $this->updateConcept($conceptId, $expectedRevision, $current->preferredLabel, $definition, array_merge($current->context, $context), $idempotencyKey);
    }

    public function createDraft(string $preferredLabel, string $definition, array $context, string $idempotencyKey): array
    {
        if (!$this->preCreateResolver instanceof DictionaryPreCreateResolver) throw new \RuntimeException('PRE_CREATE_RESOLUTION_REQUIRED');
        $payload = ['operation' => 'create', 'preferred_label' => trim($preferredLabel), 'definition' => trim($definition), 'context' => $this->sort($context)];
        $operation = function (?DictionaryPreCreateResolution $resolution = null) use ($preferredLabel, $definition, $context): array {
            if (!$resolution instanceof DictionaryPreCreateResolution || !$resolution->canCreate()) {
                if ($resolution?->action === DictionaryPreCreateResolution::REUSE_EXISTING) return $this->reuseExistingResolution($resolution);
                throw new \RuntimeException('PRE_CREATE_RESOLUTION_REQUIRED');
            }
            $concept = $this->concepts->createConcept(new DictionaryConcept(UuidCodec::newV7(), trim($preferredLabel), trim($definition), DictionaryConcept::DRAFT, null, null, null, $context, 1));
            $label = $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $concept->preferredLabel, (new DictionaryTermNormalizer())->normalize($concept->preferredLabel), DictionaryLabel::PREFERRED, 'vi-VN', $context));
            return ['concept' => $concept, 'label' => $label, 'resolution' => $resolution->toArray()];
        };
        return $this->mutateResolved($idempotencyKey, $payload, fn (): DictionaryPreCreateResolution => $this->preCreateResolver->resolveEntryCreate($preferredLabel, $context), $operation);
    }

    public function saveLabel(string $conceptId, int $expectedRevision, string $previousNormalizedLabel, DictionaryLabel $label, string $idempotencyKey): array
    {
        $current = $this->requireConcept($conceptId);
        $payload = ['operation' => 'label_save', 'concept_id' => $conceptId, 'expected_revision' => $expectedRevision, 'previous_normalized_label' => $previousNormalizedLabel, 'label' => $label->label, 'normalized_label' => $label->normalizedLabel, 'kind' => $label->kind, 'active' => $label->active];
        return $this->mutate($idempotencyKey, $payload, function () use ($current, $expectedRevision, $previousNormalizedLabel, $label): array {
            if (trim($previousNormalizedLabel) !== '') $saved = $this->concepts->saveLabel($label, $previousNormalizedLabel, $expectedRevision);
            else {
                $saved = $this->concepts->addLabel($label);
                $this->concepts->updateConcept(new DictionaryConcept($current->conceptId, $current->preferredLabel, $current->definition, $current->status, $current->destinationType, $current->destinationId, $current->destinationUrl, $current->context, $current->revision), $expectedRevision);
            }
            $result = ['label' => $saved, 'concept' => $this->concepts->findById($current->conceptId)];
            if ($label->kind === DictionaryLabel::PREFERRED && $label->active && is_object($this->entryRepository) && method_exists($this->entryRepository, 'ensurePublicIdentityForSense')) {
                $entry = $this->entryRepository->ensurePublicIdentityForSense($current->conceptId);
                if ($entry instanceof LexicalEntry) $result['entry'] = $entry;
            }
            return $result;
        });
    }

    public function setConceptStatus(string $conceptId, int $expectedRevision, string $status, string $idempotencyKey): array
    {
        if (!in_array($status, [DictionaryConcept::APPROVED, DictionaryConcept::RETIRED, DictionaryConcept::DRAFT], true)) throw new \InvalidArgumentException('DICTIONARY_STATUS_INVALID');
        $current = $this->requireConcept($conceptId);
        $payload = ['operation' => 'set_status', 'concept_id' => $conceptId, 'expected_revision' => $expectedRevision, 'status' => $status];
        return $this->mutate($idempotencyKey, $payload, function () use ($current, $expectedRevision, $status): array {
            $updated = new DictionaryConcept($current->conceptId, $current->preferredLabel, $current->definition, $status, $current->destinationType, $current->destinationId, $current->destinationUrl, $current->context, $current->revision);
            $concept = $this->concepts->updateConcept($updated, $expectedRevision);
            $result = ['concept' => $concept];
            if (is_object($this->entryRepository) && method_exists($this->entryRepository, 'syncStatusForSense')) {
                $entry = $this->entryRepository->syncStatusForSense($concept->conceptId, $status);
                if ($entry instanceof LexicalEntry) $result['entry'] = $entry;
            }
            return $result;
        });
    }

    /** Execute a Dictionary-owned curated operation with the same receipt law. */
    public function idempotent(string $operation, array $payload, string $idempotencyKey, callable $callback): array
    {
        $payload['operation'] = $operation;
        return $this->mutate($idempotencyKey, $payload, $callback);
    }

    public function createEntryWithSense(string $preferredForm, string $definition, array $context, string $idempotencyKey, ?string $locale = 'vi-VN'): array
    {
        $this->assertEntrySenseReady();
        $preferredForm = trim($preferredForm);
        if ($preferredForm === '' || trim($definition) === '') throw new \InvalidArgumentException('DICTIONARY_ENTRY_SENSE_CONTENT_REQUIRED');
        if (!is_object($this->entryRepository) || !method_exists($this->entryRepository, 'createWithSense')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        $normalizer = new DictionaryTermNormalizer();
        $normalized = $normalizer->normalize($preferredForm);
        $payload = ['preferred_form' => $preferredForm, 'definition' => trim($definition), 'context' => $this->sort($context), 'locale' => $locale];
        $operation = function (?DictionaryPreCreateResolution $resolution = null) use ($preferredForm, $normalized, $definition, $context, $locale): array {
            if ($resolution instanceof DictionaryPreCreateResolution && $resolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) return $this->reuseExistingResolution($resolution);
            if ($resolution instanceof DictionaryPreCreateResolution && !$resolution->canCreate()) throw new \RuntimeException('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
            $sense = new DictionaryConcept(UuidCodec::newV7(), $preferredForm, trim($definition), DictionaryConcept::DRAFT, null, null, null, $context, 1);
            $entry = new LexicalEntry(UuidCodec::newV7(), $preferredForm, $normalized, DictionaryConcept::DRAFT, $locale, $context, 1, [$sense->conceptId]);
            if ($this->entryPublicIdentityWriter instanceof DictionaryEntryPublicIdentityWriter) $entry = $this->entryPublicIdentityWriter->assign($entry);
            $result = $resolution instanceof DictionaryPreCreateResolution && method_exists($this->entryRepository, 'createWithSenseResolved')
                ? ($this->entryRepository)->createWithSenseResolved($entry, $sense, $context, $resolution)
                : ($this->entryRepository)->createWithSense($entry, $sense, $context);
            if (!is_array($result) || !($result['entry'] ?? null) instanceof LexicalEntry || !($result['sense'] ?? null) instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            return $result;
        };
        if (!$this->preCreateResolver instanceof DictionaryPreCreateResolver) throw new \RuntimeException('PRE_CREATE_RESOLUTION_REQUIRED');
        return $this->mutateResolved($idempotencyKey, ['operation' => 'entry.create-with-sense'] + $payload, fn (): DictionaryPreCreateResolution => $this->preCreateResolver->resolveEntryCreate($preferredForm, $context), $operation);
    }

    public function addFormToEntry(string $entryId, int $expectedRevision, string $form, array $context, string $idempotencyKey, string $kind = LexicalEntryForm::ALTERNATE, ?string $locale = 'vi-VN'): array
    {
        $this->assertEntrySenseReady();
        $form = trim($form);
        if (!in_array($kind, [LexicalEntryForm::PREFERRED, LexicalEntryForm::ALTERNATE, LexicalEntryForm::COLLOQUIAL, LexicalEntryForm::TECHNICAL, LexicalEntryForm::PHONETIC], true)) throw new \InvalidArgumentException('DICTIONARY_ENTRY_FORM_KIND_INVALID');
        $normalized = (new DictionaryTermNormalizer())->normalize($form);
        if ($form === '' || $normalized === '') throw new \InvalidArgumentException('DICTIONARY_ENTRY_FORM_REQUIRED');
        if (!is_object($this->entryRepository) || !method_exists($this->entryRepository, 'addFormToEntry')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        $payload = ['operation' => 'entry.form.add', 'entry_id' => $entryId, 'expected_revision' => $expectedRevision, 'form' => $form, 'normalized_form' => $normalized, 'context' => $this->sort($context), 'kind' => $kind, 'locale' => $locale];
        $operation = function (?DictionaryPreCreateResolution $resolution = null) use ($entryId, $expectedRevision, $form, $normalized, $context, $kind, $locale): array {
            $entry = method_exists($this->entryRepository, 'findById') ? $this->entryRepository->findById($entryId) : null;
            if (!$entry instanceof LexicalEntry) throw new \RuntimeException($resolution instanceof DictionaryPreCreateResolution ? 'DICTIONARY_ENTRY_NOT_FOUND' : 'DICTIONARY_ENTRY_FORM_COLLISION');
            if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            if ($resolution instanceof DictionaryPreCreateResolution && $resolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) return ['entry' => $entry, 'form' => new LexicalEntryForm($entryId, $form, $normalized, $kind, $locale, $context), 'duplicate' => true];
            if ($resolution instanceof DictionaryPreCreateResolution && $resolution->action !== DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY) throw new \RuntimeException('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
            if ($normalized === $entry->normalizedPreferredForm) throw new \RuntimeException('DICTIONARY_ENTRY_FORM_COLLISION');
            $saved = ($this->entryRepository)->addFormToEntry($entryId, $expectedRevision, new LexicalEntryForm($entryId, $form, $normalized, $kind, $locale, $context));
            if (!is_array($saved) || !($saved['entry'] ?? null) instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            return $saved;
        };
        if (!$this->preCreateResolver instanceof DictionaryPreCreateResolver) throw new \RuntimeException('PRE_CREATE_RESOLUTION_REQUIRED');
        return $this->mutateResolved($idempotencyKey, $payload, fn (): DictionaryPreCreateResolution => $this->preCreateResolver->resolveFormAddition($entryId, $form, $context), $operation);
    }

    public function addSenseToEntry(string $entryId, int $expectedRevision, string $conceptId, array $context, string $idempotencyKey, ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): array
    {
        $this->assertEntrySenseReady();
        if (!is_object($this->entryRepository) || !method_exists($this->entryRepository, 'addSenseToEntry')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        $sense = $this->requireConcept($conceptId);
        $semanticType ??= $sense->destinationType;
        $semanticId ??= $sense->destinationId;
        if ($semanticType !== null && $semanticId !== null && is_callable($this->knowledgeValidator) && ($this->knowledgeValidator)($semanticType, $semanticId, $sense->destinationUrl) === false) throw new \RuntimeException('DICTIONARY_SEMANTIC_REFERENCE_INVALID');
        $payload = ['operation' => 'entry.sense.add', 'entry_id' => $entryId, 'expected_revision' => $expectedRevision, 'concept_id' => $conceptId, 'context' => $this->sort($context), 'semantic_type' => $semanticType, 'semantic_id' => $semanticId, 'semantic_revision' => $semanticRevision];
        $operation = function (?DictionaryPreCreateResolution $resolution = null) use ($entryId, $expectedRevision, $sense, $context, $semanticType, $semanticId, $semanticRevision): array {
            $entry = method_exists($this->entryRepository, 'findById') ? $this->entryRepository->findById($entryId) : null;
            if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
            if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            if ($resolution instanceof DictionaryPreCreateResolution && $resolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) return ['entry' => $entry, 'sense' => $sense, 'duplicate' => true];
            if ($resolution instanceof DictionaryPreCreateResolution && $resolution->action !== DictionaryPreCreateResolution::ADD_SENSE_TO_ENTRY) throw new \RuntimeException('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
            return ($this->entryRepository)->addSenseToEntry($entryId, $expectedRevision, $sense, $context, $semanticType, $semanticId, $semanticRevision);
        };
        if (!$this->preCreateResolver instanceof DictionaryPreCreateResolver) throw new \RuntimeException('PRE_CREATE_RESOLUTION_REQUIRED');
        return $this->mutateResolved($idempotencyKey, $payload, fn (): DictionaryPreCreateResolution => $this->preCreateResolver->resolveSenseAddition($entryId, $conceptId, $context), $operation);
    }

    /** Create one new lexical Sense and attach it atomically to an existing Entry. */
    public function addNewSenseToEntry(string $entryId, int $expectedRevision, string $conceptId, string $preferredLabel, string $definition, array $context, string $idempotencyKey, ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): array
    {
        $this->assertEntrySenseReady();
        if (!is_object($this->entryRepository) || !method_exists($this->entryRepository, 'addSenseToEntry')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        $conceptId = trim($conceptId);
        $preferredLabel = trim($preferredLabel);
        $definition = trim($definition);
        if ($conceptId === '' || $preferredLabel === '' || $definition === '') throw new \InvalidArgumentException('DICTIONARY_NEW_SENSE_CONTENT_REQUIRED');
        $payload = ['operation' => 'entry.sense.create-and-add', 'entry_id' => $entryId, 'expected_revision' => $expectedRevision, 'concept_id' => $conceptId, 'preferred_label' => $preferredLabel, 'definition' => $definition, 'context' => $this->sort($context), 'semantic_type' => $semanticType, 'semantic_id' => $semanticId, 'semantic_revision' => $semanticRevision];
        return $this->mutate($idempotencyKey, $payload, function () use ($entryId, $expectedRevision, $conceptId, $preferredLabel, $definition, $context, $semanticType, $semanticId, $semanticRevision): array {
            $sense = $this->concepts->findById($conceptId) ?? new DictionaryConcept($conceptId, $preferredLabel, $definition, DictionaryConcept::DRAFT, null, null, null, $context, 1);
            $result = ($this->entryRepository)->addSenseToEntry($entryId, $expectedRevision, $sense, $context, $semanticType, $semanticId, $semanticRevision);
            if (!is_array($result) || !($result['entry'] ?? null) instanceof LexicalEntry || !($result['sense'] ?? null) instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_READBACK_FAILED');
            return $result;
        });
    }

    public function setSenseSemanticReference(string $entryId, string $senseId, int $expectedEntryRevision, string $semanticType, string $semanticId, ?int $semanticRevision, string $idempotencyKey): array
    {
        $this->assertEntrySenseReady();
        if (!is_object($this->entryRepository) || !method_exists($this->entryRepository, 'setSenseSemanticReference')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        $semanticType = trim($semanticType);
        $semanticId = trim($semanticId);
        if ($semanticType === '' || $semanticId === '') throw new \InvalidArgumentException('DICTIONARY_SEMANTIC_REFERENCE_REQUIRED');
        if (is_callable($this->knowledgeValidator) && ($this->knowledgeValidator)($semanticType, $semanticId, null) === false) throw new \RuntimeException('DICTIONARY_SEMANTIC_REFERENCE_INVALID');
        $payload = [
            'operation' => 'dictionary.entry-sense.semantic-reference.set',
            'entry_id' => $entryId,
            'sense_id' => $senseId,
            'expected_entry_revision' => $expectedEntryRevision,
            'semantic_type' => $semanticType,
            'semantic_id' => $semanticId,
            'semantic_revision' => $semanticRevision,
        ];
        return $this->mutate($idempotencyKey, $payload, function () use ($entryId, $senseId, $expectedEntryRevision, $semanticType, $semanticId, $semanticRevision): array {
            $result = ($this->entryRepository)->setSenseSemanticReference($entryId, $senseId, $expectedEntryRevision, $semanticType, $semanticId, $semanticRevision);
            if (!is_array($result)) throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_READBACK_FAILED');
            return $result;
        });
    }

    private function reuseExistingResolution(DictionaryPreCreateResolution $resolution): array
    {
        $candidate = $resolution->candidates[0] ?? [];
        $entryId = trim((string) ($candidate['entry_id'] ?? ''));
        $senseId = trim((string) ($candidate['sense_id'] ?? ''));
        $entry = $entryId !== '' && method_exists($this->entryRepository, 'findById') ? $this->entryRepository->findById($entryId) : null;
        $sense = $senseId !== '' ? $this->concepts->findById($senseId) : null;
        if (!$entry instanceof LexicalEntry || !$sense instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_PRE_CREATE_READBACK_FAILED');
        $forms = method_exists($this->entryRepository, 'listForms') ? (array) $this->entryRepository->listForms($entry) : [];
        return ['entry' => $entry, 'sense' => $sense, 'forms' => $forms, 'duplicate' => true];
    }

    private function mutateResolved(string $idempotencyKey, array $payload, callable $resolve, callable $operation): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') throw new \InvalidArgumentException('DICTIONARY_IDEMPOTENCY_KEY_REQUIRED');
        $requestFingerprint = $this->payloadFingerprint($payload);
        $existing = $this->readReceipt($key);
        if (is_array($existing)) {
            $result = (array) ($existing['result'] ?? []);
            $storedRequestFingerprint = (string) ($result['_request_fingerprint'] ?? '');
            if ($storedRequestFingerprint !== '' && $storedRequestFingerprint !== $requestFingerprint) throw new \RuntimeException('DICTIONARY_IDEMPOTENCY_CONFLICT');
            if ($storedRequestFingerprint === '' && isset($existing['fingerprint']) && $existing['fingerprint'] !== $requestFingerprint) throw new \RuntimeException('DICTIONARY_IDEMPOTENCY_CONFLICT');
            unset($result['_request_fingerprint']);
            return $result;
        }

        $resolution = $resolve();
        if (!$resolution instanceof DictionaryPreCreateResolution) throw new \RuntimeException('DICTIONARY_PRE_CREATE_RESOLUTION_UNAVAILABLE');
        $resolvedPayload = $payload + [
            'resolution_fingerprint' => $resolution->fingerprint(),
            'resolution_action' => $resolution->action,
            'dependency_revisions' => $resolution->dependencyRevisions,
        ];
        $result = $this->mutate($key, $resolvedPayload, function () use ($operation, $resolution, $requestFingerprint): array {
            return $operation($resolution) + ['resolution' => $resolution->toArray(), '_request_fingerprint' => $requestFingerprint];
        });
        unset($result['_request_fingerprint']);
        return $result;
    }

    private function readReceipt(string $key): ?array
    {
        if (!is_callable($this->receiptReader)) return null;
        $existing = ($this->receiptReader)($key, '');
        return is_array($existing) ? $existing : null;
    }

    private function payloadFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function requireConcept(string $conceptId): DictionaryConcept
    {
        $concept = $this->concepts->findById($conceptId);
        if (!$concept instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_CONCEPT_NOT_FOUND');
        return $concept;
    }

    private function assertEntrySenseReady(): void
    {
        if (is_callable($this->entrySenseReady) && !(bool) ($this->entrySenseReady)()) {
            throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE');
        }
    }

    private function mutate(string $idempotencyKey, array $payload, callable $operation): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') throw new \InvalidArgumentException('DICTIONARY_IDEMPOTENCY_KEY_REQUIRED');
        $fingerprint = $this->payloadFingerprint($payload);
        if (is_callable($this->receiptReader)) {
            $existing = ($this->receiptReader)($key, $fingerprint);
            if (is_array($existing)) {
                if (($existing['fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('DICTIONARY_IDEMPOTENCY_CONFLICT');
                return (array) ($existing['result'] ?? []);
            }
        }
        $result = $operation();
        if (is_callable($this->receiptWriter)) ($this->receiptWriter)($key, $fingerprint, $result + ['actor_user_id' => is_callable($this->actor) ? ($this->actor)() : null]);
        if (is_callable($this->cacheInvalidator)) ($this->cacheInvalidator)();
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
