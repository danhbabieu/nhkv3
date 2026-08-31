<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\DryRunService;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MigrationDryRunTest extends TestCase
{
    public function test_dry_run_reports_reason_codes_without_merging_checksum_candidates(): void
    {
        $checksum = hash('sha256', 'same');
        $report = (new DryRunService())->run([
            ['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => UuidCodec::newV7()],
            ['type' => 'media', 'stable_key' => 'front-a', 'checksum' => $checksum],
            ['type' => 'media', 'stable_key' => 'front-b', 'checksum' => $checksum],
            ['type' => 'legacy_widget', 'stable_key' => 'x'],
            ['type' => 'relation', 'source_key' => 'wp_post:1:2'],
            ['type' => 'url', 'source_path' => '/old', 'target_path' => '/new'],
        ]);
        self::assertSame(6, $report['source_count']);
        self::assertSame(4, $report['mapped']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(1, $report['duplicate_candidate']);
        self::assertSame(1, $report['invalid_relation']);
        self::assertSame(1, $report['url_mapping']);
        self::assertSame(['brand' => 1, 'media' => 2, 'legacy_widget' => 1, 'relation' => 1, 'url' => 1], $report['source_counts']);
        self::assertSame(['brand' => 1, 'media' => 2, 'url' => 1], $report['mapped_by_type']);
    }

    public function test_invalid_canonical_uuid_is_skipped_with_bounded_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => 'not-a-uuid']]);
        self::assertSame('INVALID_IDENTITY', $report['items'][0]['reason']);
    }

    public function test_domain_targeted_url_keeps_explicit_skip_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'url', 'source_path' => '/knowledge/legacy/', 'target_path' => '', 'target_reason' => 'DOMAIN_TARGETED']]);
        self::assertSame(1, $report['skipped']);
        self::assertSame(1, $report['skipped_by_reason']['DOMAIN_TARGETED']);
        self::assertSame('DOMAIN_TARGETED', $report['items'][0]['reason']);
    }

    public function test_invalid_checksum_and_non_record_are_not_silently_mapped(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'media', 'stable_key' => 'front', 'checksum' => 'not-sha256'],
            'invalid-record',
            ['type' => 'brand', 'stable_key' => 'odo', 'conflict' => true],
        ]);
        self::assertSame(0, $report['mapped']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(1, $report['conflict']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_IDENTITY']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_RECORD']);
        self::assertSame('CONFLICT_REQUIRES_REVIEW', $report['items'][2]['reason']);
    }
}
