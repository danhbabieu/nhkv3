<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Media\VisualSupportIntentRegistry;
use NHK\Core\Domain\Media\VisualSupportRequirement;
use NHK\Core\Domain\Media\VisualSupportRequirementStateRegistry;
use PHPUnit\Framework\TestCase;

final class VisualSupportRequirementTest extends TestCase
{
    private const SUBJECT = '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3321';

    public function test_registry_is_generic_and_state_machine_is_closed(): void
    {
        self::assertSame(['representative', 'technical_detail', 'evidence_like_illustration', 'contextual_illustration'], VisualSupportIntentRegistry::all());
        self::assertSame(['MISSING', 'RESOLVED', 'REVIEW_REQUIRED'], VisualSupportRequirementStateRegistry::all());
        VisualSupportIntentRegistry::assertKnown('technical_detail');
        VisualSupportRequirementStateRegistry::assertKnown('MISSING');
        $this->expectException(\InvalidArgumentException::class);
        VisualSupportIntentRegistry::assertKnown('brand_specific_image');
    }

    public function test_identity_is_semantic_and_does_not_duplicate_per_consumer(): void
    {
        $first = VisualSupportRequirement::create(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail', ['consumer' => ['type' => 'article', 'key' => '101']]);
        $second = VisualSupportRequirement::create(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail', ['consumer' => ['type' => 'video', 'key' => '202']]);

        self::assertSame($first->semanticFingerprint, $second->semanticFingerprint);
        self::assertNotSame($first->canonicalId, $second->canonicalId);
        self::assertSame('MISSING', $first->state);
        self::assertNull($first->mediaId);
        self::assertSame('article', $first->context['consumer']['type']);
        self::assertArrayNotHasKey('claim_id', $first->context);
        self::assertArrayNotHasKey('evidence_id', $first->context);
        self::assertArrayNotHasKey('graph_edge_id', $first->context);
    }

    public function test_bound_requirement_preserves_scope_and_revision(): void
    {
        $requirement = VisualSupportRequirement::create(self::SUBJECT, 'specimen_observation', 'specimen_observation', 'MOVEMENT_LOGO', 'contextual_illustration');
        $resolved = $requirement->withResolution('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3322', 4, ['matched' => 'exact']);

        self::assertSame('RESOLVED', $resolved->state);
        self::assertSame(self::SUBJECT, $resolved->subjectId);
        self::assertSame('specimen_observation', $resolved->scope);
        self::assertSame('MOVEMENT_LOGO', $resolved->featureKey);
        self::assertSame(4, $resolved->mediaRevision);
        self::assertSame(2, $resolved->revision);
        self::assertSame('exact', $resolved->provenance['matched']);
    }

    public function test_ambiguous_or_unverified_candidate_can_remain_review_required(): void
    {
        $requirement = VisualSupportRequirement::create(self::SUBJECT, 'variant', 'recognition', 'DIAL', 'technical_detail');
        $review = $requirement->withReview('AMBIGUOUS_VISUAL_CANDIDATE');
        self::assertSame('REVIEW_REQUIRED', $review->state);
        self::assertSame('AMBIGUOUS_VISUAL_CANDIDATE', $review->unresolvedReason);
        self::assertNull($review->mediaId);
    }

    public function test_invalid_scope_feature_state_and_media_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VisualSupportRequirement::create(self::SUBJECT, 'model', 'not_a_facet', 'DIAL', 'technical_detail');
    }
}
