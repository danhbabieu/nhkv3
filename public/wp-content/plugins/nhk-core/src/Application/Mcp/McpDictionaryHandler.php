<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Domain\Dictionary\{DictionaryCandidateState, DictionaryLabel};

final class McpDictionaryHandler
{
    public function __construct(private DictionaryRuntime $runtime) {}

    public function search(string $query, int $limit = 50): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $lower = static fn (string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $contains = static fn (string $haystack, string $needle): bool => (function_exists('mb_strpos') ? mb_strpos($haystack, $needle) : strpos($haystack, $needle)) !== false;
        $q = $lower(trim($query)); $items = [];
        foreach (($this->runtime->publicQuery()->hub(max(1, min(500, $limit * 4)))['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $haystack = $lower((string) ($item['title'] ?? '') . ' ' . implode(' ', array_map(static fn (array $label): string => (string) ($label['label'] ?? ''), array_filter((array) ($item['labels'] ?? []), 'is_array'))));
            if ($q === '' || $contains($haystack, $q)) $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return ['status' => 'available', 'items' => $items, 'count' => count($items)];
    }

    public function conceptGet(string $conceptId): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $concept = $this->runtime->concepts()->findById($conceptId);
        if ($concept === null) return ['status' => 'not_found', 'reason' => 'DICTIONARY_CONCEPT_NOT_FOUND'];
        return ['status' => 'available', 'concept' => $this->concept($concept), 'labels' => array_map($this->label(...), $this->runtime->concepts()->listLabels($conceptId, true))];
    }

    public function candidateList(?string $state = null, int $limit = 100): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $items = $this->runtime->candidates()->listForReview(max(1, min(100, $limit)));
        $state = $state !== null ? strtoupper(trim($state)) : null;
        if ($state !== null && !DictionaryCandidateState::valid($state)) return ['status' => 'conflict', 'reason' => 'DICTIONARY_CANDIDATE_STATE_INVALID'];
        $items = array_values(array_filter($items, static fn (mixed $item): bool => $state === null || $item->state === $state));
        return ['status' => 'available', 'items' => array_map($this->candidate(...), $items), 'count' => count($items)];
    }

    public function createConcept(array $input): array { return $this->runtime->mutation()->createDraft((string) ($input['preferred_label'] ?? ''), (string) ($input['definition'] ?? ''), (array) ($input['context'] ?? []), (string) ($input['idempotency_key'] ?? '')); }
    public function updateConcept(array $input): array { return $this->runtime->mutation()->updateConcept((string) $input['concept_id'], (int) $input['expected_revision'], (string) $input['preferred_label'], (string) ($input['definition'] ?? ''), (array) ($input['context'] ?? []), (string) $input['idempotency_key']); }
    public function lifecycle(array $input): array { return $this->runtime->mutation()->setConceptStatus((string) $input['concept_id'], (int) $input['expected_revision'], (string) $input['status'], (string) $input['idempotency_key']); }

    public function saveLabel(array $input): array
    {
        $label = new DictionaryLabel((string) $input['concept_id'], (string) $input['label'], (string) $input['normalized_label'], (string) $input['kind'], isset($input['locale']) ? (string) $input['locale'] : null, (array) ($input['context'] ?? []), (bool) ($input['active'] ?? true));
        return $this->runtime->mutation()->saveLabel($label->conceptId, (int) $input['expected_revision'], (string) ($input['previous_normalized_label'] ?? ''), $label, (string) $input['idempotency_key']);
    }

    public function review(array $input): array
    {
        $decision = strtoupper((string) $input['decision']); $id = (string) $input['candidate_id']; $revision = (int) $input['expected_revision']; $curation = $this->runtime->curation();
        return $this->runtime->mutation()->idempotent('candidate_review', [
            'candidate_id' => $id,
            'expected_revision' => $revision,
            'decision' => $decision,
            'concept_id' => $input['concept_id'] ?? null,
            'preferred_label' => (string) ($input['preferred_label'] ?? ''),
            'definition' => (string) ($input['definition'] ?? ''),
        ], (string) $input['idempotency_key'], fn (): array => match ($decision) {
            'ATTACH' => $curation->attachToExisting($id, $revision, (string) ($input['concept_id'] ?? '')),
            'CREATE_DRAFT' => $curation->createDraftFromCandidate($id, $revision, (string) ($input['preferred_label'] ?? ''), (string) ($input['definition'] ?? '')),
            'AMBIGUOUS' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::AMBIGUOUS)],
            'REJECT' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::REJECTED)],
            'IGNORE' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::IGNORED)],
            'DO_NOT_SUGGEST' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::DO_NOT_SUGGEST)],
            default => throw new \InvalidArgumentException('DICTIONARY_REVIEW_DECISION_INVALID'),
        });
    }

    public function handoff(array $input): array { return $this->runtime->relationHandoff()->prepare($input); }
    public function backfillDryRun(array $sources): array { return (new \NHK\Core\Application\Dictionary\DictionaryBackfillDryRun(fn (string $text, string $kind, array $context, array $hints): array => $this->runtime->preview($text, $kind, '', $context, $hints)))->scan($sources); }

    private function concept(object $concept): array { return ['id' => $concept->conceptId, 'preferred_label' => $concept->preferredLabel, 'definition' => $concept->definition, 'status' => $concept->status, 'destination_type' => $concept->destinationType, 'destination_id' => $concept->destinationId, 'destination_url' => $concept->destinationUrl, 'context' => $concept->context, 'revision' => $concept->revision]; }
    private function label(object $label): array { return ['label' => $label->label, 'normalized_label' => $label->normalizedLabel, 'kind' => $label->kind, 'locale' => $label->locale, 'context' => $label->context, 'active' => $label->active]; }
    private function candidate(object $candidate): array { return ['id' => $candidate->candidateId, 'normalized_term' => $candidate->normalizedTerm, 'raw_forms' => $candidate->rawForms, 'state' => $candidate->state, 'context' => $candidate->context, 'suggestions' => $candidate->suggestions, 'occurrences' => $candidate->occurrences, 'revision' => $candidate->revision]; }
}
