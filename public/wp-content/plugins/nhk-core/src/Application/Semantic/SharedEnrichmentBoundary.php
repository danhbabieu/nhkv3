<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeEnrichmentProposalFactory};
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;

/**
 * Transient shared enrichment seam. It coordinates read/planning services only;
 * it never creates owners, writes semantic data, applies proposals, or publishes.
 */
final class SharedEnrichmentBoundary
{
    /** @param callable(array<string,mixed>):array<string,mixed>|null $relations */
    public function __construct(
        private EditorialClaimRetrievalService $retrieval,
        private EditorialKnowledgeSelector $selector,
        private ?KnowledgeEnrichmentPlanner $knowledge = null,
        private ?KnowledgeEnrichmentProposalFactory $proposalFactory = null,
        private mixed $relations = null,
        private ?SemanticNeedDecomposer $decomposer = null,
    ) {
        $this->decomposer ??= new SemanticNeedDecomposer(new TextInputInterpreter(), new SemanticNeedVocabulary());
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function enrich(array $request): array
    {
        $profile = strtolower(trim((string) ($request['profile'] ?? '')));
        $result = [
            'profile' => $profile,
            'content' => ['status' => 'NOT_REQUESTED', 'retrieval' => [], 'pack' => null, 'selected_claims' => [], 'gaps' => [], 'diagnostics' => []],
            'knowledge' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'proposals' => [], 'classifications' => [], 'proposal_ready' => false, 'diagnostics' => []],
            'relations' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'readiness' => [], 'diagnostics' => []],
        ];

