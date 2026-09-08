<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Projection\{ClaimClassifier, GraphProjectionPolicy, NodeProjectionProfileRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Projection\{ClaimProjectionCategory, ClaimProjectionScope, ProjectionContext, ProjectionRule};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionContractsTest extends TestCase
{
    public function test_direct_scope_keeps_the_canonical_subject(): void
    {
        $subject = UuidCodec::newV7();
        $scope = new ClaimProjectionScope($subject, $subject);
        self::assertSame($subject, $scope->canonicalSubjectUuid);
        self::assertTrue($scope->isDirect());
    }

    public function test_related_scope_requires_a_bounded_distance_and_context_is_separate(): void
    {
        $node = UuidCodec::newV7();
        $subject = UuidCodec::newV7();
        $scope = new ClaimProjectionScope($node, $subject, ClaimProjectionScope::RELATED, 1, [['predicate' => 'variant_of']]);
        $context = new ProjectionContext($node, 'model', 'Odo36', 'variant_of', $scope->graphPath);
        self::assertSame($subject, $scope->canonicalSubjectUuid);
        self::assertSame('Odo36', $context->toArray()['node_label']);
        $this->expectException(\InvalidArgumentException::class);
        new ClaimProjectionScope($node, $subject, ClaimProjectionScope::RELATED, 3);
    }

    public function test_unknown_category_is_rejected_and_profile_is_allowlisted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClaimProjectionCategory('invented_category');
    }

    public function test_classifier_is_deterministic_and_safe_for_unknown_claims(): void
    {
        $classifier = new ClaimClassifier();
        self::assertSame('music_and_strike', $classifier->classify($this->claim('Côn chữ M thường được sử dụng.')));
        self::assertSame('user_experience', $classifier->classify($this->claim('Nhiều người chơi đánh giá cao về chất âm.')));
        self::assertSame('other', $classifier->classify($this->claim('Một quan sát không có tín hiệu phân loại.')));
    }

    public function test_projection_rule_rejects_unbounded_distance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProjectionRule('variant', 'model', ['variant_of'], maxDistance: 3);
    }

    public function test_policy_allows_only_registered_structural_context(): void
    {
        $policy = new GraphProjectionPolicy();
        self::assertTrue($policy->allows('variant', 'model', 'variant_of', 'incoming', 'configuration', 1, 'APPROVED'));
        self::assertFalse($policy->allows('variant', 'brand', 'about', 'incoming', 'configuration', 1, 'APPROVED'));
        self::assertFalse($policy->allows('variant', 'model', 'variant_of', 'incoming', 'configuration', 3, 'APPROVED'));
        self::assertFalse($policy->allows('variant', 'model', 'variant_of', 'incoming', 'configuration', 1, 'PRIVATE'));
    }

    public function test_profiles_do_not_expose_product_as_a_semantic_parent(): void
    {
        $registry = new NodeProjectionProfileRegistry();
        self::assertTrue($registry->allows('specimen', 'provenance'));
        self::assertFalse($registry->allows('product', 'mechanism'));
    }

    private function claim(string $text): KnowledgeClaim
    {
        return new KnowledgeClaim(UuidCodec::newV7(), 'claim.' . substr(hash('sha256', $text), 0, 12), $text, 'fact', ['metadata' => ['knowledge_status' => 'APPROVED']]);
    }
}
