<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Derives a bounded Dictionary owner plan from natural Capture wording.
 *
 * This class is deliberately a planner: it never treats a sentence as a
 * canonical identity, never resolves by similarity, and never copies a
 * factual statement into a lexical definition. Mutation is a later step at
 * the Dictionary-owned service boundary.
 */
final class DictionaryNaturalLanguageCaptureService
{
    private StructuredSemanticInterpreter $interpreter;

    public function __construct(private DictionaryPreCreateResolver $resolver, private $mutation = null, ?StructuredSemanticInterpreter $interpreter = null)
    {
        $this->interpreter = $interpreter ?? new StructuredSemanticInterpreter();
    }

    /** @return array<string,mixed> */
    public function plan(string $text, string $sourceId, array $context = [], ?array $sharedCommand = null): array
    {
        $text = trim($text);
        $sourceId = trim($sourceId);
        if ($text === '' || $sourceId === '') return ['status' => 'NOT_REQUESTED', 'diagnostics' => ['DICTIONARY_NATURAL_INPUT_REQUIRED']];

        $command = $sharedCommand ?? $this->interpreter->dictionaryOwnerCommand($text);
        if ($command === null) return ['status' => 'NOT_REQUESTED', 'dictionary_mutation' => false];
        if (($command['status'] ?? '') === 'SEMANTIC_ONLY') return $command;

        $operation = $command['operation'];
        $term = $command['term'];
        $definition = $command['definition'] ?? '';
        $context = $this->boundedContext($context);
        $entryResolution = $this->resolver->resolveEntryCreate($term, $context);

        if ($operation === 'CREATE') {
            if ($entryResolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) {
                return $this->ready('REUSE', $term, $context, $entryResolution, ['reason' => 'EXACT_REUSE_FIRST']);
            }
            if (!$entryResolution->canCreate()) return $this->review($operation, $term, $context, $entryResolution);
            return $this->ready($operation, $term, $context, $entryResolution, ['preferred_form' => $term, 'definition' => $definition]);
        }

        if ($entryResolution->action !== DictionaryPreCreateResolution::REUSE_EXISTING || count($entryResolution->candidates) !== 1) {
            return $this->review($operation, $term, $context, $entryResolution);
        }
        $candidate = $entryResolution->candidates[0];
        $entryId = trim((string) ($candidate['entry_id'] ?? ''));
        $entryRevision = (int) ($candidate['entry_revision'] ?? 0);
        if ($entryId === '' || $entryRevision < 1) return $this->review($operation, $term, $context, $entryResolution, 'DICTIONARY_ENTRY_BINDING_UNAVAILABLE');

        if ($operation === 'ADD_FORM') {
            $form = (string) ($command['form'] ?? '');
            $formResolution = $this->resolver->resolveFormAddition($entryId, $form, $context);
            if ($formResolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) {
                return $this->ready('REUSE', $term, $context, $formResolution, ['form' => $form, 'entry_id' => $entryId]);
            }
            if ($formResolution->action !== DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY) return $this->review($operation, $term, $context, $formResolution);
            return $this->ready($operation, $term, $context, $formResolution, ['entry_id' => $entryId, 'expected_revision' => $entryRevision, 'form' => $form]);
        }

        if ($operation === 'ADD_SENSE') {
            $conceptId = UuidCodec::v5('nhk.capture.dictionary.sense|' . $sourceId . '|' . $entryId . '|' . $this->normalize($definition));
            $senseResolution = DictionaryPreCreateResolution::fromDecision(
                DictionaryPreCreateResolution::ADD_SENSE_TO_ENTRY,
                $entryResolution->normalizedForm,
                $context,
                [['entry_id' => $entryId, 'sense_id' => $conceptId, 'entry_revision' => $entryRevision]],
                ['entry:' . $entryId => $entryRevision],
                ['reason' => 'NEW_NATURAL_SENSE', 'source' => 'CAPTURE_NATURAL_LANGUAGE'],
            );
            return $this->ready($operation, $term, $context, $senseResolution, [
                'entry_id' => $entryId,
                'expected_revision' => $entryRevision,
                'concept_id' => $conceptId,
                'preferred_label' => $term,
                'definition' => $definition,
            ]);
        }

        $senseId = trim((string) ($candidate['sense_id'] ?? ''));
        $senseRevision = (int) ($candidate['sense_revision'] ?? 0);
        if ($senseId === '' || $senseRevision < 1) return $this->review($operation, $term, $context, $entryResolution, 'DICTIONARY_SENSE_BINDING_UNAVAILABLE');
        $enrichmentResolution = DictionaryPreCreateResolution::fromDecision(
            DictionaryPreCreateResolution::ENRICH_EXISTING,
            $entryResolution->normalizedForm,
            $context,
            [$candidate],
            $entryResolution->dependencyRevisions,
            ['reason' => 'EXACT_SENSE_REUSE', 'source' => 'CAPTURE_NATURAL_LANGUAGE'],
        );
        return $this->ready('ENRICH', $term, $context, $enrichmentResolution, [
            'entry_id' => $entryId,
            'sense_id' => $senseId,
            'expected_revision' => $senseRevision,
            'preferred_label' => $term,
            'definition' => $definition,
        ]);
    }

