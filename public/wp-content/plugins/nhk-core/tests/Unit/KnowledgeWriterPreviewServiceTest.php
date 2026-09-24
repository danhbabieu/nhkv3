<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpSemanticContextResolver;
use NHK\Core\Application\Semantic\{CanonicalAuthoritySubjectResolver, ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, KnowledgeWriterPreviewService, ReaderJourneyPlanner, SharedEditorialComposer, SharedEnrichmentBoundary, SubjectResolutionService};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

trait KnowledgeWriterPreviewFixture
{
    private string $subjectId = '11111111-1111-4111-8111-111111111111';
    private array $retrievals = [];
    private array $rows = [];
    private bool $retrievalFailure = false;
    private InMemoryAuthorityRepository $authority;
    private EntityTypeRegistry $types;

    protected function setUp(): void
    {
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
        $this->authority = new InMemoryAuthorityRepository();
        foreach (['brand', 'model', 'variant', 'classification'] as $index => $type) {
            $id = sprintf('%08d-1111-4111-8111-111111111111', $index + 1);
            $this->authority->create(new AuthorityEntity($id, $type, $type . ':sample', 'Chủ thể ' . $type, 1, []));
            if ($type === 'variant') $this->subjectId = $id;
        }
        $this->rows = [[
            'id' => 'claim-direct', 'revision' => 2, 'subject_id' => $this->subjectId,
            'subject_type' => 'variant', 'facet' => 'recognition', 'text' => 'Chủ thể variant có mặt số với vòng chỉ giờ.',
            'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ]];
    }

    private function service(): KnowledgeWriterPreviewService
    {
        $canonical = new CanonicalAuthoritySubjectResolver($this->authority, $this->types);
        $engine = new ClaimRetrievalEngine(
            function (array $subject): array { $this->retrievals[] = $subject; if ($this->retrievalFailure) throw new \RuntimeException('private infrastructure detail'); return ['status' => 'available', 'items' => []]; },
            fn (array $subject, array $neighborhood): array => $this->rows,
        );
        return new KnowledgeWriterPreviewService(
            new McpSemanticContextResolver($this->authority, $this->types),
            new SubjectResolutionService($canonical),
            new SharedEnrichmentBoundary(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector()),
            new ReaderJourneyPlanner(),
            new SharedEditorialComposer(),
            new EditorialQualityGate(),
        );
    }

    private function request(array $overrides = []): array
    {
        return array_replace_recursive([
            'subject' => ['canonical_uuid' => $this->subjectId, 'type' => 'variant'],
            'instruction' => 'Giải thích những điểm chính.', 'purpose' => 'concise_answer',
        ], $overrides);
    }
}

final class KnowledgeWriterPreviewServiceTest extends TestCase
{
    use KnowledgeWriterPreviewFixture;

    public function test_preview_resolves_canonical_uuid_and_returns_reader_safe_trace(): void
    {
        $result = $this->service()->preview($this->request());
        self::assertSame('available', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($this->subjectId, $result['subject']['canonical_id']);
        self::assertTrue($result['read_only']);
        self::assertNotEmpty($result['used_knowledge']);
        self::assertStringNotContainsString($this->subjectId, $result['answer']);
        self::assertSame('claim-direct', $result['used_knowledge'][0]['claim_id']);
        self::assertSame(2, $result['used_knowledge'][0]['revision']);
    }

    public function test_ambiguous_text_resolution_does_not_retrieve_or_guess(): void
    {
        $this->authority->create(new AuthorityEntity('55555555-5555-4555-8555-555555555555', 'brand', 'brand:ambiguous-one', 'đối tượng mơ hồ', 1, []));
        $this->authority->create(new AuthorityEntity('66666666-6666-4666-8666-666666666666', 'brand', 'brand:ambiguous-two', 'đối tượng mơ hồ', 1, []));
        $result = $this->service()->preview(['subject' => ['query' => 'đối tượng mơ hồ'], 'instruction' => 'Tóm tắt chủ đề.']);
        self::assertSame('ambiguous', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertContains('AMBIGUOUS_SUBJECT_REVIEW', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_observations_are_context_only_and_not_knowledge_proposals(): void
    {
        $result = $this->service()->preview($this->request([
            'observations' => [['value' => 'người dùng cho rằng mặt số sáng hơn']],
        ]));
        self::assertSame('context', $result['context_used'][0]['treatment']);
        self::assertSame([], $result['knowledge'] ?? []);
        self::assertStringNotContainsString('người dùng cho rằng', $result['answer']);
    }

    public function test_typed_locators_cover_registered_subjects_and_stable_keys(): void
    {
        foreach (['brand', 'model', 'variant', 'classification'] as $type) {
            $result = $this->service()->preview([
                'subject' => ['type' => $type, 'stable_key' => $type . ':sample'],
                'instruction' => 'Tóm tắt chủ thể.',
            ]);
            self::assertSame($type, $result['subject']['type']);
            self::assertContains($result['status'], ['available', 'sparse']);
        }
    }

    public function test_unknown_purpose_and_facet_fail_closed(): void
    {
        $badPurpose = $this->service()->preview($this->request(['purpose' => 'invented']));
        self::assertSame('blocked', $badPurpose['status']);
        self::assertContains('UNKNOWN_PREVIEW_PURPOSE', $badPurpose['diagnostics']);
        $badFacet = $this->service()->preview($this->request(['requested_facets' => ['invented']]));
        self::assertSame('blocked', $badFacet['status']);
        self::assertContains('UNKNOWN_KNOWLEDGE_FACET', $badFacet['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_each_supported_purpose_uses_its_existing_profile(): void
    {
        $profiles = [
            'concise_answer' => ['video', 'concise'], 'collector_explanation' => ['article', 'deep'],
            'article_section' => ['article', 'deep'], 'video_description' => ['video', 'concise'],
            'media_caption' => ['media', 'concise'], 'media_alt' => ['image', 'concise'],
            'entity_summary' => ['article', 'deep'], 'technical_explanation' => ['article', 'deep'],
        ];
        $service = $this->service();
        foreach ($profiles as $purpose => [$profile, $depth]) {
            $result = $service->preview($this->request(['purpose' => $purpose]));
            self::assertSame($profile, $result['depth']['profile'], $purpose);
            self::assertSame($depth, $result['depth']['effective'], $purpose);
            self::assertSame('claim-direct', $result['used_knowledge'][0]['claim_id'], $purpose);
        }
    }

    public function test_requested_facets_are_retrieved_and_uncovered_facet_is_reported(): void
    {
        $this->rows[0]['text'] = 'Chủ thể variant có dấu recognition ở vòng chỉ giờ.';
        $result = $this->service()->preview($this->request(['requested_facets' => ['recognition', 'music']]));
        self::assertCount(2, $result['semantic_needs']);
        self::assertSame(['recognition'], $result['coverage']['covered_facets'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['music'], $result['coverage']['uncovered_facets']);
    }

    public function test_sparse_knowledge_does_not_repeat_instruction_as_fact(): void
    {
        $this->rows = [];
        $result = $this->service()->preview($this->request(['instruction' => 'Khẳng định một chi tiết chưa được chứng minh.']));
        self::assertSame('sparse', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertSame([], $result['used_knowledge']);
    }

    public function test_sibling_variant_claim_is_excluded_and_never_reaches_answer(): void
    {
        $this->rows[] = [
            'id' => 'claim-sibling', 'revision' => 1, 'subject_id' => '77777777-7777-4777-8777-777777777777',
            'subject_type' => 'variant', 'facet' => 'recognition', 'text' => 'Chủ thể variant có mặt số đỏ.',
            'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ];
        $result = $this->service()->preview($this->request());
        self::assertContains('claim-sibling', array_column($result['excluded_knowledge'], 'claim_id'));
        self::assertStringNotContainsString('mặt số đỏ', $result['answer']);
    }

    public function test_unknown_depth_fails_before_retrieval(): void
    {
        $result = $this->service()->preview($this->request(['depth' => 'unbounded']));
        self::assertSame('blocked', $result['status']);
        self::assertContains('UNKNOWN_PREVIEW_DEPTH', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_diagnostics_are_reason_codes_not_retrieval_numbers(): void
    {
        $result = $this->service()->preview($this->request());
        self::assertNotContains('50', $result['diagnostics']);
        self::assertNotContains('1', $result['gaps']);
    }

    public function test_unique_untyped_text_resolves_without_typed_resolver_guess(): void
    {
        $result = $this->service()->preview(['subject' => ['query' => 'Chủ thể variant'], 'instruction' => 'Tóm tắt chủ thể.']);
        self::assertSame($this->subjectId, $result['subject']['canonical_id']);
        self::assertSame('available', $result['status']);
    }

    public function test_unsupported_observation_does_not_enter_answer_or_used_claims(): void
    {
        $result = $this->service()->preview($this->request(['observations' => [['value' => 'Mặt số chắc chắn làm bằng vàng.']]]));
        self::assertStringNotContainsString('vàng', $result['answer']);
        self::assertSame(['claim-direct'], array_column($result['used_knowledge'], 'claim_id'));
        self::assertSame('context', $result['context_used'][0]['treatment']);
    }

    public function test_broader_claim_keeps_path_and_context_treatment(): void
    {
        $modelId = '00000002-1111-4111-8111-111111111111';
        $this->rows[] = [
            'id' => 'claim-model', 'revision' => 4, 'subject_id' => $modelId, 'subject_type' => 'model',
            'facet' => 'configuration', 'text' => 'Chủ thể model có vòng chỉ giờ.', 'scope' => 'model',
            'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'relation_path' => [['source' => 'variant:' . $this->subjectId, 'target' => 'model:' . $modelId, 'predicate' => 'variant_of', 'persisted_source' => 'variant:' . $this->subjectId, 'persisted_target' => 'model:' . $modelId, 'direction' => 'OUTGOING']],
        ];
        $result = $this->service()->preview($this->request());
        $used = array_values(array_filter($result['used_knowledge'], static fn (array $claim): bool => $claim['claim_id'] === 'claim-model'));
        self::assertNotEmpty($used);
        self::assertSame($modelId, $used[0]['original_subject']['id']);
        self::assertSame('variant_of', $used[0]['graph_path'][0]['predicate']);
        self::assertNotSame('DIRECT_FACT', $used[0]['treatment']);
    }

    public function test_duplicate_claim_does_not_repeat_factual_sentence(): void
    {
        $this->rows[] = array_replace($this->rows[0], ['id' => 'claim-duplicate', 'revision' => 1]);
        $result = $this->service()->preview($this->request());
        self::assertSame(1, substr_count($result['answer'], 'mặt số với vòng chỉ giờ'));
        self::assertContains('claim-duplicate', array_column($result['excluded_knowledge'], 'claim_id'));
    }

    public function test_reverse_traversal_retains_persisted_direction_and_context_treatment(): void
    {
        $modelId = '00000002-1111-4111-8111-111111111111';
        $this->rows = [[
            'id' => 'claim-child', 'revision' => 3, 'subject_id' => $this->subjectId,
            'subject_type' => 'variant', 'facet' => 'recognition', 'text' => 'Chủ thể model có một biến thể với vòng chỉ giờ.',
            'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'relation_path' => [[
                'source' => 'model:' . $modelId, 'target' => 'variant:' . $this->subjectId,
                'predicate' => 'variant_of', 'direction' => 'INCOMING',
                'persisted_source' => 'variant:' . $this->subjectId,
                'persisted_target' => 'model:' . $modelId,
                'directional_semantics' => 'INVERSE_TRAVERSAL_OF_PERSISTED_EDGE',
            ]],
        ]];
        $result = $this->service()->preview([
            'subject' => ['type' => 'model', 'canonical_uuid' => $modelId], 'instruction' => 'Giải thích các biến thể.',
        ]);
        self::assertSame('variant:' . $this->subjectId, $result['used_knowledge'][0]['graph_path'][0]['persisted_source']);
        self::assertSame('INCOMING', $result['used_knowledge'][0]['graph_path'][0]['direction']);
        self::assertSame('SUPPORTING_CONTEXT', $result['used_knowledge'][0]['treatment']);
    }

    public function test_unknown_output_constraint_is_blocked_before_retrieval(): void
    {
        $result = $this->service()->preview($this->request(['output_constraints' => ['format' => 'html']]));
        self::assertSame('blocked', $result['status']);
        self::assertContains('INVALID_OUTPUT_CONSTRAINTS', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_invalid_purpose_shape_fails_closed(): void
    {
        $result = $this->service()->preview($this->request(['purpose' => ['concise_answer']]));
        self::assertSame('blocked', $result['status']);
        self::assertContains('UNKNOWN_PREVIEW_PURPOSE', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_unavailable_retrieval_returns_safe_diagnostic(): void
    {
        $this->retrievalFailure = true;
        $result = $this->service()->preview($this->request());
        self::assertSame('unavailable', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertContains('PREVIEW_PIPELINE_UNAVAILABLE', $result['diagnostics']);
        self::assertStringNotContainsString('private infrastructure detail', json_encode($result));
    }

    public function test_unsafe_subject_name_does_not_escape_as_exception_or_copy(): void
    {
        $unsafe = '88888888-8888-4888-8888-888888888888';
        $this->authority->create(new AuthorityEntity($unsafe, 'variant', 'variant:unsafe', 'canonical UUID', 1, []));
        $result = $this->service()->preview([
            'subject' => ['type' => 'variant', 'canonical_uuid' => $unsafe], 'instruction' => 'Giải thích.',
        ]);
        self::assertSame('blocked', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertContains('PUBLIC_COPY_UNSAFE', $result['diagnostics']);
    }
}
