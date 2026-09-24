<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, EditorialQualityReport, ReaderJourneyPlanner, SemanticSeoPlanner, SharedEditorialComposer, SharedEnrichmentBoundary};
use NHK\Core\Application\Semantic\{EditorialDraft, SemanticSeoPlan};

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
        private ?VideoStatementDecisionEngine $statementDecisions = null,
        private ?VideoEditorialDecisionPipeline $decisionPipeline = null,
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
            new VideoStatementDecisionEngine(),
            new VideoEditorialDecisionPipeline(),
            $shared ?? new SharedEnrichmentBoundary($retrieval, $selector),
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function prepare(array $context): array
    {
        $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
        $subject = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $source = is_array($context['source'] ?? null) ? $context['source'] : [];
        $userHint = trim((string) ($context['user_hint'] ?? $context['raw_input'] ?? ''));
        $scope = new VideoEditorialScopeNormalizer();
        $topic = $scope->publicTopic($subject, (string) ($source['source_title'] ?? ''));
        $retrievalTopic = trim(implode(' ', array_filter([
            $topic,
            $userHint,
            trim((string) ($context['editorial_instruction'] ?? $context['topic'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));
        $inputContext = [
            'raw_input' => $scope->input($userHint, $subject, (string) ($source['source_title'] ?? '')),
            'title' => trim((string) ($context['editorial_title'] ?? '')),
            'observations' => is_array($context['observations'] ?? null) ? $context['observations'] : [],
        ];
        $profile = ['profile' => 'video', 'result_limit' => 50];
        $shared = $this->shared?->enrich([
            'profile' => 'video', 'subject_resolution' => $resolution, 'subject' => $subject,
            'topic' => $topic, 'retrieval_topic' => $retrievalTopic, 'raw_input' => $inputContext['raw_input'], 'title' => $inputContext['title'],
            'observations' => $inputContext['observations'], 'components' => (array) ($context['components'] ?? $context['semantic_components'] ?? []), 'hints' => (array) ($context['hints'] ?? []),
            'semantic_needs' => (array) ($context['semantic_needs'] ?? $context['needs'] ?? []),
            'relations' => is_array($context['relations'] ?? null) ? $context['relations'] : [],
        ]);
        $retrieved = is_array($shared['content']['retrieval'] ?? null) ? $shared['content']['retrieval'] : $this->retrieval->retrieve($subject, $topic, (array) ($context['hints'] ?? []), $profile);
        $pack = $shared['content']['pack'] ?? $this->selector->select($retrieved, $topic, $subject, $profile, $inputContext);
        $plan = $this->journey->plan($pack);
        $draft = $this->composer->compose($plan);
        $seoContext = [
            'public_identity' => is_array($context['public_identity'] ?? null) ? $context['public_identity'] : [],
            'runtime_available' => ($context['runtime_available'] ?? true) === true,
            'internal_link_candidates' => is_array($context['internal_link_candidates'] ?? null) ? $context['internal_link_candidates'] : [],
            'dictionary_terms' => is_array($context['dictionary_terms'] ?? null) ? $context['dictionary_terms'] : [],
            'competing_pages' => is_array($context['competing_pages'] ?? null) ? $context['competing_pages'] : [],
            'structured_data' => ['type' => 'VideoObject'],
        ];
        $seo = $this->seo->plan($pack, $plan, $draft, $seoContext);
        $attemptId = trim((string) ($context['attempt_id'] ?? ''));
        $attemptNo = max(0, (int) ($context['attempt_no'] ?? 0));
        $qualityPackage = ['title' => $draft->title, 'summary' => $draft->summary, 'body' => $draft->body, 'seo_title' => $seo->title, 'seo_description' => $seo->metaDescription];
        $qualityPackageFingerprint = hash('sha256', (string) json_encode($qualityPackage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $quality = $this->quality->evaluate($pack, $plan, $draft, $seo, ($context['public_identity_deferred'] ?? false) === true, 0, $qualityPackageFingerprint, $attemptId, $attemptNo);
        $statementDecision = ($this->statementDecisions ??= new VideoStatementDecisionEngine())->evaluate(
            is_array($context['statements'] ?? null) ? $context['statements'] : [],
            is_array($context['canonical_context'] ?? null) ? $context['canonical_context'] : [],
            is_array($context['evidence_context'] ?? null) ? $context['evidence_context'] : [],
            is_array($context['visual_context'] ?? null) ? $context['visual_context'] : [],
        );
        $copyGuard = new PublicEditorialCopyGuard();
        $qualityReevaluations = [];
        $decision = ($this->decisionPipeline ??= new VideoEditorialDecisionPipeline())->run(
            [
                'title' => $draft->title,
                'summary' => $draft->summary,
                'body' => $draft->body,
                'seo_title' => $seo->title,
                'seo_description' => $seo->metaDescription,
                'claims' => $pack->selectedClaims,
            ],
            ['statement_decision' => $statementDecision->toArray()],
            function (array $package, array $decisionContext, int $round) use ($pack, $plan, $draft, $seoContext): array {
                $currentDraft = new EditorialDraft(
                    $draft->status,
                    $draft->profile,
                    (string) ($package['title'] ?? $draft->title),
                    (string) ($package['summary'] ?? $draft->summary),
                    (string) ($package['body'] ?? $draft->body),
                    $draft->claimTrace,
                    $draft->diagnostics,
                );
                $currentSeo = $this->seo->plan($pack, $plan, $currentDraft, $seoContext);
                return ['seo_title' => $currentSeo->title, 'seo_description' => $currentSeo->metaDescription];
            },
            function (array $package, array $decisionContext, int $round) use ($copyGuard, $pack, $plan, $seoContext, $draft, $context, $attemptId, $attemptNo, &$qualityReevaluations): array {
                $currentDraft = new EditorialDraft(
                    $draft->status,
                    $draft->profile,
                    (string) ($package['title'] ?? $draft->title),
                    (string) ($package['summary'] ?? $draft->summary),
                    (string) ($package['body'] ?? $draft->body),
                    $draft->claimTrace,
                    $draft->diagnostics,
                );
                $currentSeo = $this->seo->plan($pack, $plan, $currentDraft, $seoContext);
                $currentPackage = ['title' => $currentDraft->title, 'summary' => $currentDraft->summary, 'body' => $currentDraft->body, 'seo_title' => $currentSeo->title, 'seo_description' => $currentSeo->metaDescription];
                $currentFingerprint = hash('sha256', (string) json_encode($currentPackage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $currentQuality = $this->quality->evaluate($pack, $plan, $currentDraft, $currentSeo, ($context['public_identity_deferred'] ?? false) === true, $round, $currentFingerprint, $attemptId, $attemptNo);
                $qualityReevaluations[] = $currentQuality->toArray();
                $findings = $copyGuard->findings($package);
                if ($round === 0) $findings = array_merge($findings, $decisionContext['statement_decision']['findings'] ?? []);
                return array_merge($this->qualityBlockFindings($currentQuality), $findings);
            },
        );

        $finalDraft = new EditorialDraft($draft->status, $draft->profile, (string) ($decision['editorial_package']['title'] ?? $draft->title), (string) ($decision['editorial_package']['summary'] ?? $draft->summary), (string) ($decision['editorial_package']['body'] ?? $draft->body), $draft->claimTrace, $draft->diagnostics);
        $finalSeo = $this->seo->plan($pack, $plan, $finalDraft, $seoContext);
        $finalPackage = ['title' => $finalDraft->title, 'summary' => $finalDraft->summary, 'body' => $finalDraft->body, 'seo_title' => $finalSeo->title, 'seo_description' => $finalSeo->metaDescription];
        $finalFingerprint = hash('sha256', (string) json_encode($finalPackage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $quality = $this->quality->evaluate($pack, $plan, $finalDraft, $finalSeo, ($context['public_identity_deferred'] ?? false) === true, (int) ($decision['rounds'] ?? 0), $finalFingerprint, $attemptId, $attemptNo);

        return [
            'status' => $quality->readiness,
            'profile' => 'video',
            'retrieval' => $retrieved,
            'pack' => $pack,
            'plan' => $plan,
            'draft' => $finalDraft,
            'seo_plan' => $finalSeo,
            'quality_report' => $quality,
            'decision_trace' => $statementDecision->items(),
            'constraint_findings' => $decision['findings'],
            'quality_decision' => $decision['quality'],
            'repair_rounds' => $decision['rounds'],
            'quality_re_evaluations' => $qualityReevaluations,
            'fingerprint_claims' => array_values(array_map(static fn (array $claim): array => [
                'id' => (string) ($claim['claim_id'] ?? ''),
                'revision' => max(1, (int) ($claim['claim_revision'] ?? 1)),
            ], array_filter($pack->selectedClaims, 'is_array'))),
            'shared_enrichment' => $shared['content'] ?? ['status' => 'NOT_REQUESTED'],
            'shared_result' => $shared,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function qualityBlockFindings(EditorialQualityReport $quality): array
    {
        return array_values(array_map(static fn (string $code): array => [
            'code' => $code,
            'severity' => VideoConstraintSeverity::HARD_BLOCK,
            'scope' => 'artifact',
            'claim_id' => null,
            'repair' => VideoEditorialAction::USE_AS_IS,
            'reason' => 'Shared EditorialQualityGate blocked the video editorial package.',
        ], array_values(array_unique($quality->blockers))));
    }
}