    /** @return array<string,mixed> */
    public function apply(array $plan, string $idempotencyKey): array
    {
        $status = strtoupper(trim((string) ($plan['status'] ?? '')));
        if ($status === 'SEMANTIC_ONLY' || $status === 'NOT_REQUESTED') return ['status' => 'NOT_REQUESTED', 'dictionary_mutation' => false];
        if ($status === 'REVIEW_REQUIRED') return ['status' => 'REVIEW_REQUIRED', 'dictionary_mutation' => false, 'diagnostics' => (array) ($plan['diagnostics'] ?? [])];
        if ($status !== 'READY') return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => ['DICTIONARY_OWNER_PLAN_INVALID']];
        if (strtoupper(trim((string) ($plan['operation'] ?? ''))) === 'REUSE') return ['status' => 'REUSED', 'dictionary_mutation' => false, 'canonical_readback' => $plan['pre_create_resolution']['candidates'][0] ?? []];
        if (!is_callable($this->mutation)) return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => ['DICTIONARY_OWNER_MUTATION_UNAVAILABLE']];
        $result = ($this->mutation)($plan, trim($idempotencyKey));
        if (!is_array($result)) throw new \RuntimeException('DICTIONARY_OWNER_READBACK_UNAVAILABLE');
        return ['status' => 'APPLIED', 'dictionary_mutation' => true, 'canonical_readback' => $this->readback($result)];
    }

    /** @return array<string,mixed> */
    private function ready(string $operation, string $term, array $context, DictionaryPreCreateResolution $resolution, array $extra = []): array
    {
        return ['status' => 'READY', 'operation' => $operation, 'term' => $term, 'context' => $context, 'pre_create_resolution' => $resolution->toArray()] + $extra;
    }

    /** @return array<string,mixed> */
    private function review(string $operation, string $term, array $context, DictionaryPreCreateResolution $resolution, ?string $reason = null): array
    {
        $plan = ['status' => 'REVIEW_REQUIRED', 'operation' => $operation, 'term' => $term, 'context' => $context, 'pre_create_resolution' => $resolution->toArray()];
        if ($reason !== null) $plan['diagnostics'] = [$reason];
        return $plan;
    }

    /** @return array<string,mixed> */
    private function boundedContext(array $context): array
    {
        $allowed = ['locale', 'lexical_locale', 'domain', 'usage_scope', 'region', 'community', 'scope', 'term_type'];
        $out = [];
        foreach ($allowed as $key) if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null) $out[$key] = $context[$key];
        ksort($out, SORT_STRING);
        return $out;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function readback(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) $out[$key] = $this->readback($item);
            return $out;
        }
        if (!is_object($value)) return $value;
        $fields = get_object_vars($value);
        if (isset($fields['entryId'])) return ['entry_id' => $fields['entryId'], 'preferred_form' => $fields['preferredForm'] ?? null, 'revision' => $fields['revision'] ?? null, 'sense_ids' => $fields['senseIds'] ?? []];
        if (isset($fields['conceptId'])) return ['sense_id' => $fields['conceptId'], 'preferred_label' => $fields['preferredLabel'] ?? null, 'definition' => $fields['definition'] ?? null, 'status' => $fields['status'] ?? null, 'revision' => $fields['revision'] ?? null, 'context' => $fields['context'] ?? [], 'destination_type' => $fields['destinationType'] ?? null, 'destination_id' => $fields['destinationId'] ?? null];
        if (isset($fields['form'])) return ['entry_id' => $fields['entryId'] ?? null, 'form' => $fields['form'], 'normalized_form' => $fields['normalizedForm'] ?? null, 'kind' => $fields['kind'] ?? null, 'locale' => $fields['locale'] ?? null, 'context' => $fields['context'] ?? []];
        return $fields;
    }
}
