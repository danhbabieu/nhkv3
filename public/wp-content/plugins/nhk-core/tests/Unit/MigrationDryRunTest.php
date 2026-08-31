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
    }

    public function test_invalid_canonical_uuid_is_skipped_with_bounded_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => 'not-a-uuid']]);
        self::assertSame('INVALID_IDENTITY', $report['items'][0]['reason']);
    }
}
