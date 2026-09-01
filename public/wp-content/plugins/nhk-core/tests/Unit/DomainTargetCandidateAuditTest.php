<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\DomainTargetCandidateAudit;
use PHPUnit\Framework\TestCase;

final class DomainTargetCandidateAuditTest extends TestCase
{
    public function test_exact_same_domain_title_and_slug_is_a_review_candidate_not_an_automatic_mapping(): void
    {
        $report = (new DomainTargetCandidateAudit())->run([
            ['type' => 'wp_post', 'legacy_id' => '10', 'legacy_type' => 'nhk_brand', 'post_name' => 'odo', 'post_title' => 'Odo'],
            ['type' => 'brand', 'canonical_uuid' => '11111111-1111-4111-8111-111111111111', 'stable_key' => 'nhk:brand:o-do', 'canonical_name' => 'Odo', 'metadata' => ['slug' => 'odo']],
        ]);

        self::assertSame(1, $report['candidate_count']);
        self::assertSame(['one' => 1], $report['by_legacy_type']['nhk_brand']);
        self::assertSame('one', $report['items'][0]['match_class']);
        self::assertSame('nhk:brand:o-do', $report['items'][0]['candidates'][0]['stable_key']);
        self::assertTrue($report['items'][0]['review']['requires_explicit_mapping']);
        self::assertTrue($report['items'][0]['review']['name_only_match_forbidden']);
        self::assertArrayNotHasKey('mapped', $report['items'][0]);
    }

    public function test_cross_domain_matches_are_not_candidates_and_duplicate_targets_are_ambiguous(): void
    {
        $report = (new DomainTargetCandidateAudit())->run([
            ['type' => 'wp_post', 'legacy_id' => '11', 'legacy_type' => 'nhk_brand', 'post_name' => 'shared', 'post_title' => 'Shared'],
            ['type' => 'wp_post', 'legacy_id' => '12', 'legacy_type' => 'nhk_model', 'post_name' => 'missing', 'post_title' => 'Missing'],
            ['type' => 'brand', 'canonical_uuid' => '22222222-2222-4222-8222-222222222222', 'stable_key' => 'brand:one', 'canonical_name' => 'Shared', 'metadata' => ['slug' => 'shared']],
            ['type' => 'brand', 'canonical_uuid' => '33333333-3333-4333-8333-333333333333', 'stable_key' => 'brand:two', 'canonical_name' => 'Shared', 'metadata' => ['slug' => 'shared']],
            ['type' => 'model', 'canonical_uuid' => '44444444-4444-4444-8444-444444444444', 'stable_key' => 'model:shared', 'canonical_name' => 'Shared', 'metadata' => ['slug' => 'shared']],
        ]);

        self::assertSame(['ambiguous' => 1], $report['by_legacy_type']['nhk_brand']);
        self::assertSame(['none' => 1], $report['by_legacy_type']['nhk_model']);
        self::assertCount(2, $report['items']);
        self::assertSame('ambiguous', $report['items'][0]['match_class']);
        self::assertSame('none', $report['items'][1]['match_class']);
    }
}
