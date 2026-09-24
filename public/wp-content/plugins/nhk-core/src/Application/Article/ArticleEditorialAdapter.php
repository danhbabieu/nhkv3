<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, ReaderJourneyPlanner, SemanticSeoPlanner, SharedEditorialComposer, SharedEnrichmentBoundary};

/**
 * Article-owned boundary for the shared transient editorial pipeline.
 *
 * This adapter owns no Article persistence. It returns the shared read models
 * so the native Capture/WordPress Article owner can decide when to mutate.
 */
final class ArticleEditorialAdapter
{
    public function __construct(
        private EditorialClaimRetrievalService $retrieval,
        private EditorialKnowledgeSelector $selector,
        private ReaderJourneyPlanner $journey,
        private SharedEditorialComposer $composer,
        private SemanticSeoPlanner $seo,
        private EditorialQualityGate $quality,
        private ?SharedEnrichmentBoundary $shared = null,
    ) {
    }

    public static function fromEngine(ClaimRetrievalEngine $engine, ?SharedEnrichmentBoundary $shared = null): self
    {
        $retrieval = new EditorialClaimRetrievalService($engine);
        $selector = new EditorialKnowledgeSelector();
        return new self(
            $retrieval,
            $selector,
            new ReaderJourneyPlanner(),
            new SharedEditorialComposer(),
            new SemanticSeoPlanner(),
            new EditorialQualityGate(),
            $shared ?? new SharedEnrichmentBoundary($retrieval, $selector),
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function prepare(array $context): array
    {
        $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
        $subject = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $topic = trim((string) ($context['topic'] ?? $context['raw_input'] ?? $context['title'] ?? ''));
        $inputContext = [
            'raw_input' => trim((string) ($context['raw_input'] ?? $context['text'] ?? '')),
            'title' => trim((string) ($context['title'] ?? '')),
            'observations' => is_array($context['observations'] ?? null) ? $context['observations'] : [],
        ];
        $profile = ['profile' => 'article', 'result_limit' => 50];
        $shared = $this->shared?->enrich([
            'profile' => 'article', 'subject_resolution' => $resolution, 'subject' => $subject,
            'topic' => $topic, 'raw_input' => $inputContext['raw_input'], 'title' => $inputContext['title'],
            'observations' => $inputContext['observations'], 'hints' => (array) ($context['hints'] ?? []),
            'semantic_needs' => (array) ($context['semantic_needs'] ?? $context['needs'] ?? []),
            'prepared_context' => is_array($context['prepared_context'] ?? null) ? $context['prepared_context'] : [],
        ]);
        $retrieved = is_array($shared['content']['retrieval'] ?? null) ? $shared['content']['retrieval'] : $this->retrieval->retrieve($subject, $topic, (array) ($context['hints'] ?? []), $profile);
        $pack = $shared['content']['pack'] ?? $this->selector->select($retrieved, $topic, $subject, $profile, $inputContext);
        $plan = $this->journey->plan($pack);
        $draft = $this->composer->compose($plan);
        $seo = $this->seo->plan($pack, $plan, $draft, [
            'public_identity' => is_array($context['public_identity'] ?? null) ? $context['public_identity'] : [],
            'runtime_available' => ($context['runtime_available'] ?? true) === true,
            'internal_link_candidates' => is_array($context['internal_link_candidates'] ?? null) ? $context['internal_link_candidates'] : [],
            'dictionary_terms' => is_array($context['dictionary_terms'] ?? null) ? $context['dictionary_terms'] : [],
            'competing_pages' => is_array($context['competing_pages'] ?? null) ? $context['competing_pages'] : [],
            'structured_data' => ['type' => 'Article'],
        ]);
        $quality = $this->quality->evaluate($pack, $plan, $draft, $seo);

        return [
            'status' => $quality->readiness,
            'profile' => 'article',
            'retrieval' => $retrieved,
            'pack' => $pack,
            'plan' => $plan,
            'draft' => $draft,
            'seo_plan' => $seo,
            'quality_report' => $quality,
            'shared_enrichment' => $shared['content'] ?? ['status' => 'NOT_REQUESTED'],
            'shared_result' => $shared,
        ];
    }

    /** @param array<string,mixed> $retrieved @param array<string,mixed> $prepared @param array<string,mixed> $subject @return array<string,mixed> */
    private function boundToPreparedContext(array $retrieved, array $prepared, array $subject): array
    {
        if ($prepared === []) return $retrieved;
        $packet = is_array($prepared['subject_resolution_packet'] ?? null) ? $prepared['subject_resolution_packet'] : [];
        $primaryId = trim((string) ($packet['canonical_subject_id'] ?? $subject['id'] ?? ''));
        $selected = [];
        foreach ((array) ($prepared['selected_related_entities'] ?? []) as $entity) {
            if (!is_array($entity)) continue;
            foreach ([(string) ($entity['id'] ?? ''), (string) ($entity['stable_key'] ?? ''), (string) ($entity['name'] ?? $entity['value'] ?? '')] as $key) {
                if ($key !== '') $selected[$key] = true;
            }
        }
        foreach ((array) ($prepared['selected_knowledge'] ?? []) as $claim) {
            if (is_array($claim) && trim((string) ($claim['claim_id'] ?? $claim['id'] ?? '')) !== '') $selected[(string) ($claim['claim_id'] ?? $claim['id'])] = true;
        }
        $filter = static function (mixed $claim) use ($primaryId, $selected): bool {
            if (!is_array($claim) || ($claim['eligibility'] ?? '') !== 'eligible') return false;
            $claimId = (string) ($claim['claim_id'] ?? $claim['id'] ?? '');
            $subjectId = (string) ($claim['subject_id'] ?? '');
            $subjectName = (string) ($claim['subject_name'] ?? $claim['name'] ?? '');
            $subjectType = strtolower(trim((string) ($claim['subject_type'] ?? '')));
            if ($subjectType === 'video' && !isset($selected[$subjectId])) return false;
            return $subjectId === $primaryId || isset($selected[$claimId]) || isset($selected[$subjectId]) || ($subjectName !== '' && isset($selected[$subjectName]));
        };
        $retrieved['items'] = array_values(array_filter((array) ($retrieved['items'] ?? []), $filter));
        $retrieved['eligible_claims'] = array_values(array_filter((array) ($retrieved['eligible_claims'] ?? []), $filter));
        $retrieved['selected_claims'] = $retrieved['eligible_claims'];
        $retrieved['diagnostics']['prepared_context_bound'] = true;
        return $retrieved;
    }
}