        if (in_array($profile, ['article', 'video', 'media', 'text'], true)) $result['content'] = $this->content($request, $profile);
        if ($profile === 'knowledge_delta' || (($request['knowledge'] ?? false) === true)) $result['knowledge'] = $this->knowledge($request);
        if (is_array($request['relations'] ?? null)) $result['relations'] = $this->relations($request);
        return $result;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function content(array $request, string $profile): array
    {
        $resolution = is_array($request['subject_resolution'] ?? null) ? $request['subject_resolution'] : [];
        $subject = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : (is_array($request['subject'] ?? null) ? $request['subject'] : []);
        $topic = trim((string) ($request['topic'] ?? $request['raw_input'] ?? $request['title'] ?? ''));
        $retrievalTopic = trim((string) ($request['retrieval_topic'] ?? $topic));
        $profileData = ['profile' => $profile, 'result_limit' => max(1, min(200, (int) ($request['result_limit'] ?? 50)))];
        if (array_key_exists('selection_limit', $request)) $profileData['selection_limit'] = max(1, min(20, (int) $request['selection_limit']));
        $envelope = SemanticInputEnvelope::fromArray([
            'raw_text' => (string) ($request['raw_input'] ?? $retrievalTopic),
            'title' => (string) ($request['title'] ?? ''),
            'subject_resolution' => ['primary' => $subject],
            'components' => (array) ($request['components'] ?? $request['semantic_components'] ?? []),
            'observations' => (array) ($request['observations'] ?? []),
            'target_surface' => $profile,
        ]);
        $needs = array_values(array_filter((array) ($request['semantic_needs'] ?? $request['needs'] ?? []), static fn (mixed $need): bool => $need instanceof SemanticNeed || is_array($need)));
        $decomposition = null;
        if ($needs === [] && $this->decomposer !== null) {
            $decomposition = $this->decomposer->decompose($envelope)->toArray();
            $needs = (array) ($decomposition['needs'] ?? []);
        }
        if ($needs !== []) {
            $retrieved = $this->retrieval->retrieveForNeeds($envelope, $needs, $profileData);
        } else {
            $retrieved = $this->retrieval->retrieve($subject, $retrievalTopic, (array) ($request['hints'] ?? []), $profileData);
        }
        $retrieved = $this->boundToPreparedContext($retrieved, is_array($request['prepared_context'] ?? null) ? $request['prepared_context'] : [], $subject);
        $inputContext = [
            'raw_input' => trim((string) ($request['raw_input'] ?? '')),
            'title' => trim((string) ($request['title'] ?? '')),
            'observations' => is_array($request['observations'] ?? null) ? $request['observations'] : [],
        ];
        $pack = $this->selector->select($retrieved, $topic, $subject, $profileData, $inputContext);
        $diagnostics = array_values(array_filter(array_map('strval', (array) ($retrieved['diagnostics'] ?? []))));
        if ($pack->selectedClaims === []) $diagnostics[] = 'SHARED_CONTENT_CONTEXT_SPARSE';
        $gaps = $diagnostics === [] ? [] : $diagnostics;
        return [
            'status' => $pack->status,
            'retrieval' => $retrieved,
            'semantic_needs' => array_map(static fn (mixed $need): array => $need instanceof SemanticNeed ? $need->toArray() : (array) $need, $needs),
            'decomposition' => $decomposition,
            'pack' => $pack,
            'selected_claims' => $pack->selectedClaims,
            'gaps' => array_values(array_unique($gaps)),
            'diagnostics' => array_values(array_unique($diagnostics)),
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function knowledge(array $request): array
    {
        $base = ['status' => 'INCOMPLETE', 'candidates' => [], 'proposals' => [], 'classifications' => [], 'proposal_ready' => false, 'diagnostics' => []];
        $observation = trim((string) ($request['observation'] ?? ''));
        $origin = strtoupper(trim((string) ($request['origin'] ?? '')));
        if ($observation === '') return array_merge($base, ['diagnostics' => ['OBSERVATION_REQUIRED']]);
        if (($request['generated'] ?? false) === true || in_array($origin, ['GENERATED_ARTICLE_PROSE', 'GENERATED_VIDEO_PROSE', 'EDITORIAL_DRAFT'], true)) return array_merge($base, ['diagnostics' => ['GENERATED_PROSE_NOT_KNOWLEDGE']]);
        if ($this->knowledge === null || $this->proposalFactory === null) return array_merge($base, ['diagnostics' => ['KNOWLEDGE_ENRICHMENT_UNAVAILABLE']]);
        try {
            $facet = new KnowledgeFacetProfile((string) ($request['facet'] ?? ''), (string) ($request['scope'] ?? ''));
            $candidates = $this->knowledge->plan((string) ($request['subject_id'] ?? ''), $facet, $observation, is_array($request['context'] ?? null) ? $request['context'] : []);
        } catch (\Throwable $error) {
            return array_merge($base, ['diagnostics' => ['KNOWLEDGE_ENRICHMENT_INVALID:' . $error->getMessage()]]);
        }
        $serialized = [];
        $proposals = [];
        $classifications = [];
        foreach ($candidates as $candidate) {
            $classifications[] = $candidate->classification;
            $serialized[] = ['classification' => $candidate->classification, 'subject_id' => $candidate->subjectId, 'facet' => $candidate->profile->facet, 'scope' => $candidate->profile->scope, 'observation' => $candidate->observation, 'provenance' => $candidate->provenance];
            if (in_array($candidate->classification, ['new_claim', 'add_evidence', 'qualify', 'contradict'], true)) {
                try { $proposals[] = $this->proposalFactory->arguments($candidate, (string) ($request['operation_id'] ?? 'shared-enrichment')); } catch (\Throwable $error) { $base['diagnostics'][] = 'PROPOSAL_NOT_READY:' . $error->getMessage(); }
            }
        }
        $ready = $proposals !== [] && $base['diagnostics'] === [];
        return ['status' => $ready ? 'PROPOSAL_READY' : 'REVIEW_REQUIRED', 'candidates' => $serialized, 'proposals' => $proposals, 'classifications' => array_values(array_unique($classifications)), 'proposal_ready' => $ready, 'diagnostics' => array_values(array_unique($base['diagnostics']))];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function relations(array $request): array
    {
        if ($this->relations === null) return ['status' => 'INCOMPLETE', 'candidates' => [], 'readiness' => ['status' => 'OPTIONAL_ENRICHMENT', 'reason' => 'RELATION_PLANNER_UNAVAILABLE'], 'diagnostics' => ['RELATION_PLANNER_UNAVAILABLE']];
        try { $candidates = ($this->relations)($request); } catch (\Throwable $error) { return ['status' => 'INCOMPLETE', 'candidates' => [], 'readiness' => ['status' => 'REVIEW_REQUIRED'], 'diagnostics' => ['RELATION_PLANNING_FAILED:' . $error->getMessage()]]; }
        return ['status' => $candidates === [] ? 'INCOMPLETE' : 'AVAILABLE', 'candidates' => $candidates, 'readiness' => ['status' => $candidates === [] ? 'OPTIONAL_ENRICHMENT' : 'AVAILABLE', 'applied' => false], 'diagnostics' => []];
    }

    /** @param array<string,mixed> $retrieved @param array<string,mixed> $prepared @param array<string,mixed> $subject @return array<string,mixed> */
    private function boundToPreparedContext(array $retrieved, array $prepared, array $subject): array
    {
        if ($prepared === []) return $retrieved;
        $packet = is_array($prepared['subject_resolution_packet'] ?? null) ? $prepared['subject_resolution_packet'] : [];
        $primaryId = trim((string) ($packet['canonical_subject_id'] ?? $subject['id'] ?? ''));
        $selected = [];
        foreach ((array) ($prepared['selected_related_entities'] ?? []) as $entity) if (is_array($entity)) foreach ([(string) ($entity['id'] ?? ''), (string) ($entity['stable_key'] ?? ''), (string) ($entity['name'] ?? $entity['value'] ?? '')] as $key) if ($key !== '') $selected[$key] = true;
        foreach ((array) ($prepared['selected_knowledge'] ?? []) as $claim) if (is_array($claim) && trim((string) ($claim['claim_id'] ?? $claim['id'] ?? '')) !== '') $selected[(string) ($claim['claim_id'] ?? $claim['id'])] = true;
        $filter = static function (mixed $claim) use ($primaryId, $selected): bool {
            if (!is_array($claim) || ($claim['eligibility'] ?? '') !== 'eligible') return false;
            $claimId = (string) ($claim['claim_id'] ?? $claim['id'] ?? ''); $subjectId = (string) ($claim['subject_id'] ?? ''); $subjectName = (string) ($claim['subject_name'] ?? $claim['name'] ?? '');
            if (strtolower((string) ($claim['subject_type'] ?? '')) === 'video' && !isset($selected[$subjectId])) return false;
            return $subjectId === $primaryId || isset($selected[$claimId]) || isset($selected[$subjectId]) || ($subjectName !== '' && isset($selected[$subjectName]));
        };
        $retrieved['items'] = array_values(array_filter((array) ($retrieved['items'] ?? []), $filter));
        $retrieved['eligible_claims'] = array_values(array_filter((array) ($retrieved['eligible_claims'] ?? []), $filter));
        $retrieved['selected_claims'] = $retrieved['eligible_claims'];
        $retrieved['diagnostics']['prepared_context_bound'] = true;
        return $retrieved;
    }
}
