<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeEnrichmentProposalFactory};
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;

/**
 * Domain-neutral transient enrichment coordinator.
 *
 * It only composes read/planning services. Owner creation, persistence,
 * Governance, publication and projection remain outside this class.
 */
final class UniversalEnrichmentCore
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

    /** @param array<string,mixed> $options */
    public function enrich(UniversalInputEnvelope $input, array $options = []): EnrichmentPack
    {
        $profile = strtolower(trim((string) ($options['profile'] ?? $input->toArray()['target_surface'] ?? '')));
        $branches = [
            'content' => ['status' => 'NOT_REQUESTED', 'retrieval' => [], 'pack' => null, 'selected_claims' => [], 'gaps' => [], 'diagnostics' => []],
            'knowledge' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'proposals' => [], 'classifications' => [], 'proposal_ready' => false, 'diagnostics' => []],
            'relations' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'readiness' => [], 'diagnostics' => []],
        ];
        if (in_array($profile, ['article', 'video', 'media', 'image', 'text', 'generic_source'], true)) {
            $branches['content'] = $this->content($input, $options, $profile);
        }
        if (($options['knowledge'] ?? false) === true || $profile === 'knowledge_delta') {
            $branches['knowledge'] = $this->knowledge($input, $options);
        }
        if (is_array($options['relations'] ?? null)) {
            $branches['relations'] = $this->relations($options);
        }
        return EnrichmentPack::fromBranches($branches);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function content(UniversalInputEnvelope $input, array $options, string $profile): array
    {
        $value = $input->toArray();
        $resolution = is_array($value['subject_resolution'] ?? null) ? $value['subject_resolution'] : [];
        $subject = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $topic = trim((string) ($options['topic'] ?? $value['body'] ?? $value['title'] ?? ''));
        $retrievalTopic = trim((string) ($options['retrieval_topic'] ?? $topic));
        $profileData = ['profile' => $profile, 'result_limit' => max(1, min(200, (int) ($options['result_limit'] ?? 50)))];
        if (array_key_exists('selection_limit', $options)) $profileData['selection_limit'] = max(1, min(20, (int) $options['selection_limit']));
        $needs = array_values(array_filter((array) ($options['semantic_needs'] ?? $options['needs'] ?? []), static fn (mixed $need): bool => $need instanceof SemanticNeed || is_array($need)));
        $decomposition = null;
        if ($needs === [] && $this->decomposer !== null) {
            $decomposition = $this->decomposer->decompose($input)->toArray();
            $needs = (array) ($decomposition['needs'] ?? []);
        }
        if ($needs !== []) $retrieved = $this->retrieval->retrieveForNeeds($input, $needs, $profileData);
        else $retrieved = $this->retrieval->retrieve($subject, $retrievalTopic, (array) ($options['hints'] ?? []), $profileData);
        $retrieved = $this->boundToPreparedContext($retrieved, is_array($options['prepared_context'] ?? null) ? $options['prepared_context'] : [], $subject);
        $inputContext = ['raw_input' => trim((string) ($value['body'] ?? '')), 'title' => trim((string) ($value['title'] ?? '')), 'observations' => (array) ($value['observations'] ?? [])];
        $pack = $this->selector->select($retrieved, $topic, $subject, $profileData, $inputContext);
        $diagnostics = array_values(array_filter(array_map('strval', (array) ($retrieved['diagnostics'] ?? []))));
        if ($pack->selectedClaims === []) $diagnostics[] = 'SHARED_CONTENT_CONTEXT_SPARSE';
        return [
            'status' => $pack->status === 'available' ? 'AVAILABLE' : strtoupper($pack->status),
            'retrieval' => $retrieved,
            'semantic_needs' => array_map(static fn (mixed $need): array => $need instanceof SemanticNeed ? $need->toArray() : (array) $need, $needs),
            'decomposition' => $decomposition,
            'pack' => $pack,
            'selected_claims' => $pack->selectedClaims,
            'gaps' => array_values(array_unique($diagnostics)),
            'diagnostics' => array_values(array_unique($diagnostics)),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function knowledge(UniversalInputEnvelope $input, array $options): array
    {
        $base = ['status' => 'INCOMPLETE', 'candidates' => [], 'proposals' => [], 'classifications' => [], 'proposal_ready' => false, 'diagnostics' => []];
        $value = $input->toArray();
        $observation = trim((string) ($options['observation'] ?? ''));
        $origin = strtoupper(trim((string) ($options['origin'] ?? '')));
        if ($observation === '') return array_merge($base, ['diagnostics' => ['OBSERVATION_REQUIRED']]);
        if (($options['generated'] ?? false) === true || in_array($origin, ['GENERATED_ARTICLE_PROSE', 'GENERATED_VIDEO_PROSE', 'EDITORIAL_DRAFT'], true)) return array_merge($base, ['diagnostics' => ['GENERATED_PROSE_NOT_KNOWLEDGE']]);
        if ($this->knowledge === null || $this->proposalFactory === null) return array_merge($base, ['diagnostics' => ['KNOWLEDGE_ENRICHMENT_UNAVAILABLE']]);
        try {
            $facet = new KnowledgeFacetProfile((string) ($options['facet'] ?? ''), (string) ($options['scope'] ?? ''));
            $candidates = $this->knowledge->plan((string) ($options['subject_id'] ?? $value['subject_resolution']['primary']['id'] ?? ''), $facet, $observation, is_array($options['context'] ?? null) ? $options['context'] : []);
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
                try { $proposals[] = $this->proposalFactory->arguments($candidate, (string) ($options['operation_id'] ?? 'shared-enrichment')); } catch (\Throwable $error) { $base['diagnostics'][] = 'PROPOSAL_NOT_READY:' . $error->getMessage(); }
            }
        }
        return ['status' => $proposals !== [] && $base['diagnostics'] === [] ? 'PROPOSAL_READY' : 'REVIEW_REQUIRED', 'candidates' => $serialized, 'proposals' => $proposals, 'classifications' => array_values(array_unique($classifications)), 'proposal_ready' => $proposals !== [] && $base['diagnostics'] === [], 'diagnostics' => array_values(array_unique($base['diagnostics']))];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function relations(array $options): array
    {
        if ($this->relations === null) return ['status' => 'INCOMPLETE', 'candidates' => [], 'readiness' => ['status' => 'OPTIONAL_ENRICHMENT', 'reason' => 'RELATION_PLANNER_UNAVAILABLE'], 'diagnostics' => ['RELATION_PLANNER_UNAVAILABLE']];
        try { $candidates = ($this->relations)($options); } catch (\Throwable $error) { return ['status' => 'INCOMPLETE', 'candidates' => [], 'readiness' => ['status' => 'REVIEW_REQUIRED'], 'diagnostics' => ['RELATION_PLANNING_FAILED:' . $error->getMessage()]]; }
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
