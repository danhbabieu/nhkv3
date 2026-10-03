<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry};

/**
 * Read-only inventory/planning boundary for the additive Entry/Sense model.
 * It deliberately knows nothing about persistence writes or semantic merging.
 */
final class DictionaryEntryMaterializationPlanner
{
    private DictionaryTermNormalizer $normalizer;

    /**
     * @param callable(string):?LexicalEntry $entryForConcept
     * @param callable(string):list<DictionaryLabel> $labelsForConcept
     * @param callable(string,string,string):bool|null $destinationValidator
     * @param callable():bool|null $entrySenseReady
     */
    public function __construct(
        private DictionaryConceptRepository $concepts,
        private $entryForConcept,
        private $labelsForConcept,
        private $destinationValidator = null,
        ?DictionaryTermNormalizer $normalizer = null,
        private $entrySenseReady = null,
    ) {
        $this->normalizer = $normalizer ?? new DictionaryTermNormalizer();
    }

    /**
     * @param array{concept_id?:string,concept_ids?:list<string>,limit?:int} $input
     */
    public function plan(array $input = []): array
    {
        if (is_callable($this->entrySenseReady) && !(bool) ($this->entrySenseReady)()) {
            return ['status' => 'UNAVAILABLE', 'reason' => 'DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE', 'items' => [], 'grouping_candidates' => [], 'count' => 0];
        }
        $concepts = $this->selectConcepts($input);
        $items = [];
        foreach ($concepts as $concept) {
            $items[] = $this->item($concept);
        }

        $groups = [];
        foreach ($items as $item) {
            $key = (string) ($item['form']['normalized_form'] ?? '');
            if ($key !== '') $groups[$key][] = $item;
        }
        $groupingCandidates = [];
        foreach ($groups as $normalized => $group) {
            if (count($group) < 2) continue;
            $groupingCandidates[] = [
                'normalized_form' => $normalized,
                'concept_ids' => array_values(array_map(static fn (array $item): string => $item['concept_id'], $group)),
                'definitions' => array_values(array_map(static fn (array $item): string => $item['definition'], $group)),
                'contexts' => array_values(array_map(static fn (array $item): array => $item['context'], $group)),
                'eligibility' => 'REVIEW_REQUIRED',
                'proposed_operation' => 'GROUP_SENSES_UNDER_ENTRY',
                'reason' => 'NORMALIZED_FORM_COLLISION_REQUIRES_CURATOR_REVIEW',
            ];
        }
        usort($groupingCandidates, static fn (array $a, array $b): int => $a['normalized_form'] <=> $b['normalized_form']);

        $payload = ['items' => $items, 'grouping_candidates' => $groupingCandidates];
        $payload['fingerprint'] = hash('sha256', $this->json($payload));
        return ['status' => 'READY', ...$payload, 'count' => count($items)];
    }

    /** @return list<DictionaryConcept> */
    private function selectConcepts(array $input): array
    {
        $ids = [];
        foreach ((array) ($input['concept_ids'] ?? []) as $id) {
            $id = trim((string) $id);
            if ($id !== '') $ids[$id] = true;
        }
        $single = trim((string) ($input['concept_id'] ?? ''));
        if ($single !== '') $ids[$single] = true;

        if ($ids !== []) {
            $out = [];
            foreach (array_keys($ids) as $id) {
                $concept = $this->concepts->findById($id);
                if ($concept instanceof DictionaryConcept) $out[] = $concept;
            }
            usort($out, static fn (DictionaryConcept $a, DictionaryConcept $b): int => $a->conceptId <=> $b->conceptId);
            return $out;
        }

        $limit = max(1, min(2000, (int) ($input['limit'] ?? 500)));
        if (method_exists($this->concepts, 'listByStatus')) {
            $all = [];
            foreach ([DictionaryConcept::DRAFT, DictionaryConcept::APPROVED, DictionaryConcept::RETIRED] as $status) {
                foreach ((array) $this->concepts->listByStatus($status, $limit) as $concept) {
                    if ($concept instanceof DictionaryConcept) $all[$concept->conceptId] = $concept;
                }
            }
            $out = array_values($all);
        } else {
            $out = array_values(array_filter($this->concepts->listApproved($limit), static fn (mixed $concept): bool => $concept instanceof DictionaryConcept));
        }
        usort($out, static fn (DictionaryConcept $a, DictionaryConcept $b): int => $a->conceptId <=> $b->conceptId);
        return array_slice($out, 0, $limit);
    }

