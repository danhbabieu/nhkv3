<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoPublicAttachmentPolicy;
use PHPUnit\Framework\TestCase;

final class VideoPublicAttachmentPolicyTest extends TestCase
{
    public function test_public_eligibility_rejects_legacy_target_alias_without_exact_evidence_backed_shape(): void
    {
        $result = (new VideoPublicAttachmentPolicy())->blockers([['target_id' => '22222222-2222-4222-8222-222222222222']]);

        self::assertSame(['VIDEO_ABOUT_RELATION_READBACK_INVALID'], $result);
    }

    public function test_public_eligibility_distinguishes_missing_and_invalid_evidence(): void
    {
        $policy = new VideoPublicAttachmentPolicy();

        self::assertSame(['VIDEO_ABOUT_EVIDENCE_MISSING'], $policy->blockers([[
            'target_type' => 'variant', 'target_uuid' => '22222222-2222-4222-8222-222222222222', 'predicate' => 'about',
        ]]));
        self::assertSame(['VIDEO_ABOUT_EVIDENCE_INVALID'], $policy->blockers([[
            'target_type' => 'variant', 'target_uuid' => '22222222-2222-4222-8222-222222222222', 'predicate' => 'about',
            'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'not-canonical']],
        ]]));
    }
}
