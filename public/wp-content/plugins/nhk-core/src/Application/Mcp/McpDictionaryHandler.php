<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Application\Dictionary\DictionaryMutationService;
use NHK\Core\Contracts\Dictionary\DictionaryCandidatePageReader;
use NHK\Core\Domain\Dictionary\{DictionaryCandidateState, DictionaryLabel};

final class McpDictionaryHandler
{
    public function __construct(private DictionaryRuntime $runtime, private $semanticRelationGovernance = null, private $lexicalRelationGovernance = null, private ?DictionaryMutationService $mutationOverride = null) {}

    public function semanticRelationRead(array $input = []): array { return $this->semanticRelationGovernance?->read($input) ?? ['status'=>'unavailable','reason'=>'SEMANTIC_RELATION_GOVERNANCE_UNAVAILABLE']; }
    public function semanticRelationPreview(array $input): array { return $this->semanticRelationGovernance?->preview($input) ?? ['status'=>'unavailable','reason'=>'SEMANTIC_RELATION_GOVERNANCE_UNAVAILABLE']; }
    public function semanticRelationApply(array $input): array { return $this->semanticRelationGovernance?->apply((array)($input['plan']??[]),(string)($input['approved_plan_fingerprint']??''),(string)($input['idempotency_key']??'')) ?? ['status'=>'unavailable','reason'=>'SEMANTIC_RELATION_GOVERNANCE_UNAVAILABLE']; }
    public function lexicalRelationRead(array $input = []): array { return $this->lexicalRelationGovernance?->read($input) ?? ['status'=>'unavailable','reason'=>'LEXICAL_RELATION_GOVERNANCE_UNAVAILABLE']; }
    public function lexicalRelationPreview(array $input): array { return $this->lexicalRelationGovernance?->preview($input) ?? ['status'=>'unavailable','reason'=>'LEXICAL_RELATION_GOVERNANCE_UNAVAILABLE']; }
    public function lexicalRelationApply(array $input): array { $result = $this->lexicalRelationGovernance?->apply((array)($input['plan']??[]),(string)($input['approved_plan_fingerprint']??''),(string)($input['idempotency_key']??'')) ?? ['status'=>'unavailable','reason'=>'LEXICAL_RELATION_GOVERNANCE_UNAVAILABLE']; if (($result['status'] ?? '') === 'READ_BACK_VERIFIED' && !($result['idempotent_replay'] ?? false)) $this->runtime->invalidateLabelCache(); return $result; }

