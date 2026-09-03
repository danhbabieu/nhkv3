<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedLivingKnowledgeDomainTest extends TestCase
{
    public function test_profile_accepts_registered_reusable_facet_and_narrow_scope(): void
    {
        $profile = new KnowledgeFacetProfile('recognition', 'specimen_observation');

        self::assertSame('recognition', $profile->facet);
        self::assertSame('specimen_observation', $profile->scope);
    }

    public function test_profile_rejects_unknown_facet_or_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KnowledgeFacetProfile('invented', 'model');
    }

    public function test_candidate_cannot_be_marked_as_evidence_when_generated(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate(
            'new_claim',
            UuidCodec::newV7(),
            new KnowledgeFacetProfile('music', 'movement'),
            'Sonodo được ghi nhận trong ngữ cảnh máy 24.',
            ['origin' => 'synthesis'],
            true,
        );

        self::assertFalse($candidate->isEvidence);
        self::assertSame('new_claim', $candidate->classification);
    }
}
