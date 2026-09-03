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
}