    public function search(string $query, int $limit = 50): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $limit = max(1, min(500, $limit));
        $items = array_values(array_filter((array) ($this->runtime->publicQuery()->archive(['query' => $query, 'page_size' => $limit])['items'] ?? []), 'is_array'));
        if (count($items) > $limit) $items = array_slice($items, 0, $limit);
        return ['status' => 'available', 'items' => $items, 'count' => count($items)];
    }

    public function conceptGet(string $conceptId): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $concept = $this->runtime->concepts()->findById($conceptId);
        if ($concept === null) return ['status' => 'not_found', 'reason' => 'DICTIONARY_CONCEPT_NOT_FOUND'];
        return ['status' => 'available', 'concept' => $this->concept($concept), 'labels' => array_map($this->label(...), $this->runtime->concepts()->listLabels($conceptId, true))];
    }

    public function resolve(string $term, array $context = [], array $hints = []): array { return $this->runtime->resolve($term, $context, $hints); }

    public function candidateList(?string $state = null, int $limit = 100, ?string $cursor = null): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $state = $state !== null ? strtoupper(trim($state)) : null;
        if ($state !== null && !DictionaryCandidateState::valid($state)) return ['status' => 'conflict', 'reason' => 'DICTIONARY_CANDIDATE_STATE_INVALID'];
        $repository = $this->runtime->candidates();
        if ($repository instanceof DictionaryCandidatePageReader) {
            $page = $repository->pageForReview(max(1, min(100, $limit)), $cursor, $state);
            $items = array_values(array_filter((array) ($page['items'] ?? []), 'is_object'));
            return [
                'status' => 'available',
                'items' => array_map($this->candidate(...), $items),
                'count' => count($items),
                'page_count' => count($items),
                'total' => (int) ($page['total'] ?? count($items)),
                'has_more' => (bool) ($page['has_more'] ?? false),
                'next_cursor' => $page['next_cursor'] ?? null,
                'diagnostics' => (array) ($page['diagnostics'] ?? []),
            ];
        }
        if ($cursor !== null && trim($cursor) !== '') throw new \RuntimeException('DICTIONARY_CANDIDATE_PAGINATION_UNAVAILABLE');
        $items = array_values(array_filter($repository->listForReview(max(1, min(100, $limit))), static fn (mixed $item): bool => $state === null || $item->state === $state));
        return ['status' => 'available', 'items' => array_map($this->candidate(...), $items), 'count' => count($items), 'page_count' => count($items), 'total' => null, 'has_more' => false, 'next_cursor' => null, 'diagnostics' => [['code' => 'DICTIONARY_CANDIDATE_LEGACY_READER_FALLBACK']]];
    }

    public function candidateDetail(string $candidateId, int $limit = 50, int $offset = 0): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $candidate = $this->runtime->candidate($candidateId);
        if ($candidate === null) return ['status' => 'not_found', 'reason' => 'DICTIONARY_CANDIDATE_NOT_FOUND'];
        $limit = max(1, min(100, $limit)); $offset = max(0, $offset);
        $mentions = $this->runtime->mentionsForCandidate($candidateId, $limit, $offset);
        $hasMore = count($mentions) > $limit;
        if ($hasMore) $mentions = array_slice($mentions, 0, $limit);
        return ['status' => 'available', 'candidate' => $this->candidate($candidate), 'provenance' => $this->provenance($mentions, count($mentions), $hasMore ? $offset + $limit : null)];
    }

    public function mentions(array $input): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $items = $this->runtime->mentionsForSource((string) $input['source_kind'], (string) $input['source_id']);
        return ['status' => 'available', 'source_kind' => strtoupper((string) $input['source_kind']), 'source_id' => (string) $input['source_id'], 'items' => array_map(fn (object $mention): array => ['id' => $mention->mentionId, 'normalized_term' => $mention->normalizedTerm, 'concept_id' => $mention->conceptId, 'context' => $this->boundedContext($mention->context), 'strength' => $mention->strength, 'created_at' => $mention->createdAt], $items), 'count' => count($items)];
    }

    public function profile(array $input): array { return $this->runtime->profile(isset($input['concept_id']) ? (string) $input['concept_id'] : null, isset($input['slug']) ? (string) $input['slug'] : null); }
    public function enrichmentAudit(array $input): array { $limit = (int) ($input['limit'] ?? 50); if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('DICTIONARY_ENRICHMENT_LIMIT_INVALID'); return $this->runtime->enrichmentAudit($input); }
    public function enrichmentPlan(array $input): array { $limit = (int) ($input['limit'] ?? 50); if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('DICTIONARY_ENRICHMENT_LIMIT_INVALID'); return $this->runtime->enrichmentPlan($input); }
    public function enrichmentApply(array $input): array { if (!isset($input['plan'], $input['approved_plan_fingerprint'], $input['idempotency_key'])) throw new \InvalidArgumentException('DICTIONARY_ENRICHMENT_APPLY_INPUT_INVALID'); return $this->runtime->enrichmentApply($input); }
    public function materializationProfile(array $input): array { return $this->runtime->materializationPlanner()->plan($input); }
    public function materializationPlan(array $input): array { return $this->runtime->materializationPlanner()->plan($input); }
    public function materializationApply(array $input): array { return $this->runtime->materializationService()->apply((array) ($input['plan'] ?? []), (string) ($input['approved_plan_fingerprint'] ?? ''), (string) ($input['idempotency_key'] ?? '')); }

    public function createConcept(array $input): array { return ($this->mutationOverride ?? $this->runtime->mutation())->createDraft((string) ($input['preferred_label'] ?? ''), (string) ($input['definition'] ?? ''), (array) ($input['context'] ?? []), (string) ($input['idempotency_key'] ?? '')); }
    public function createEntryWithSense(array $input): array { return $this->runtime->mutation()->createEntryWithSense((string) ($input['preferred_form'] ?? ''), (string) ($input['definition'] ?? ''), (array) ($input['context'] ?? []), (string) ($input['idempotency_key'] ?? '')); }
    public function entryGet(array $input): array
    {
        if (!$this->runtime->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $id = trim((string) ($input['entry_id'] ?? ''));
        if ($id === '' || !method_exists($this->runtime->entries(), 'curatorRead')) throw new \RuntimeException('DICTIONARY_ENTRY_REPOSITORY_UNAVAILABLE');
        try { return ['status' => 'available', 'entry' => $this->runtime->entries()->curatorRead($id)]; }
        catch (\RuntimeException $e) { if ($e->getMessage() === 'DICTIONARY_ENTRY_NOT_FOUND') return ['status' => 'not_found', 'reason' => $e->getMessage()]; throw $e; }
    }
    public function entryUpdate(array $input): array { return ['status' => 'available', 'entry' => $this->runtime->mutation()->updateEntry($input)['entry'] ?? []]; }
    public function entryLifecycle(array $input): array { return ['status' => 'available', 'entry' => $this->runtime->mutation()->lifecycleEntry((string) ($input['entry_id'] ?? ''), (int) ($input['expected_revision'] ?? 0), (string) ($input['status'] ?? ''), (string) ($input['idempotency_key'] ?? ''))['entry'] ?? []]; }
    public function addFormToEntry(array $input): array { return $this->runtime->mutation()->addFormToEntry((string) $input['entry_id'], (int) $input['expected_revision'], (string) $input['form'], (array) ($input['context'] ?? []), (string) $input['idempotency_key'], (string) ($input['kind'] ?? 'ALTERNATE'), isset($input['locale']) ? (string) $input['locale'] : null); }
    public function addSenseToEntry(array $input): array { return $this->runtime->mutation()->addSenseToEntry((string) $input['entry_id'], (int) $input['expected_revision'], (string) $input['concept_id'], (array) ($input['context'] ?? []), (string) $input['idempotency_key']); }
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
        $candidate = $this->runtime->candidate($id);
        if ($candidate === null) throw new \RuntimeException('DICTIONARY_CANDIDATE_NOT_FOUND');
        $mutation = $this->runtime->mutation();
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
            'CREATE_ENTRY_WITH_SENSE' => $this->reviewCreateEntry($mutation, $curation, $candidate, $revision, $input),
            'ADD_SENSE_TO_ENTRY' => $this->reviewAddSense($mutation, $curation, $candidate, $revision, $input),
            'ADD_FORM_TO_ENTRY' => $this->reviewAddForm($mutation, $curation, $candidate, $revision, $input),
            'AMBIGUOUS' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::AMBIGUOUS)],
            'REJECT' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::REJECTED)],
            'IGNORE' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::IGNORED)],
            'DO_NOT_SUGGEST' => ['candidate' => $curation->decide($id, $revision, DictionaryCandidateState::DO_NOT_SUGGEST)],
            default => throw new \InvalidArgumentException('DICTIONARY_REVIEW_DECISION_INVALID'),
        });
    }

    private function reviewCreateEntry(object $mutation, object $curation, object $candidate, int $revision, array $input): array
    {
        $raw = trim((string) ($candidate->rawForms[0] ?? $candidate->normalizedTerm));
        $result = $mutation->createEntryWithSense($raw, (string) ($input['definition'] ?? ''), $candidate->context, (string) $input['idempotency_key']);
        $reused = (($result['resolution']['action'] ?? '') === 'REUSE_EXISTING');
        $result['candidate'] = $curation->decide($candidate->candidateId, $revision, $reused ? DictionaryCandidateState::RESOLVED_EXISTING : DictionaryCandidateState::PROPOSED_NEW, ['entry_id' => $result['entry']->entryId, 'resolution' => $result['resolution'] ?? null]);
        return $result;
    }

    private function reviewAddSense(object $mutation, object $curation, object $candidate, int $revision, array $input): array
    {
        $result = $mutation->addSenseToEntry((string) ($input['entry_id'] ?? ''), (int) ($input['entry_expected_revision'] ?? 0), (string) ($input['concept_id'] ?? ''), $candidate->context, (string) $input['idempotency_key']);
        $result['candidate'] = $curation->decide($candidate->candidateId, $revision, DictionaryCandidateState::RESOLVED_EXISTING, ['entry_id' => $result['entry']->entryId, 'sense_id' => $result['sense']->conceptId]);
        return $result;
    }

    private function reviewAddForm(object $mutation, object $curation, object $candidate, int $revision, array $input): array
    {
        $raw = trim((string) ($candidate->rawForms[0] ?? $candidate->normalizedTerm));
        $result = $mutation->addFormToEntry((string) ($input['entry_id'] ?? ''), (int) ($input['entry_expected_revision'] ?? 0), $raw, $candidate->context, (string) $input['idempotency_key']);
        $result['candidate'] = $curation->decide($candidate->candidateId, $revision, DictionaryCandidateState::RESOLVED_EXISTING, ['entry_id' => $result['entry']->entryId, 'form' => $raw]);
        return $result;
    }

    public function handoff(array $input): array { return $this->runtime->relationHandoff()->prepare($input); }
    public function backfillDryRun(array $sources): array { return (new \NHK\Core\Application\Dictionary\DictionaryBackfillDryRun(fn (string $text, string $kind, array $context, array $hints): array => $this->runtime->preview($text, $kind, '', $context, $hints)))->scan($sources); }

    private function concept(object $concept): array { return ['id' => $concept->conceptId, 'preferred_label' => $concept->preferredLabel, 'definition' => $concept->definition, 'status' => $concept->status, 'destination_type' => $concept->destinationType, 'destination_id' => $concept->destinationId, 'destination_url' => $concept->destinationUrl, 'context' => $concept->context, 'revision' => $concept->revision]; }
    private function label(object $label): array { return ['label' => $label->label, 'normalized_label' => $label->normalizedLabel, 'kind' => $label->kind, 'locale' => $label->locale, 'context' => $label->context, 'active' => $label->active]; }
    private function candidate(object $candidate): array { return ['id' => $candidate->candidateId, 'normalized_term' => $candidate->normalizedTerm, 'raw_forms' => $candidate->rawForms, 'state' => $candidate->state, 'context' => $candidate->context, 'suggestions' => $candidate->suggestions, 'occurrences' => $candidate->occurrences, 'revision' => $candidate->revision]; }
    private function provenance(array $mentions, int $count, ?int $nextOffset): array
    {
        $items = array_map(fn (object $mention): array => [
            'id' => $mention->mentionId,
            'source_kind' => $mention->sourceKind,
            'source_id' => $mention->sourceId,
            'concept_id' => $mention->conceptId,
            'context' => $this->boundedContext($mention->context),
            'strength' => $mention->strength,
            'created_at' => $mention->createdAt,
        ], $mentions);
        $families = [];
        foreach ($items as $item) {
            $key = $item['source_kind'] . ':' . $item['source_id'];
            $families[$key] = ($families[$key] ?? 0) + 1;
        }
        return ['mention_count' => $count, 'source_count' => count($families), 'items' => $items, 'next_offset' => $nextOffset];
    }

    private function boundedContext(array $context): array
    {
        $allowed = ['locale', 'domain', 'usage_scope', 'region', 'community', 'scope', 'term_type', 'subject', 'source_revision', 'source_family', 'lineage', 'locator', 'post_id', 'claim_type', 'attachment_id', 'platform', 'external_video_id', 'weak_sources'];
        $bounded = [];
        foreach ($allowed as $key) if (array_key_exists($key, $context)) $bounded[$key] = $context[$key];
        return $bounded;
    }
}
