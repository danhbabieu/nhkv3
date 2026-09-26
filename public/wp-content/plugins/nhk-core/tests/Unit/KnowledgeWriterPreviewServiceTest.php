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
    private $readProbe = null;
    private $ownerProbe = null;
    private InMemoryAuthorityRepository $authority;
    private EntityTypeRegistry $types;

    protected function setUp(): void
    {
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
        $this->authority = new InMemoryAuthorityRepository();
        foreach (['brand', 'model', 'variant', 'classification'] as $index => $type) {
            $id = sprintf('%08d-1111-4111-8111-111111111111', $index + 1);
            $payload = $type === 'variant' ? ['aliases' => ['Odo 36/10']] : [];
            $this->authority->create(new AuthorityEntity($id, $type, $type . ':sample', 'Chủ thể ' . $type, 1, $payload));
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
            function (array $subject): array { $this->retrievals[] = $subject; if ($this->readProbe !== null) ($this->readProbe)(); if ($this->ownerProbe !== null) $this->ownerProbe->read(); if ($this->retrievalFailure) throw new \RuntimeException('private infrastructure detail'); return ['status' => 'available', 'items' => []]; },
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

    public function test_name_locator_uses_exact_match_across_registered_subject_types(): void
    {
        $odo = $this->subjectId;
        $this->rows = [[
            'id' => 'odo-claim', 'revision' => 2, 'subject_id' => $odo, 'subject_type' => 'variant',
            'facet' => 'recognition', 'text' => 'Chủ thể variant có mặt số với vòng chỉ giờ.', 'scope' => 'variant',
            'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ]];

        $result = $this->service()->preview([
            'subject' => ['type' => 'model', 'name' => 'Odo 36/10', 'query' => 'Odo 36/10'],
            'instruction' => 'Tóm tắt điểm nhận diện.', 'requested_facets' => ['recognition'],
        ]);

        self::assertCount(1, $this->retrievals, json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertNotContains('SUBJECT_NOT_FOUND', $result['diagnostics'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($odo, $result['subject']['canonical_id']);
        self::assertNotEmpty($result['used_knowledge'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertNotSame('', $result['answer']);
    }

    public function test_freeform_input_resolves_subject_and_keeps_detail_terms_for_retrieval(): void
    {
        $base = '77777777-1111-4111-8111-111111111111';
        $this->authority->create(new AuthorityEntity($base, 'variant', 'variant:odo.36.10', 'Odo 36/10', 1, []));
        foreach ([
            ['configuration', 'Cấu hình dùng côn M.'],
            ['recognition', 'Mặt số nổi là một đặc điểm nhận diện.'],
        ] as $index => [$facet, $text]) {
            $this->rows[] = [
                'id' => 'freeform-' . $index, 'revision' => 1, 'subject_id' => $base, 'subject_type' => 'variant',
                'facet' => $facet, 'text' => $text, 'scope' => 'variant',
                'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ];
        }

        $result = $this->service()->preview([
            'subject' => ['query' => 'Odo 36/10 côn M mặt số nổi'],
            'instruction' => 'Tra cứu rồi viết lại.',
            'purpose' => 'collector_explanation',
            'depth' => 'deep',
            'requested_facets' => ['configuration', 'recognition'],
        ]);

        self::assertSame('available', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($base, $result['subject']['canonical_id']);
        self::assertSame(['configuration', 'recognition'], $result['coverage']['covered_facets']);
        self::assertStringContainsString('côn M', $result['answer']);
        self::assertStringContainsString('Mặt số nổi', $result['answer']);
    }

    public function test_qualified_base_variant_name_does_not_match_specialized_descendants(): void
    {
        $base = '88888888-1111-4111-8111-111111111111';
        $specialized = '88888888-2222-4222-8222-222222222222';
        $this->authority->create(new AuthorityEntity($base, 'variant', 'variant:test.36.10', 'Đồng hồ Test 36/10', 1, []));
        $this->authority->create(new AuthorityEntity($specialized, 'variant', 'variant:test.36.10.two-tune', 'Đồng hồ Test 36/10 hai bài', 1, []));
        $this->rows = [[
            'id' => 'base-claim', 'revision' => 1, 'subject_id' => $base, 'subject_type' => 'variant',
            'facet' => 'recognition', 'text' => 'Chủ thể cơ sở có dấu hiệu nhận diện riêng.',
            'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ]];

        $result = $this->service()->preview([
            'subject' => ['query' => 'Test 36/10'],
            'instruction' => 'Tóm tắt chủ thể.',
        ]);

        self::assertSame('available', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($base, $result['subject']['canonical_id']);
    }

    public function test_direct_subject_claim_is_not_rejected_only_for_missing_topic_word_overlap(): void
    {
        $w64 = '77777777-7777-4777-8777-777777777777';
        $this->authority->create(new AuthorityEntity($w64, 'variant', 'nhk:variant:junghans.w64', 'Junghans W64', 1, []));
        $this->rows = [[
            'id' => 'w64-claim', 'revision' => 2, 'subject_id' => $w64, 'subject_type' => 'variant',
            'facet' => 'configuration', 'text' => 'Cấu hình năm côn đồng bạch.', 'scope' => 'variant',
            'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ]];

        $result = $this->service()->preview([
            'subject' => ['type' => 'variant', 'name' => 'Junghans W64', 'query' => 'Junghans W64'],
            'instruction' => 'Tóm tắt chủ thể.',
        ]);

        self::assertSame('available', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['w64-claim'], array_column($result['used_knowledge'], 'claim_id'));
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

    public function test_each_purpose_returns_a_deterministic_reader_safe_preview(): void
    {
        $purposes = ['concise_answer', 'collector_explanation', 'article_section', 'video_description', 'media_caption', 'media_alt', 'entity_summary', 'technical_explanation'];
        foreach ($purposes as $purpose) {
            $request = $this->request(['purpose' => $purpose]);
            $first = $this->service()->preview($request);
            $second = $this->service()->preview($request);

            self::assertSame(json_encode($first, JSON_UNESCAPED_UNICODE), json_encode($second, JSON_UNESCAPED_UNICODE), $purpose);
            self::assertTrue($first['read_only'], $purpose);
            self::assertSame('available', $first['status'], $purpose);
            self::assertNotSame('', $first['answer'], $purpose);
            self::assertStringNotContainsString('claim_id', strtolower($first['answer']), $purpose);
            self::assertStringNotContainsString('provenance', strtolower($first['answer']), $purpose);
        }
    }

    public function test_requested_facets_are_retrieved_and_uncovered_facet_is_reported(): void
    {
        $result = $this->service()->preview($this->request(['requested_facets' => ['recognition', 'music']]));
        self::assertCount(2, $result['semantic_needs']);
        self::assertSame(['recognition'], $result['coverage']['covered_facets'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['music'], $result['coverage']['uncovered_facets']);
        self::assertSame('partial', $result['coverage']['status']);
        $complete = $this->service()->preview($this->request(['requested_facets' => ['recognition']]));
        self::assertSame('complete', $complete['coverage']['status']);
    }

    public function test_writer_collects_broad_multi_facet_context_before_composition(): void
    {
        $facets = ['identity', 'chronology', 'recognition', 'configuration', 'movement', 'music', 'component', 'provenance'];
        $this->rows = [];
        foreach ($facets as $index => $facet) {
            $this->rows[] = [
                'id' => 'claim-' . $facet, 'revision' => 1, 'subject_id' => $this->subjectId,
                'subject_type' => 'variant', 'facet' => $facet,
                'text' => 'Thông tin ' . ($index + 1) . ' thuộc khía cạnh ' . $facet . '.',
                'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ];
        }

        $result = $this->service()->preview($this->request([
            'requested_facets' => $facets,
            'instruction' => 'Tra cứu đầy đủ các khía cạnh rồi viết lại thành nội dung liền mạch.',
        ]));

        self::assertSame('available', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($facets, $result['coverage']['covered_facets']);
        self::assertCount(count($facets), $result['used_knowledge']);
        self::assertSame([], $result['coverage']['uncovered_facets']);
    }

    public function test_sparse_knowledge_does_not_repeat_instruction_as_fact(): void
    {
        $this->rows = [];
        $result = $this->service()->preview($this->request(['instruction' => 'Khẳng định một chi tiết chưa được chứng minh.']));
        self::assertSame('sparse', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertSame([], $result['used_knowledge']);
    }

    public function test_suppressed_answer_has_no_used_claims_or_covered_facets(): void
    {
        $result = $this->service()->preview($this->request([
            'requested_facets' => ['recognition'], 'output_constraints' => ['max_chars' => 1],
        ]));
        self::assertSame('', $result['answer']);
        self::assertSame([], $result['used_knowledge']);
        self::assertSame([], $result['coverage']['covered_facets']);
        self::assertSame(['recognition'], $result['coverage']['uncovered_facets']);
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

    public function test_incompatible_depth_fails_before_retrieval(): void
    {
        $result = $this->service()->preview($this->request(['depth' => 'deep']));
        self::assertSame('blocked', $result['status']);
        self::assertContains('INCOMPATIBLE_PREVIEW_DEPTH', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_control_metadata_in_claim_text_blocks_reader_answer(): void
    {
        $this->rows[0]['text'] .= ' source_id=private-source-17 evidence_id=evidence-local-4 proposal_id=review-9.';
        $result = $this->service()->preview($this->request());
        self::assertSame('blocked', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertSame([], $result['used_knowledge']);
        self::assertContains('PUBLIC_COPY_UNSAFE', $result['diagnostics']);
    }

    public function test_plural_and_provenance_control_labels_never_reach_reader_answer(): void
    {
        foreach (['source_ids', 'evidence_ids', 'provenance_references'] as $label) {
            $this->rows[0]['text'] = 'Chủ thể variant có mặt số với vòng chỉ giờ. ' . $label . '=private-source-17';
            $result = $this->service()->preview($this->request());
            self::assertNotSame('available', $result['status'], $label);
            self::assertSame('', $result['answer'], $label);
            self::assertSame([], $result['used_knowledge'], $label);
            self::assertContains('PUBLIC_COPY_UNSAFE', $result['diagnostics'], $label);
        }
    }

    public function test_non_uuid_evidence_identifier_and_control_payload_cannot_be_rendered(): void
    {
        $this->rows[0]['evidence_ids'] = ['private-evidence-42'];
        $this->rows[0]['text'] .= ' private-evidence-42';
        $result = $this->service()->preview($this->request());
        self::assertSame('blocked', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertSame([], $result['used_knowledge']);

        $this->rows[0]['text'] = 'Chủ thể variant có mặt số với vòng chỉ giờ. source: hidden-record-7';
        $result = $this->service()->preview($this->request());
        self::assertSame('blocked', $result['status']);
        self::assertSame('', $result['answer']);
    }

    public function test_conflicting_explicit_locators_do_not_retrieve(): void
    {
        $other = '77777777-7777-4777-8777-777777777777';
        $this->authority->create(new AuthorityEntity($other, 'variant', 'variant:other', 'Biến thể khác', 1, []));
        $result = $this->service()->preview($this->request(['subject' => [
            'canonical_uuid' => $this->subjectId, 'type' => 'variant', 'stable_key' => 'variant:other',
        ]]));
        self::assertSame('blocked', $result['status']);
        self::assertSame('', $result['answer']);
        self::assertContains('SUBJECT_LOCATOR_CONFLICT', $result['diagnostics']);
        self::assertSame([], $this->retrievals);
    }

    public function test_missing_or_contradictory_name_locator_does_not_fall_back_to_uuid(): void
    {
        foreach (['Không có chủ thể này', 'Chủ thể model'] as $name) {
            $result = $this->service()->preview($this->request(['subject' => [
                'canonical_uuid' => $this->subjectId, 'type' => 'variant', 'query' => $name,
            ]]));
            self::assertSame('blocked', $result['status']);
            self::assertContains('SUBJECT_LOCATOR_CONFLICT', $result['diagnostics']);
        }
        self::assertSame([], $this->retrievals);
    }

    public function test_requested_facet_does_not_override_subject_applicability(): void
    {
        $this->rows[0]['subject_id'] = '77777777-7777-4777-8777-777777777777';
        $result = $this->service()->preview($this->request(['requested_facets' => ['recognition']]));
        self::assertSame([], $result['used_knowledge']);
        self::assertContains('claim-direct', array_column($result['excluded_knowledge'], 'claim_id'));
    }

    public function test_oversized_or_malformed_context_and_query_fail_before_retrieval(): void
    {
        foreach ([
            ['observations' => [['value' => str_repeat('a', 5000)]]],
            ['observations' => [['value' => ['nested']]]],
            ['observations' => array_fill(0, 13, ['value' => 'Ghi chú'])],
            ['subject' => ['query' => str_repeat('x', 1000)]],
        ] as $input) {
            $result = $this->service()->preview($this->request($input));
            self::assertSame('blocked', $result['status']);
            self::assertSame([], $result['context_used']);
        }
        self::assertSame([], $this->retrievals);
    }

    public function test_diagnostic_projection_is_bounded_and_code_only(): void
    {
        $method = new \ReflectionMethod(KnowledgeWriterPreviewService::class, 'reasonCodes');
        $codes = array_map(static fn (int $i): string => sprintf('REASON_%02d', $i), range(0, 49));
        $result = $method->invoke($this->service(), [...$codes, 'secret=value', str_repeat('X', 100)]);
        self::assertCount(30, $result);
        self::assertSame(array_slice($codes, 0, 30), $result);
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

    public function test_broader_context_cannot_satisfy_requested_exact_facet_coverage(): void
    {
        $modelId = '00000002-1111-4111-8111-111111111111';
        $this->rows = [[
            'id' => 'claim-broader-context', 'revision' => 4, 'subject_id' => $modelId, 'subject_type' => 'model',
            'facet' => 'recognition', 'text' => 'Chủ thể model có vòng chỉ giờ.', 'scope' => 'model',
            'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'relation_path' => [['source' => 'variant:' . $this->subjectId, 'target' => 'model:' . $modelId, 'predicate' => 'variant_of', 'persisted_source' => 'variant:' . $this->subjectId, 'persisted_target' => 'model:' . $modelId, 'direction' => 'OUTGOING']],
        ]];

        $result = $this->service()->preview($this->request(['requested_facets' => ['recognition']]));

        self::assertSame([], $result['coverage']['covered_facets']);
        self::assertSame(['recognition'], $result['coverage']['uncovered_facets']);
        self::assertSame('sparse', $result['coverage']['status']);
        self::assertSame([], $result['used_knowledge']);
    }

    public function test_quality_projection_exposes_existing_grounding_repetition_and_specificity_dimensions(): void
    {
        $result = $this->service()->preview($this->request());
        $dimensions = $result['quality']['dimensions'];

        foreach (['factual_grounding', 'scope', 'evidence', 'traceability', 'factual_safety', 'information_gain', 'redundancy', 'reader_journey', 'public_language', 'editorial_quality'] as $dimension) {
            self::assertArrayHasKey($dimension, $dimensions);
            self::assertContains($dimensions[$dimension]['status'], ['READY', 'INCOMPLETE', 'BLOCKED']);
        }
        self::assertArrayHasKey('readiness', $result['quality']);
        self::assertContains($result['quality']['readiness'], ['READY', 'INCOMPLETE', 'BLOCKED']);
    }

    public function test_duplicate_claim_does_not_repeat_factual_sentence(): void
    {
        $this->rows[] = array_replace($this->rows[0], ['id' => 'claim-duplicate', 'revision' => 1]);
        $result = $this->service()->preview($this->request());
        self::assertSame(1, substr_count($result['answer'], 'mặt số với vòng chỉ giờ'));
        self::assertContains('claim-duplicate', array_column($result['excluded_knowledge'], 'claim_id'));
        self::assertNotContains('claim-duplicate', array_column($result['used_knowledge'], 'claim_id'));
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
