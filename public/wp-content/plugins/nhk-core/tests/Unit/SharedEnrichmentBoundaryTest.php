<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeEnrichmentProposalFactory};
use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, SemanticInputEnvelope, SemanticNeed, SemanticNeedDecomposer, SharedEnrichmentBoundary, TextInputInterpreter};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SharedEnrichmentBoundaryTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_article_and_video_profiles_share_eligible_claim_context_but_keep_independent_profiles(): void
    {
        $boundary = $this->boundary();
        $request = ['subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']], 'topic' => 'Odo 36', 'raw_input' => 'Odo 36'];
        $article = $boundary->enrich($request + ['profile' => 'article']);
        $video = $boundary->enrich($request + ['profile' => 'video']);

        self::assertSame('article', $article['profile']);
        self::assertSame('video', $video['profile']);
        self::assertSame('claim-1', $article['content']['selected_claims'][0]['claim_id']);
        self::assertSame('claim-1', $video['content']['selected_claims'][0]['claim_id']);
        self::assertSame([], $article['knowledge']['candidates']);
    }

    public function test_comprehensive_editorial_policy_is_shared_by_article_video_image_and_media_without_changing_defaults(): void
    {
        $expected = ['result_limit' => 200, 'selection_limit' => 20, 'aspect_target' => 12, 'token_budget' => 3000];
        foreach (['article', 'video', 'image', 'media'] as $profile) {
            self::assertSame($expected, SharedEnrichmentBoundary::comprehensiveEditorialPolicy($profile));
        }
        self::assertSame([], SharedEnrichmentBoundary::comprehensiveEditorialPolicy('knowledge_delta'));

        $boundary = $this->boundary();
        $base = ['subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']], 'topic' => 'Odo 36'];
        $normal = $boundary->enrich($base + ['profile' => 'article']);
        $rich = $boundary->enrich($base + ['profile' => 'article', 'comprehensive_editorial' => true]);

        self::assertSame(50, $normal['content']['retrieval']['diagnostics']['result_limit']);
        self::assertSame(200, $rich['content']['retrieval']['diagnostics']['result_limit']);
        self::assertSame(3000, $rich['content']['pack']->diagnostics['context_budget']);
    }

    public function test_prepared_subject_context_bounds_shared_content_and_sparse_content_is_local(): void
    {
        $boundary = $this->boundary();
        $result = $boundary->enrich([
            'profile' => 'article', 'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']], 'topic' => 'Odo',
            'prepared_context' => ['subject_resolution_packet' => ['canonical_subject_id' => self::SUBJECT], 'selected_related_entities' => []],
        ]);
        self::assertSame('claim-1', $result['content']['selected_claims'][0]['claim_id']);
        self::assertArrayHasKey('diagnostics', $result['content']);

        $empty = new SharedEnrichmentBoundary(
            new EditorialClaimRetrievalService(new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [])),
            new EditorialKnowledgeSelector(),
        );
        $sparse = $empty->enrich(['profile' => 'media', 'subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'topic' => 'none']);
        self::assertContains('SHARED_CONTENT_CONTEXT_SPARSE', $sparse['content']['diagnostics']);
        self::assertSame('NOT_REQUESTED', $sparse['knowledge']['status']);
    }

    public function test_shared_boundary_retrieves_supplied_needs_before_selection_without_surface_owner_change(): void
    {
        $boundary = $this->boundary();
        $need = SemanticNeed::fromArray([
            'canonical_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'facet_key' => 'configuration', 'concept_key' => 'wall', 'scope' => 'model',
            'origin' => 'USER_EXPLICIT', 'confidence' => 0.9,
        ]);
        $result = $boundary->enrich([
            'profile' => 'media', 'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'topic' => 'Odo 36', 'semantic_needs' => [$need->toArray()],
        ]);

        self::assertSame('media', $result['profile']);
        self::assertSame($need->needId(), $result['content']['retrieval']['items'][0]['need_id']);
        self::assertArrayHasKey('need_diagnostics', $result['content']['retrieval']);
    }

    public function test_shared_boundary_decomposes_registered_components_before_retrieval_for_media_surface(): void
    {
        $vocabulary = new class {
            public function isRegistered(string $facet, string $concept): bool
            {
                return $facet === 'configuration' && $concept === 'wall';
            }
        };
        $decomposer = new SemanticNeedDecomposer(new TextInputInterpreter(), $vocabulary);
        $boundary = new SharedEnrichmentBoundary(
            new EditorialClaimRetrievalService(new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [['id' => 'claim-1', 'claim_id' => 'claim-1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'facet' => 'configuration', 'text' => 'Odo 36 có vách ngăn.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE']])),
            new EditorialKnowledgeSelector(),
            null,
            null,
            null,
            $decomposer,
        );

        $result = $boundary->enrich([
            'profile' => 'media',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'raw_input' => 'Odo 36',
            'components' => [['facet_key' => 'configuration', 'concept_key' => 'wall', 'origin' => 'USER_EXPLICIT', 'scope' => 'model']],
        ]);

        self::assertCount(1, $result['content']['semantic_needs']);
        self::assertSame('configuration', $result['content']['semantic_needs'][0]['facet_key']);
        self::assertSame('claim-1', $result['content']['retrieval']['items'][0]['claim_id']);
        self::assertSame('USER_EXPLICIT', $result['content']['semantic_needs'][0]['origin']);
    }

    public function test_shared_pipeline_retrieves_ineligible_claim_but_never_selects_or_counts_it_as_exact_knowledge(): void
    {
        $boundary = new SharedEnrichmentBoundary(
            new EditorialClaimRetrievalService(new ClaimRetrievalEngine(
                static fn (array $subject): array => ['status' => 'available', 'items' => []],
                static fn (array $subject, array $neighborhood): array => [
                    ['id' => 'blocked', 'claim_id' => 'blocked', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'facet' => 'configuration', 'text' => 'Blocked fact.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'MISSING'],
                    ['id' => 'eligible', 'claim_id' => 'eligible', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'facet' => 'recognition', 'text' => 'Eligible fact.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
                ],
            )),
            new EditorialKnowledgeSelector(),
        );

        $result = $boundary->enrich([
            'profile' => 'video',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'topic' => 'fact',
        ]);

        self::assertSame(['eligible', 'blocked'], array_column($result['content']['retrieval']['items'], 'claim_id'));
        self::assertSame(['eligible'], array_column($result['content']['selected_claims'], 'claim_id'));
        self::assertSame(1, $result['content']['pack']->diagnostics['exact_selected_count']);
        self::assertSame(['blocked'], array_column($result['content']['pack']->excludedCandidates, 'claim_id'));
    }

    public function test_knowledge_delta_preserves_classifications_provenance_and_proposal_readiness(): void
    {
        $subject = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $claim = new KnowledgeClaim($claimId, 'nhk:claim:shared', 'Một claim đã có.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']], true, 3);
        $source = new Source($sourceId, 'nhk:source:shared', 'Nguồn kiểm chứng.', 'website', null, [], true, 4);
        $boundary = $this->boundary($claim, $source);

        $same = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Một claim đã có.', 'origin' => 'SOURCE', 'operation_id' => 'op-same']);
        self::assertSame(['same_claim'], $same['knowledge']['classifications']);
        self::assertFalse($same['knowledge']['proposal_ready']);

        $evidence = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Nguồn bổ sung.', 'origin' => 'SOURCE', 'operation_id' => 'op-evidence', 'context' => ['relation' => 'supports', 'claim_id' => $claimId, 'source_id' => $sourceId, 'locator' => 'p.4', 'metadata' => ['origin' => 'SOURCE']] ]);
        self::assertSame(['add_evidence'], $evidence['knowledge']['classifications']);
        self::assertTrue($evidence['knowledge']['proposal_ready']);
        self::assertSame($sourceId, $evidence['knowledge']['candidates'][0]['provenance']['source_id']);
        self::assertSame(4, $evidence['knowledge']['candidates'][0]['provenance']['source_revision']);

        foreach (['qualifies' => 'qualify', 'contradicts' => 'contradict'] as $relation => $classification) {
            $classified = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Nguồn đánh giá.', 'origin' => 'SOURCE', 'operation_id' => 'op-' . $relation, 'context' => ['relation' => $relation, 'claim_id' => $claimId, 'source_id' => $sourceId]]);
            self::assertSame([$classification], $classified['knowledge']['classifications']);
            self::assertTrue($classified['knowledge']['proposal_ready']);
        }

        $ambiguous = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Không rõ.', 'origin' => 'SOURCE', 'context' => ['ambiguous' => true]]);
        self::assertSame(['ambiguous'], $ambiguous['knowledge']['classifications']);
        self::assertFalse($ambiguous['knowledge']['proposal_ready']);
        $unsupported = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Ngoài phạm vi.', 'origin' => 'SOURCE', 'context' => ['unsupported' => true]]);
        self::assertSame(['unsupported'], $unsupported['knowledge']['classifications']);
        self::assertFalse($unsupported['knowledge']['proposal_ready']);

        $novel = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Một quan sát mới.', 'origin' => 'SOURCE', 'operation_id' => 'op-new']);
        self::assertSame(['new_claim'], $novel['knowledge']['classifications']);
        self::assertTrue($novel['knowledge']['proposal_ready']);
        self::assertSame($novel['knowledge'], $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Một quan sát mới.', 'origin' => 'SOURCE', 'operation_id' => 'op-new'])['knowledge']);
    }

    public function test_generated_prose_is_rejected_and_relations_are_not_applied(): void
    {
        $boundary = new SharedEnrichmentBoundary(new EditorialClaimRetrievalService(new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [])), new EditorialKnowledgeSelector(), null, null, static fn (array $request): array => [['id' => 'relation-candidate']]);
        $result = $boundary->enrich(['profile' => 'knowledge_delta', 'subject_id' => UuidCodec::newV7(), 'facet' => 'recognition', 'scope' => 'variant', 'observation' => 'Văn bản sinh ra.', 'origin' => 'GENERATED_ARTICLE_PROSE', 'relations' => [['target_id' => 'ignored']]]);
        self::assertContains('GENERATED_PROSE_NOT_KNOWLEDGE', $result['knowledge']['diagnostics']);
        self::assertSame('AVAILABLE', $result['relations']['readiness']['status']);
        self::assertFalse($result['relations']['readiness']['applied']);
    }

    private function boundary(?KnowledgeClaim $claim = null, ?Source $source = null): SharedEnrichmentBoundary
    {
        $engine = new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [['id' => 'claim-1', 'claim_id' => 'claim-1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE']]);
        $claims = new class($claim) implements KnowledgeRepository { public function __construct(private ?KnowledgeClaim $claim) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->claim?->canonicalId === $id ? $this->claim : null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('read-only'); } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('read-only'); } public function list(bool $includeRetired = false): array { return $this->claim ? [$this->claim] : []; } };
        $sources = new class($source) implements SourceRepository { public function __construct(private ?Source $source) {} public function findByCanonicalId(string $id): ?Source { return $this->source?->canonicalId === $id ? $this->source : null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $item): Source { throw new \LogicException('read-only'); } public function update(Source $item, int $revision): Source { throw new \LogicException('read-only'); } public function list(bool $includeRetired = false): array { return $this->source ? [$this->source] : []; } };
        $evidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Evidence { return null; } public function create(\NHK\Core\Domain\Knowledge\Evidence $item): \NHK\Core\Domain\Knowledge\Evidence { throw new \LogicException('read-only'); } public function update(\NHK\Core\Domain\Knowledge\Evidence $item, int $revision): \NHK\Core\Domain\Knowledge\Evidence { throw new \LogicException('read-only'); } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        return new SharedEnrichmentBoundary(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector(), new KnowledgeEnrichmentPlanner($claims, $evidence, $sources), new KnowledgeEnrichmentProposalFactory());
    }
}
