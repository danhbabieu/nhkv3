<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\OdoMediaIntegrityAuditor;
use PHPUnit\Framework\TestCase;

final class OdoMediaIntegrityAuditorTest extends TestCase
{
    public function test_detects_canonical_database_path_with_legacy_filesystem_path(): void
    {
        $report = (new OdoMediaIntegrityAuditor())->audit([
            ['attachment_id' => 83, 'attached_file' => '2026/09/odo-example.webp', 'metadata' => ['file' => '2026/09/odo-example.webp', 'sizes' => []]],
        ], ['2026/09/o-do-example.webp']);

        self::assertContains('DB_CANONICAL_FS_LEGACY', $report['categories']);
        self::assertSame('DB_CANONICAL_FS_LEGACY', $report['attachments'][0]['classification']);
    }

    public function test_detects_reverse_mismatch_and_identical_and_different_paths(): void
    {
        $report = (new OdoMediaIntegrityAuditor())->audit([
            ['attachment_id' => 1, 'attached_file' => '2026/09/o-do-one.webp', 'metadata' => ['file' => '2026/09/o-do-one.webp', 'sizes' => []]],
            ['attachment_id' => 2, 'attached_file' => '2026/09/odo-two.webp', 'metadata' => ['file' => '2026/09/odo-two.webp', 'sizes' => []]],
            ['attachment_id' => 3, 'attached_file' => '2026/09/odo-three.webp', 'metadata' => ['file' => '2026/09/odo-three.webp', 'sizes' => []]],
        ], ['2026/09/odo-one.webp', '2026/09/odo-two.webp', '2026/09/o-do-two.webp', '2026/09/odo-three.webp']);

        self::assertSame('DB_LEGACY_FS_CANONICAL', $report['attachments'][0]['classification']);
        self::assertSame('BOTH_DIFFERENT', $report['attachments'][1]['classification']);
        self::assertSame('BOTH_IDENTICAL', $report['attachments'][2]['classification']);
    }

    public function test_audit_is_read_only_and_reports_missing_derivative_or_orphans(): void
    {
        $report = (new OdoMediaIntegrityAuditor())->audit([
            ['attachment_id' => 8, 'attached_file' => '2026/09/odo.webp', 'metadata' => ['file' => '2026/09/odo.webp', 'sizes' => ['large' => ['file' => 'odo-800x600.webp']]]],
        ], ['2026/09/odo.webp', '2026/09/orphan.webp'], ['https://demo.test/wp-content/uploads/2026/09/o-do-inline.webp'], [8]);

        self::assertContains('MISSING_DERIVATIVE', $report['categories']);
        self::assertContains('ORPHAN_FILE', $report['categories']);
        self::assertSame(['https://demo.test/wp-content/uploads/2026/09/o-do-inline.webp'], $report['inline_media_urls']);
        self::assertTrue($report['read_only']);
    }
}
