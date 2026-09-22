<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, ReaderJourneyPlanner, SemanticSeoPlanner, SharedEditorialComposer};

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
    ) {
    }

    public static function fromEngine(ClaimRetrievalEngine $engine): self
    {
        return new self(
            new EditorialClaimRetrievalService($engine),
            new EditorialKnowledgeSelector(),
            new ReaderJourneyPlanner(),
            new SharedEditorialComposer(),
            new SemanticSeoPlanner(),
            new EditorialQualityGate(),
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
        $profile = ['profile' => 'article', 'selection_limit' => 8, 'result_limit' => 50];
        $retrieved = $this->retrieval->retrieve($subject, $topic, (array) ($context['hints'] ?? []), $profile);
        $pack = $this->selector->select($retrieved, $topic, $subject, $profile, $inputContext);
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
        ];
    }
}
