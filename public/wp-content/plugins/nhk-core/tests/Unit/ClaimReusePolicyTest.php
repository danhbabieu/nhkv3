<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\ClaimReusePolicy;
use PHPUnit\Framework\TestCase;

final class ClaimReusePolicyTest extends TestCase
{
    public function test_reuses_supported_equivalent_configuration_claim_at_the_same_variant_scope(): void
    {
        $claim = [
            'claim_id' => '01a06d45-aa68-7d08-b6a0-7cccb84ae75b',
            'claim_revision' => 3,
            'text' => 'Một hiện vật được Bibelot & Co mô tả là Odo n°36, serial 4583, có 10 côn/tiges, 10 búa/marteaux và hai giai điệu.',
            'subject_id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df',
            'scope' => 'variant',
            'provenance' => 'CATALOG_SUPPORTED',
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        $reused = (new ClaimReusePolicy())->find([
            'text' => 'Cấu hình 10 côn 10 búa, chơi 2 bài nhạc.',
            'subject_id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df',
            'scope' => 'variant',
        ], [$claim]);

        self::assertSame($claim, $reused);
    }

    public function test_does_not_promote_specimen_claim_to_variant_or_reuse_without_support(): void
    {
        $claim = [
            'claim_id' => 'specimen-claim',
            'claim_revision' => 1,
            'text' => 'Cấu hình 10 côn 10 búa, chơi 2 bài nhạc.',
            'subject_id' => 'specimen-4583',
            'scope' => 'specimen_observation',
            'provenance' => 'CATALOG_SUPPORTED',
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ];
        $unsupported = array_merge($claim, ['subject_id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'scope' => 'variant', 'evidence_status' => 'INSUFFICIENT_EVIDENCE']);

        $policy = new ClaimReusePolicy();

        self::assertNull($policy->find(['text' => $claim['text'], 'subject_id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'scope' => 'variant'], [$claim]));
        self::assertNull($policy->find(['text' => $claim['text'], 'subject_id' => $unsupported['subject_id'], 'scope' => 'variant'], [$unsupported]));
    }
}