    /** @return array<string,mixed> */
    private function item(DictionaryConcept $concept): array
    {
        $labels = array_values(array_filter(($this->labelsForConcept)($concept->conceptId), static fn (mixed $label): bool => $label instanceof DictionaryLabel && $label->active));
        $preferredLabels = array_values(array_filter($labels, static fn (DictionaryLabel $label): bool => $label->kind === DictionaryLabel::PREFERRED));
        $preferred = $preferredLabels[0] ?? null;
        $display = $preferred instanceof DictionaryLabel ? $preferred->label : $concept->preferredLabel;
        $normalized = $this->normalizer->normalize($display);
        $entry = ($this->entryForConcept)($concept->conceptId);

        $warnings = [];
        if (!$preferred instanceof DictionaryLabel) $warnings[] = 'PREFERRED_LABEL_MISSING';
        if ($preferred instanceof DictionaryLabel && $this->normalizer->normalize($preferred->label) !== $this->normalizer->normalize($concept->preferredLabel)) $warnings[] = 'PREFERRED_LABEL_DIVERGED';
        $invalidDestination = $this->invalidDestination($concept);
        if ($invalidDestination) $warnings[] = 'DESTINATION_INVALID';

        if ($concept->status === DictionaryConcept::RETIRED) {
            $classification = 'RETIRED';
            $eligibility = 'BLOCKED';
            $operation = 'NO_OP';
        } elseif ($entry instanceof LexicalEntry && in_array($concept->conceptId, $entry->senseIds, true)) {
            $classification = 'ALREADY_MAPPED';
            $eligibility = $warnings === [] ? 'READY' : 'REVIEW_REQUIRED';
            $operation = 'REUSE_EXISTING_MAPPING';
        } elseif ($entry instanceof LexicalEntry) {
            $classification = 'MAPPING_INCONSISTENT';
            $eligibility = 'BLOCKED';
            $operation = 'NO_OP';
            $warnings[] = 'ENTRY_FOUND_WITHOUT_CONCEPT_SENSE_MAPPING';
        } else {
            $classification = 'UNMAPPED_CONCEPT';
            $eligibility = $invalidDestination ? 'REVIEW_REQUIRED' : ($warnings === [] ? 'READY' : 'REVIEW_REQUIRED');
            $operation = $eligibility === 'BLOCKED' ? 'NO_OP' : 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE';
        }

        return [
            'concept_id' => $concept->conceptId,
            'concept_revision' => $concept->revision,
            'preferred_label' => $concept->preferredLabel,
            'definition' => $concept->definition,
            'status' => $concept->status,
            'context' => $concept->context,
            'destination' => ['type' => $concept->destinationType, 'id' => $concept->destinationId, 'url' => $concept->destinationUrl],
            'classification' => $classification,
            'eligibility' => $eligibility,
            'proposed_operation' => $operation,
            'existing_entry' => $entry instanceof LexicalEntry ? ['entry_id' => $entry->entryId, 'revision' => $entry->revision, 'sense_ids' => $entry->senseIds] : null,
            'form' => ['text' => $display, 'normalized_form' => $normalized, 'kind' => 'PREFERRED', 'locale' => $preferred?->locale ?? 'vi-VN'],
            'suggested_entry_key' => $this->slug($normalized),
            'semantic_reference' => ['type' => $concept->destinationType, 'id' => $concept->destinationId],
            'warnings' => array_values(array_unique($warnings)),
            'conflicts' => $invalidDestination ? ['DESTINATION_INVALID'] : [],
        ];
    }

    private function invalidDestination(DictionaryConcept $concept): bool
    {
        if ($concept->destinationType === null && $concept->destinationId === null) return false;
        if ($concept->destinationType === null || $concept->destinationId === null) return true;
        if (!is_callable($this->destinationValidator)) return false;
        try { return ($this->destinationValidator)($concept->destinationType, $concept->destinationId, $concept->destinationUrl) === false; }
        catch (\Throwable) { return true; }
    }

    private function slug(string $value): string
    {
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = function_exists('iconv') ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) : $value;
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
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
