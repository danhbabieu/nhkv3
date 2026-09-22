<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, ReaderJourneyPlanner, SemanticSeoPlanner, SharedEditorialComposer};

/**
 * Video-owned boundary over the shared transient editorial pipeline.
 * Video identity, source metadata and governed persistence remain outside this
 * adapter; it only returns shared read models for the existing Video owner.
 */
final class VideoEditorialAdapter
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
        $source = is_array($context['source'] ?? null) ? $context['source'] : [];
        $topic = trim((string) ($context['topic'] ?? $context['editorial_instruction'] ?? $context['user_hint'] ?? $context['raw_input'] ?? $source['source_title'] ?? ''));
        $inputContext = [
            'raw_input' => trim((string) ($context['raw_input'] ?? $context['user_hint'] ?? '')),
            'title' => trim((string) ($context['editorial_title'] ?? '')),
            'observations' => is_array($context['observations'] ?? null) ? $context['observations'] : [],
        ];
        $profile = ['profile' => 'video', 'selection_limit' => 6, 'result_limit' => 50];
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
            'structured_data' => ['type' => 'VideoObject'],
        ]);
        $quality = $this->quality->evaluate($pack, $plan, $draft, $seo);

        return [
            'status' => $quality->readiness,
            'profile' => 'video',
            'retrieval' => $retrieved,
            'pack' => $pack,
            'plan' => $plan,
            'draft' => $draft,
            'seo_plan' => $seo,
            'quality_report' => $quality,
            'fingerprint_claims' => array_values(array_map(static fn (array $claim): array => [
                'id' => (string) ($claim['claim_id'] ?? ''),
                'revision' => max(1, (int) ($claim['claim_revision'] ?? 1)),
            ], array_filter($pack->selectedClaims, 'is_array'))),
        ];
    }
}
