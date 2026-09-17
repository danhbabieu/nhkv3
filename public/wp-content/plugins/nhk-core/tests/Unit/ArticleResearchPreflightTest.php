<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleResearchPreflight;
use PHPUnit\Framework\TestCase;

final class ArticleResearchPreflightTest extends TestCase
{
    public function test_post_reconcile_inventory_exposes_canonical_article_usage_ids_separately_from_representatives(): void
    {
        $articleUsageIds = ['article-usage-featured', 'article-usage-inline'];
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'subject-vedette-37', 'type' => 'variant', 'name' => 'Vedette 37']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [],
                'categories' => [['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
                'article_media' => [
                    'canonical_readback' => [
                        'media_usage' => [
                            'state' => 'VERIFIED',
                            'endpoint_type' => 'wp_post',
                            'endpoint_key' => '1:573',
                            'roles' => ['featured_primary', 'inline_primary'],
                            'usage_ids' => $articleUsageIds,
                            'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                        ],
                    ],
                    'representative_usages' => [
                        ['endpoint_type' => 'model', 'endpoint_key' => 'model-111', 'role' => 'representative', 'usage_id' => 'model-usage'],
                        ['endpoint_type' => 'classification', 'endpoint_key' => 'classification-cuckoo', 'role' => 'representative', 'usage_id' => 'classification-usage'],
                    ],
                    'media_complete' => true,
                ],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Vedette 37', ['type' => 'variant', 'name' => 'Vedette 37'], ['post_id' => 573]);

        self::assertSame($articleUsageIds, $result->mediaPlan['article_usage_ids']);
        self::assertSame('1:573', $result->mediaPlan['article_endpoint_key']);
        self::assertSame('VERIFIED', $result->mediaPlan['article_media_state']);
        self::assertNotContains('model-usage', $result->mediaPlan['article_usage_ids']);
        self::assertNotContains('classification-usage', $result->mediaPlan['article_usage_ids']);
    }

    public function test_missing_or_corrupt_article_asset_is_a_typed_blocker_even_when_representative_usage_exists(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'subject-corrupt', 'type' => 'classification', 'name' => 'Đồng hồ lỗi ảnh']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [],
                'categories' => [['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
                'article_media' => [
                    'state' => 'REVIEW_REQUIRED',
                    'media_complete' => false,
                    'canonical_readback' => [
                        'media_usage' => [
                            'state' => 'REVIEW_REQUIRED',
                            'blockers' => ['MEDIAUSAGE_INCOMPLETE'],
                        ],
                    ],
                    'representative_usages' => [
                        ['endpoint_type' => 'model', 'endpoint_key' => 'model-corrupt', 'role' => 'representative', 'usage_id' => 'model-usage-corrupt'],
                    ],
                ],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Đồng hồ lỗi ảnh', ['type' => 'classification', 'name' => 'Đồng hồ lỗi ảnh']);

        self::assertFalse($result->readyForDraft);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertSame('REVIEW_REQUIRED', $result->mediaPlan['state']);
        self::assertNotContains('model-usage-corrupt', (array) ($result->mediaPlan['article_usage_ids'] ?? []));
    }

    public function test_research_reuses_subject_and_classifies_overlap_relations_and_public_links(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'brand-1', 'type' => 'brand', 'name' => 'NHK']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [['id' => '1:2', 'title' => 'Lịch sử NHK', 'subject_ids' => ['brand-1'], 'published' => true]],
                'categories' => [['name' => 'Âm thanh cổ', 'slug' => 'am-thanh-co']],
                'knowledge' => [['id' => 'claim-1', 'subject_id' => 'brand-1', 'supported' => true, 'scope' => 'Brand']],
                'sources' => [], 'evidence' => [], 'media' => [['id' => 'media-1', 'ready' => true, 'public' => true]], 'videos' => [],
                'relations' => [
                    ['class' => 'DIRECT', 'target_id' => 'model-1', 'target_type' => 'model', 'predicate' => 'model_of', 'reason' => 'registered edge', 'public' => true],
                    ['class' => 'DERIVED', 'target_id' => 'music-1', 'target_type' => 'music', 'path' => [['predicate' => 'supports_music'], ['predicate' => 'uses_movement']], 'reason' => 'two-hop path'],
                ],
            ],
            static fn (array $candidate): array => ['eligible' => ($candidate['public'] ?? false) === true, 'route' => ($candidate['public'] ?? false) ? '/model/nhk' : null],
        );

        $result = $service->research('Lịch sử NHK', ['type' => 'brand', 'name' => 'NHK']);

        self::assertFalse($result->readyForDraft);
        self::assertSame('brand-1', $result->subjectResolution['primary']['id']);
        self::assertSame('EXISTING_CANONICAL_ARTICLE', $result->overlap['classification']);
        self::assertSame(['EXISTING_DIRECT', 'EXISTING_DERIVED'], array_column($result->relationPlan, 'classification'));
        self::assertSame('/model/nhk', $result->internalLinks[0]['route']);
        self::assertContains('EXISTING_ARTICLE_OVERLAP', $result->blockers);
    }

