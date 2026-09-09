<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleResearchPreflight;
use PHPUnit\Framework\TestCase;

final class ArticleResearchPreflightTest extends TestCase
{
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
