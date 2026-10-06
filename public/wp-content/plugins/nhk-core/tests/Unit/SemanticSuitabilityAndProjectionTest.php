<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaUsageReconciler, SemanticSuitabilityPolicy};
use NHK\Core\Application\Semantic\{ArticleComposer, EditorialProjectionEligibility};
use NHK\Core\Domain\Media\Media;
use PHPUnit\Framework\TestCase;

final class SemanticSuitabilityAndProjectionTest extends TestCase
{
    private const MEDIA_ID = '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3341';

    public function test_readable_media_with_wrong_subject_is_ineligible_not_complete(): void
    {
        $policy = new SemanticSuitabilityPolicy();
        $assessment = $policy->evaluateMedia(
            new Media(self::MEDIA_ID, 'media.clock', 'Clock image', 'ready', ['subject_ids' => ['subject-a']]),
            [new \NHK\Core\Domain\Media\MediaAsset(
                '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3342', self::MEDIA_ID, 'original', 'media/clock', str_repeat('a', 64), 'image/jpeg', 100, 1200, 800, 'PUBLIC'
            )],
            ['subject_ids' => ['subject-b']],
        );

        self::assertSame(SemanticSuitabilityPolicy::INELIGIBLE, $assessment['suitability']);
        self::assertFalse($assessment['valid_for_completeness']);
        self::assertSame('MEDIA_CANDIDATE_INELIGIBLE', $assessment['diagnostic']);
    }

    public function test_unknown_scope_cannot_be_auto_selected(): void
    {
        $assessment = (new SemanticSuitabilityPolicy())->evaluateMedia(
            new Media(self::MEDIA_ID, 'media.clock', 'Clock image', 'ready'),
            [new \NHK\Core\Domain\Media\MediaAsset(
                '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3342', self::MEDIA_ID, 'original', 'media/clock', str_repeat('b', 64), 'image/jpeg', 100, 1200, 800, 'PUBLIC'
            )],
            ['subject_ids' => ['subject-b']],
        );

        self::assertSame(SemanticSuitabilityPolicy::UNKNOWN, $assessment['suitability']);
        self::assertFalse($assessment['auto_select']);
        self::assertFalse($assessment['valid_for_completeness']);
    }

    public function test_unscoped_ready_media_is_not_automatic_article_coverage(): void
    {
        $assessment = (new SemanticSuitabilityPolicy())->evaluateMedia(
            new Media(self::MEDIA_ID, 'media.unscoped', 'Unscoped image', 'ready'),
            [new \NHK\Core\Domain\Media\MediaAsset(
                '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3343', self::MEDIA_ID, 'original', 'media/unscoped', str_repeat('c', 64), 'image/jpeg', 100, 2400, 1600, 'PUBLIC'
            )],
            [],
            'SYSTEM_AUTO',
            'featured_primary',
        );

        self::assertFalse($assessment['auto_select']);
        self::assertFalse($assessment['valid_for_completeness']);
        self::assertNotSame(SemanticSuitabilityPolicy::COMPATIBLE, $assessment['suitability']);
        self::assertSame('MEDIA_USAGE_SEMANTIC_MISMATCH', $assessment['diagnostic']);
    }

    public function test_visually_superior_unrelated_media_is_hard_rejected(): void
    {
        $assessment = (new SemanticSuitabilityPolicy())->evaluateMedia(
            new Media(self::MEDIA_ID, 'media.unrelated', 'Unrelated large image', 'ready', ['subject_ids' => ['subject-other']]),
            [new \NHK\Core\Domain\Media\MediaAsset(
                '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3344', self::MEDIA_ID, 'original', 'media/unrelated', str_repeat('d', 64), 'image/jpeg', 100, 6000, 4000, 'PUBLIC'
            )],
            ['subject_ids' => ['subject-target']],
            'SYSTEM_AUTO',
            'featured_primary',
        );

        self::assertSame(SemanticSuitabilityPolicy::INELIGIBLE, $assessment['suitability']);
        self::assertFalse($assessment['auto_select']);
        self::assertSame('persisted_subject_scope_mismatch', $assessment['basis']);
    }