    public function test_ambiguity_and_unavailable_runtime_fail_closed_without_becoming_empty(): void
    {
        $ambiguous = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'ambiguous', 'candidates' => [['id' => 'a'], ['id' => 'b']]],
            static fn (array $context): array => ['status' => 'available'],
            static fn (array $candidate): array => ['eligible' => true, 'route' => '/x'],
        );
        $result = $ambiguous->research('NHK', ['name' => 'NHK']);
        self::assertFalse($result->readyForDraft);
        self::assertContains('AMBIGUOUS_SUBJECT', $result->blockers);

        $unavailable = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'a']],
            static fn (array $context): array => ['status' => 'unavailable', 'reason' => 'RUNTIME_UNAVAILABLE'],
            static fn (array $candidate): array => ['eligible' => true, 'route' => '/x'],
        );
        $unavailableResult = $unavailable->research('NHK', ['name' => 'NHK']);
        self::assertFalse($unavailableResult->readyForDraft);
        self::assertContains('RUNTIME_UNAVAILABLE', $unavailableResult->blockers);
        self::assertNotSame([], $unavailableResult->inventory['status']);
    }

    public function test_missing_category_and_placeholder_media_are_plans_not_writes_or_seo_success(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'a', 'type' => 'brand', 'name' => 'NHK']],
            static fn (array $context): array => ['status' => 'available', 'posts' => [], 'categories' => [], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [['id' => 'placeholder', 'ready' => false, 'public' => false]], 'videos' => [], 'relations' => []],
            static fn (array $candidate): array => ['eligible' => false, 'route' => null],
        );
        $result = $service->research('NHK', ['name' => 'NHK']);
        self::assertSame('CATEGORY_MISSING', $result->categoryPlan['status']);
        self::assertFalse($result->seoBlueprint['media_complete']);
        self::assertContains('CATEGORY_MISSING', $result->warnings);
    }

    public function test_text_article_without_provided_media_keeps_optional_visual_support_as_a_warning(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'a', 'type' => 'brand', 'name' => 'NHK']],
            static fn (array $context): array => ['status' => 'available', 'posts' => [], 'categories' => [['slug' => 'tri-thuc']], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [], 'article_media' => ['media_complete' => false, 'requirement' => 'OPTIONAL_VISUAL_SUPPORT']],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Lịch sử NHK', ['name' => 'NHK']);

        self::assertTrue($result->readyForDraft);
        self::assertFalse($result->seoBlueprint['media_complete']);
        self::assertContains('MEDIA_PLACEHOLDER_OR_UNAVAILABLE', $result->warnings);
        self::assertNotContains('MEDIA_PIPELINE_FAILURE', $result->blockers);
    }

    public function test_text_article_placeholder_readback_remains_non_ready(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'article-text', 'type' => 'brand', 'name' => 'NHK']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [],
                'categories' => [['slug' => 'tri-thuc']],
                'knowledge' => [],
                'sources' => [],
                'evidence' => [],
                'media' => [],
                'videos' => [],
                'relations' => [],
                'article_media' => [
                    'state' => 'PLACEHOLDER',
                    'media_complete' => false,
                    'featured_primary' => ['media_id' => 'placeholder-featured', 'placeholder' => true],
                    'inline_primary' => ['media_id' => 'placeholder-inline', 'placeholder' => true],
                    'canonical_readback' => [
                        'media_usage' => [
                            'state' => 'REVIEW_REQUIRED',
                            'endpoint_type' => 'wp_post',
                            'endpoint_key' => '1:574',
                            'roles' => [],
                            'usage_ids' => [],
                            'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                            'blockers' => ['MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING'],
                        ],
                    ],
                ],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Bài chữ không có ảnh', ['type' => 'brand', 'name' => 'NHK'], ['post_id' => 574]);

        self::assertFalse($result->mediaPlan['media_complete']);
        self::assertSame('REVIEW_REQUIRED', $result->mediaPlan['state']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_FEATURED_MISSING', array_column($result->mediaPlan['diagnostics'], 'code'));
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', array_column($result->mediaPlan['diagnostics'], 'code'));
    }

    public function test_ordinary_article_research_reads_semantic_inventory_without_creating_a_write_plan(): void
    {
        $inventoryReads = 0;
        $dictionaryPreviews = 0;
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'variant-vedette-37', 'type' => 'variant', 'name' => 'Vedette 37']],
            static function (array $context) use (&$inventoryReads): array {
                ++$inventoryReads;
                return [
                    'status' => 'available',
                    'posts' => [],
                    'categories' => [['slug' => 'tri-thuc-dong-ho']],
                    'knowledge' => [['id' => 'existing-claim', 'subject_id' => 'variant-vedette-37', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE']],
                    'sources' => [],
                    'evidence' => [],
                    'media' => [],
                    'videos' => [],
                    'relations' => [],
                ];
            },
            static fn (array $candidate): array => ['eligible' => false],
            static function (string $text, array $context) use (&$dictionaryPreviews): array {
                ++$dictionaryPreviews;
                return ['status' => 'PREVIEW', 'resolved_terms' => [], 'ambiguous_terms' => [], 'candidate_terms' => [], 'internal_link_candidates' => [], 'warnings' => [], 'blocking' => false];
            },
        );

        $result = $service->research('Vedette 37 có mặt số xanh.', ['type' => 'variant', 'name' => 'Vedette 37']);

        self::assertSame(1, $inventoryReads);
        self::assertSame(1, $dictionaryPreviews);
        self::assertSame(['existing-claim'], array_column($result->knowledgeInventory['claims'], 'id'));
        self::assertArrayNotHasKey('writes', $result->knowledgeInventory);
        self::assertArrayNotHasKey('proposals', $result->knowledgeInventory);
        self::assertNotContains('SEMANTIC_WRITE_REQUIRED', $result->blockers);
    }

    public function test_new_factual_claim_without_applied_evidence_is_a_hard_preflight_blocker(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'brand-1', 'type' => 'brand']],
            static fn (array $context): array => ['status' => 'available', 'posts' => [], 'categories' => [['slug' => 'odo']], 'knowledge' => [['id' => 'claim-1', 'new_or_modified' => true, 'evidence_status' => 'NO_EVIDENCE']], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => []],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Phương pháp Odo', ['type' => 'brand', 'name' => 'Odo']);

        self::assertContains('PUBLIC_CLAIM_EVIDENCE_REQUIRED', $result->blockers);
        self::assertFalse($result->readyForDraft);
    }

    public function test_public_claim_block_contains_exact_scope_evidence_reason_and_genuinely_narrower_rewrite(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'variant-a', 'type' => 'variant', 'name' => 'Variant A']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [], 'categories' => [['slug' => 'tri-thuc']], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
                'knowledge' => [[
                    'claim_id' => 'claim-a',
                    'text' => 'Variant A là mẫu hiếm nhất và tốt nhất.',
                    'claim_type' => 'fact',
                    'subject_id' => 'variant-a',
                    'scope' => 'variant',
                    'evidence_status' => 'NO_EVIDENCE',
                    'new_or_modified' => true,
                ]],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Variant A', ['type' => 'variant', 'name' => 'Variant A']);

        self::assertSame('HUMAN_REVIEW_REQUIRED', $result->compliance['status']);
        self::assertSame('Variant A là mẫu hiếm nhất và tốt nhất.', $result->compliance['diagnostics'][0]['claim_text']);
        self::assertSame('variant', $result->compliance['diagnostics'][0]['scope']);
        self::assertSame('NO_EVIDENCE', $result->compliance['diagnostics'][0]['evidence_status']);
        self::assertTrue($result->compliance['diagnostics'][0]['review_required']);
        self::assertStringNotContainsString('tốt nhất', strtolower((string) $result->compliance['diagnostics'][0]['suggested_rewrite']));
        self::assertStringNotContainsString('hiếm nhất', strtolower((string) $result->compliance['diagnostics'][0]['suggested_rewrite']));
    }

    public function test_persisted_article_state_is_separate_from_planning_subject_and_global_candidates(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'movement-1', 'type' => 'movement', 'name' => 'Calibre 1']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [['id' => '1:87', 'title' => 'Bản nháp', 'subject_ids' => [], 'published' => false]],
                'current_categories' => [['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'categories' => [['name' => 'Chưa phân loại', 'slug' => 'uncategorized']],
                'article_media' => [
                    'featured_primary' => ['media_id' => 'featured-1', 'placeholder' => false],
                    'inline_primary' => ['media_id' => 'placeholder-1', 'placeholder' => true],
                    'media_complete' => false,
                ],
                'knowledge' => [], 'sources' => [], 'evidence' => [],
                'media' => [['id' => 'candidate-1', 'ready' => true, 'public' => true]], 'videos' => [], 'relations' => [],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Bản nháp', ['type' => 'movement', 'name' => 'Calibre 1'], ['post_id' => 87]);

        self::assertSame('unattached_planning_candidate', $result->subjectResolution['persistence']['status']);
        self::assertSame('Tri thức đồng hồ', $result->categoryPlan['current_category']['name']);
        self::assertSame('EXISTING', $result->categoryPlan['status']);
        self::assertFalse($result->mediaPlan['media_complete']);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', array_column($result->mediaPlan['diagnostics'], 'code'));
    }

    public function test_persisted_subject_attachment_is_reported_as_attached(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'movement-1', 'type' => 'movement']],
            static fn (array $context): array => ['status' => 'available', 'posts' => [['id' => '1:87', 'subject_ids' => ['movement-1']]], 'categories' => [], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => []],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Bản nháp', ['type' => 'movement'], ['post_id' => 87]);

        self::assertSame('attached', $result->subjectResolution['persistence']['status']);
    }

    public function test_existing_default_uncategorized_does_not_hide_configured_available_category(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'variant-1', 'type' => 'variant', 'name' => 'Variant A']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [],
                'current_categories' => [['id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized', 'is_default' => true]],
                'categories' => [
                    ['id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized', 'is_default' => true],
                    ['id' => 4, 'name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho', 'is_default' => false],
                ],
                'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Variant A', ['type' => 'variant'], ['post_id' => 123]);

        self::assertSame('Tri thức đồng hồ', $result->categoryPlan['category']['name']);
        self::assertSame('EXISTING', $result->categoryPlan['status']);
        self::assertNotSame($result->categoryPlan['category']['id'], 1);
    }

    public function test_fresh_preflight_does_not_report_current_article_as_duplicate_intent(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'variant-1', 'type' => 'variant', 'name' => 'Variant A']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [['id' => '1:123', 'title' => 'Variant A', 'subject_ids' => ['variant-1'], 'published' => false]],
                'categories' => [['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Variant A', ['type' => 'variant'], ['post_id' => 123, 'planned_title' => 'Variant A']);

        self::assertSame('NO_OVERLAP', $result->overlap['classification']);
        self::assertNotContains('EXISTING_ARTICLE_OVERLAP', $result->blockers);
    }

    public function test_branch_scoped_inventory_excludes_global_media_knowledge_and_video_candidates(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [],
                'categories' => [['name' => 'Chưa phân loại', 'slug' => 'uncategorized'], ['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'knowledge' => [
                    ['id' => 'claim-1', 'subject_id' => 'classification-1', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
                    ['id' => 'claim-global', 'subject_id' => 'brand-elsewhere', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
                ],
                'media' => [
                    ['id' => 'media-global', 'subject_ids' => ['brand-elsewhere'], 'ready' => true, 'public' => true],
                ],
                'videos' => [
                    ['id' => 'video-global', 'subject_id' => 'brand-elsewhere', 'public' => true],
                ],
                'sources' => [], 'evidence' => [], 'relations' => [],
            ],
            static fn (array $candidate): array => ['eligible' => true, 'route' => '/phan-loai/dong-ho-chim-cuc-cu/'],
        );

        $result = $service->research('Đồng hồ chim cúc cu', ['type' => 'classification']);

        self::assertSame(['claim-1'], array_column($result->knowledgeInventory['claims'], 'id'));
        self::assertSame([], $result->mediaPlan['candidates']);
        self::assertFalse($result->mediaPlan['media_complete']);
        self::assertSame([], $result->videoPlan['candidates']);
        self::assertSame('Tri thức đồng hồ', $result->categoryPlan['category']['name']);
    }

    public function test_graph_branch_claims_without_metadata_subject_are_retained_by_subject_ids(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            static fn (array $context): array => [
                'status' => 'available', 'posts' => [], 'categories' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
                'knowledge' => [
                    ['id' => 'graph-claim', 'subject_id' => '', 'subject_ids' => ['classification-1'], 'evidence_status' => 'NO_EVIDENCE'],
                    ['id' => 'other-claim', 'subject_id' => 'classification-2', 'evidence_status' => 'NO_EVIDENCE'],
                ],
            ],
            static fn (array $candidate): array => ['eligible' => false],
        );

        $result = $service->research('Đồng hồ chim cúc cu', ['type' => 'classification']);

        self::assertSame(['graph-claim'], array_column($result->knowledgeInventory['claims'], 'id'));
    }

    public function test_planned_title_is_preserved_in_seo_blueprint(): void
    {
        $service = new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            static fn (array $context): array => ['status' => 'available', 'posts' => [], 'categories' => [], 'knowledge' => [], 'media' => [], 'videos' => [], 'sources' => [], 'evidence' => [], 'relations' => []],
            static fn (array $candidate): array => ['eligible' => true, 'route' => '/x'],
        );

        $result = $service->research('collector topic', ['type' => 'classification'], ['planned_title' => 'Đồng hồ chim cúc cu: checklist cho người sưu tầm']);

        self::assertSame('Đồng hồ chim cúc cu: checklist cho người sưu tầm', $result->seoBlueprint['title_intent']);
        self::assertSame('dong-ho-chim-cuc-cu-checklist-cho-nguoi-suu-tam', $result->seoBlueprint['slug_intent']);
    }
}
