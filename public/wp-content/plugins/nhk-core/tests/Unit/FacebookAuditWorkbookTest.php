<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\FacebookAudit\FacebookAuditWorkbook;
use NHK\Core\Domain\FacebookAudit\FacebookValue;
use NHK\Core\Infrastructure\FacebookAudit\NativeXlsxWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class FacebookAuditWorkbookTest extends TestCase
{
    public function testWorkbookContainsAllRequiredSheetsAndVietnameseText(): void
    {
        $path = $this->writeWorkbook();
        $zip = $this->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');

        foreach (['Tổng quan', 'Danh sách bài Fanpage', 'Danh sách bài hội nhóm', 'Bài tương tác thấp', 'Bài có rủi ro nhãn hiệu', 'Bài trùng lặp', 'Đề xuất xóa', 'Thiếu quyền truy cập', 'Chờ phê duyệt'] as $sheet) self::assertStringContainsString('name="' . $sheet . '"', $workbook);
        self::assertStringContainsString('Đồng hồ thử nghiệm', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        self::assertStringContainsString('collection_timestamp', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        @unlink($path);
    }

    public function testPostUrlIsAnExternalHyperlinkRelationshipNotAFormula(): void
    {
        $path = $this->writeWorkbook();
        $zip = $this->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $rels = (string) $zip->getFromName('xl/worksheets/_rels/sheet2.xml.rels');

        self::assertStringContainsString('<hyperlinks>', $sheet);
        self::assertStringContainsString('r:id="rId1"', $sheet);
        self::assertStringContainsString('Target="https://www.facebook.com/donghonhakho.vn/posts/p-1"', $rels);
        self::assertStringNotContainsString('<f>', $sheet);
        $zip->close();
        @unlink($path);
    }

    public function testFormulaInjectionIsEscapedAndZeroIsNotNull(): void
    {
        $path = $this->writeWorkbook([
            'page_posts' => [[
                'post_id' => 'p-danger',
                'post_url' => 'https://www.facebook.com/donghonhakho.vn/posts/p-danger',
                'published_at' => '2026-01-01',
                'content_type' => 'TEXT',
                'text' => '=HYPERLINK("https://evil.example")',
                'reaction_count' => FacebookValue::known(0),
                'comment_count' => FacebookValue::nullValue(),
                'share_count' => FacebookValue::unavailable(),
            ]],
        ]);
        $zip = $this->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');

        self::assertStringContainsString('&apos;=HYPERLINK', $sheet);
        self::assertStringContainsString('>0<', $sheet);
        self::assertStringContainsString('>NULL<', $sheet);
        self::assertStringContainsString('>UNAVAILABLE<', $sheet);
        self::assertStringNotContainsString('<f>', $sheet);
        $zip->close();
        @unlink($path);
    }

    public function testCredentialLikeOverviewKeysAreNotExported(): void
    {
        $path = $this->writeWorkbook(['overview' => ['META_ACCESS_TOKEN' => 'secret-token']]);
        $zip = $this->open($path);
        $all = '';
        for ($index = 1; $index <= 9; $index++) $all .= (string) $zip->getFromName('xl/worksheets/sheet' . $index . '.xml');

        self::assertStringNotContainsString('secret-token', $all);
        self::assertStringNotContainsString('META_ACCESS_TOKEN', $all);
        $zip->close();
        @unlink($path);
    }

    /** @param array<string,mixed> $overrides */
    private function writeWorkbook(array $overrides = []): string
    {
        $row = [
            'post_id' => 'p-1',
            'post_url' => 'https://www.facebook.com/donghonhakho.vn/posts/p-1',
            'published_at' => '2026-01-01',
            'content_type' => 'PHOTO',
            'text' => 'Đồng hồ thử nghiệm',
            'media_references' => ['media-1'],
            'reaction_count' => FacebookValue::known(0),
            'comment_count' => FacebookValue::known(2),
            'share_count' => FacebookValue::known(1),
            'video_views' => FacebookValue::nullValue(),
            'source' => 'PAGE',
            'classification' => 'KEEP',
            'reason_codes' => [],
        ];
        $input = array_replace([
            'overview' => ['PAGE_ID_VERIFIED' => 'YES', 'PAGE_POSTS_FOUND' => 1],
            'page_posts' => [$row],
            'group_posts' => [],
            'classified_rows' => [$row],
            'access' => ['page_metadata' => 'GRANTED'],
        ], $overrides);
        $sheets = FacebookAuditWorkbook::compose(
            $input['overview'],
            $input['page_posts'],
            $input['group_posts'],
            $input['classified_rows'],
            $input['access'],
        );
        $path = tempnam(sys_get_temp_dir(), 'facebook-audit-report-') . '.xlsx';
        (new NativeXlsxWriter())->write($sheets, $path);
        return $path;
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path));
        return $zip;
    }
}