    public function test_registered_structural_compatibility_is_distinguished_from_broad_ancestor_scope(): void
    {
        $policy = new SemanticSuitabilityPolicy();
        $compatible = $policy->evaluate([
            'availability' => SemanticSuitabilityPolicy::AVAILABLE,
            'subject_ids' => ['model-parent'],
            'compatibility_rule_registered' => true,
            'relation_class' => 'REPRESENTATIVE_COMPATIBLE',
            'relation_path' => ['variant_of'],
            'role' => 'featured_primary',
        ], ['subject_ids' => ['variant-child']]);
        $broad = $policy->evaluate([
            'availability' => SemanticSuitabilityPolicy::AVAILABLE,
            'subject_ids' => ['brand-parent'],
            'compatibility_rule_registered' => true,
            'relation_class' => 'BROAD_ANCESTOR',
            'relation_path' => ['variant_of', 'model_of'],
            'role' => 'featured_primary',
        ], ['subject_ids' => ['variant-child']]);

        self::assertSame(SemanticSuitabilityPolicy::COMPATIBLE, $compatible['suitability']);
        self::assertTrue($compatible['auto_select']);
        self::assertSame(SemanticSuitabilityPolicy::INELIGIBLE, $broad['suitability']);
        self::assertFalse($broad['auto_select']);
    }

    public function test_policy_exposes_deterministic_semantic_tier_and_rejection_reason(): void
    {
        $policy = new SemanticSuitabilityPolicy();
        $exact = $policy->evaluate([
            'availability' => SemanticSuitabilityPolicy::AVAILABLE,
            'subject_ids' => ['subject-target'],
            'role' => 'featured_primary',
        ], ['subject_ids' => ['subject-target']]);
        $unknown = $policy->evaluate([
            'availability' => SemanticSuitabilityPolicy::AVAILABLE,
            'role' => 'featured_primary',
        ], ['subject_ids' => ['subject-target']]);

        self::assertSame('EXACT_SUBJECT', $exact['semantic_tier']);
        self::assertSame('EXACT', $exact['relationship_class']);
        self::assertSame([], $exact['relation_path']);
        self::assertTrue($exact['eligible']);
        self::assertSame('UNKNOWN_SCOPE', $unknown['semantic_tier']);
        self::assertFalse($unknown['eligible']);
        self::assertSame('no_explainable_persisted_subject_basis', $unknown['basis']);
    }

    public function test_usage_reconciliation_requires_suitability_before_planning_add(): void
    {
        $result = (new MediaUsageReconciler())->plan('wp_post', '1:1', [], [[
            'role' => 'featured_primary', 'media_id' => self::MEDIA_ID, 'placement_key' => 'article:1:1:featured_primary',
        ]], static fn (): array => [
            'suitability' => SemanticSuitabilityPolicy::INELIGIBLE,
            'valid_for_completeness' => false,
            'diagnostic' => 'MEDIA_CANDIDATE_INELIGIBLE',
        ]);

        self::assertSame('OWNER_REVIEW_REQUIRED', $result['status']);
        self::assertSame('MEDIA_CANDIDATE_INELIGIBLE', $result['actions'][0]['code']);
        self::assertSame([], array_filter($result['actions'], static fn (array $action): bool => ($action['action'] ?? '') === 'ADD'));
    }

    public function test_knowledge_provenance_is_not_public_prose_without_editorial_relevance(): void
    {
        $claim = [
            'claim_id' => 'claim-source', 'revision' => 1,
            'text' => 'Nguồn tư liệu ghi nhận một chi tiết trong hồ sơ.',
            'claim_type' => 'evidence', 'editorial_relevance' => false,
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        self::assertFalse((new EditorialProjectionEligibility())->evaluate($claim)['eligible']);
        $result = (new ArticleComposer())->compose('Ghi chú biên tập.', [], [$claim]);
        self::assertSame([], $result['claim_trace']);
        self::assertStringNotContainsString('Nguồn tư liệu', $result['content']);
    }
}
