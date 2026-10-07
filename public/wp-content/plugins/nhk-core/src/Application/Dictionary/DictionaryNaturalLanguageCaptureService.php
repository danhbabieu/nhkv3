<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;

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
        if (($command['status'] ?? '') === 'SEMANTIC_ONLY') return ['status' => 'NOT_REQUESTED', 'dictionary_mutation' => false, 'semantic_track' => (array) ($command['semantic_track'] ?? [])];

        $operation = $command['operation'];
        $term = $command['term'];
        $definition = $command['definition'] ?? '';
        $context = $this->boundedContext($context);
        $entryResolution = $this->resolver->resolveEntryCreate($term, $context);

        $semanticTrack = (array) ($command['semantic_track'] ?? []);
        if ($operation === 'CREATE') {
            if ($entryResolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) {
                return $this->ready('REUSE', $term, $context, $entryResolution, ['reason' => 'EXACT_REUSE_FIRST', 'semantic_track' => $semanticTrack]);
            }
            if (!$entryResolution->canCreate()) return $this->review($operation, $term, $context, $entryResolution, null, ['semantic_track' => $semanticTrack]);
            return $this->ready($operation, $term, $context, $entryResolution, ['preferred_form' => $term, 'definition' => $definition, 'semantic_track' => $semanticTrack]);
        }

        $entryCandidates = array_values(array_filter($entryResolution->candidates, 'is_array'));
        $entryIds = array_values(array_unique(array_filter(array_map(static fn (array $candidate): string => trim((string) ($candidate['entry_id'] ?? '')), $entryCandidates))));
        if ($operation !== 'ADD_SENSE' && ($entryResolution->action !== DictionaryPreCreateResolution::REUSE_EXISTING || count($entryCandidates) !== 1)) {
            return $this->review($operation, $term, $context, $entryResolution, null, ['semantic_track' => $semanticTrack]);
        }
        if ($entryIds === []) {
            return $this->review($operation, $term, $context, $entryResolution, 'DICTIONARY_ENTRY_BINDING_UNAVAILABLE', ['semantic_track' => $semanticTrack]);
        }
        if (count($entryIds) !== 1) return $this->review($operation, $term, $context, $entryResolution, null, ['semantic_track' => $semanticTrack]);
        $candidate = $entryCandidates[0];
        $entryId = trim((string) ($candidate['entry_id'] ?? ''));
        $entryRevision = (int) ($candidate['entry_revision'] ?? 0);
        if ($entryId === '' || $entryRevision < 1) return $this->review($operation, $term, $context, $entryResolution, 'DICTIONARY_ENTRY_BINDING_UNAVAILABLE', ['semantic_track' => $semanticTrack]);

        if ($operation === 'ADD_FORM') {
            $form = (string) ($command['form'] ?? '');
            $formResolution = $this->resolver->resolveFormAddition($entryId, $form, $context);
            if ($formResolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) {
                return $this->ready('REUSE', $term, $context, $formResolution, ['form' => $form, 'entry_id' => $entryId, 'semantic_track' => $semanticTrack]);
            }
            if ($formResolution->action !== DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY) return $this->review($operation, $term, $context, $formResolution, null, ['semantic_track' => $semanticTrack]);
            return $this->ready($operation, $term, $context, $formResolution, ['entry_id' => $entryId, 'expected_revision' => $entryRevision, 'form' => $form, 'semantic_track' => $semanticTrack]);
        }

        if ($operation === 'ADD_SENSE') {
            $senseResolution = $this->resolver->resolveNewSenseAddition($entryId, $definition, $context);
            if ($senseResolution->action === DictionaryPreCreateResolution::REUSE_EXISTING) return $this->ready('REUSE', $term, $context, $senseResolution, ['entry_id' => $entryId, 'semantic_track' => $semanticTrack]);
            if ($senseResolution->action !== DictionaryPreCreateResolution::ADD_SENSE_TO_ENTRY || ($senseResolution->diagnostics['decision'] ?? '') !== 'NEW_SENSE_ALLOWED') return $this->review($operation, $term, $context, $senseResolution, null, ['semantic_track' => $semanticTrack]);
            $conceptId = trim((string) ($senseResolution->candidates[0]['sense_id'] ?? ''));
            if ($conceptId === '') return $this->review($operation, $term, $context, $senseResolution, 'DICTIONARY_SENSE_IDENTITY_UNAVAILABLE', ['semantic_track' => $semanticTrack]);
            return $this->ready($operation, $term, $context, $senseResolution, [
                'entry_id' => $entryId,
                'expected_revision' => (int) ($senseResolution->candidates[0]['entry_revision'] ?? $entryRevision),
                'concept_id' => $conceptId,
                'preferred_label' => $term,
                'definition' => $definition,
                'semantic_track' => $semanticTrack,
            ]);
        }

        $senseId = trim((string) ($candidate['sense_id'] ?? ''));
        $senseRevision = (int) ($candidate['sense_revision'] ?? 0);
        if ($senseId === '' || $senseRevision < 1) return $this->review($operation, $term, $context, $entryResolution, 'DICTIONARY_SENSE_BINDING_UNAVAILABLE', ['semantic_track' => $semanticTrack]);
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
            'enrichment_field' => (string) ($command['enrichment_field'] ?? 'definition_refinement'),
            'semantic_track' => $semanticTrack,
        ]);
    }

    /** @return array<string,mixed> */
    public function apply(array $plan, string $idempotencyKey): array
    {
        $status = strtoupper(trim((string) ($plan['status'] ?? '')));
        if ($status === 'SEMANTIC_ONLY' || $status === 'NOT_REQUESTED') return ['status' => 'NOT_REQUESTED', 'dictionary_mutation' => false, 'semantic_track' => (array) ($plan['semantic_track'] ?? [])];
        if ($status === 'REVIEW_REQUIRED') return ['status' => 'REVIEW_REQUIRED', 'dictionary_mutation' => false, 'diagnostics' => (array) ($plan['diagnostics'] ?? [])];
        if ($status !== 'READY') return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => ['DICTIONARY_OWNER_PLAN_INVALID']];
        if (strtoupper(trim((string) ($plan['operation'] ?? ''))) === 'REUSE') return ['status' => 'REUSED', 'dictionary_mutation' => false, 'canonical_readback' => $plan['pre_create_resolution']['candidates'][0] ?? []];
        if (!is_callable($this->mutation)) return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => ['DICTIONARY_OWNER_MUTATION_UNAVAILABLE']];
        $result = ($this->mutation)($plan, trim($idempotencyKey));
        if (!is_array($result)) throw new \RuntimeException('DICTIONARY_OWNER_READBACK_UNAVAILABLE');
        if (in_array(strtoupper(trim((string) ($result['status'] ?? ''))), ['REVIEW_REQUIRED', 'UNAVAILABLE'], true)) return $result + ['dictionary_mutation' => false];
        return ['status' => 'APPLIED', 'dictionary_mutation' => true, 'canonical_readback' => $this->readback($result)];
    }

    /** @return array<string,mixed> */
    private function ready(string $operation, string $term, array $context, DictionaryPreCreateResolution $resolution, array $extra = []): array
    {
        return ['status' => 'READY', 'operation' => $operation, 'term' => $term, 'context' => $context, 'pre_create_resolution' => $resolution->toArray()] + $extra;
    }

    /** @return array<string,mixed> */
    private function review(string $operation, string $term, array $context, DictionaryPreCreateResolution $resolution, ?string $reason = null, array $extra = []): array
    {
        $plan = ['status' => 'REVIEW_REQUIRED', 'operation' => $operation, 'term' => $term, 'context' => $context, 'pre_create_resolution' => $resolution->toArray()];
        if ($reason !== null) $plan['diagnostics'] = [$reason];
        return $plan + $extra;
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
