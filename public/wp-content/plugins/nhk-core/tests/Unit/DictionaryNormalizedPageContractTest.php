<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryDetailPresentationComposer;
use PHPUnit\Framework\TestCase;

final class DictionaryNormalizedPageContractTest extends TestCase
{
    public function test_detail_partial_has_one_delegated_owner_notice_and_honest_status_summary(): void
    {
        $root = dirname(__DIR__, 4);
        $detail = (string) file_get_contents($root . '/themes/nhk-v3/template-parts/dictionary/dictionary-detail.php');
        $style = (string) file_get_contents($root . '/themes/nhk-v3/dictionary.css');

        self::assertSame(1, substr_count($detail, 'dictionary-canonical-owner'));
        self::assertStringContainsString('dictionary-data-status', $detail);
        self::assertStringContainsString('Chưa có dữ liệu công khai liên quan', $detail);
        self::assertStringContainsString('Hiện chưa thể truy vấn', $detail);
        self::assertStringContainsString('aria-live="polite"', $detail);
        self::assertStringContainsString('.dictionary-canonical-owner{', $style);
        self::assertStringContainsString('.dictionary-data-status{', $style);
        self::assertStringNotContainsString('UNAVAILABLE_IMPLEMENTATION_GAP', $detail);
        self::assertStringNotContainsString("['source_id']", $detail);
        self::assertStringNotContainsString("['revision']", $detail);
    }

    public function test_con_hoa_thi_example_remains_delegated_to_component_owner_and_non_indexable(): void
    {
        $packet = (new DictionaryDetailPresentationComposer())->compose([
            'status' => 'READY',
            'item' => [
                'title' => 'Côn hoa thị',
                'description' => 'Tên gọi đang được biên tập.',
                'url' => '/tu-dien/con-hoa-thi/',
                'senses' => [[
                    'title' => 'Côn hoa thị',
                    'description' => 'Tên gọi đang được biên tập.',
                    'semantic_reference' => [
                        'source' => 'MAPPING',
                        'status' => 'AVAILABLE',
                        'type' => 'component',
                        'id' => 'component-read-back',
                    ],
                    'canonical_owner' => [
                        'type' => 'component',
                        'id' => 'component-read-back',
                        'title' => 'Côn hoa thị',
                        'url' => '/linh-kien/con-hoa-thi/',
                    ],
                    'knowledge' => ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'items' => []],
                    'media' => ['status' => 'AVAILABLE_EMPTY', 'items' => []],
                ]],
            ],
            'seo' => [
                'state' => 'NOINDEX',
                'canonical' => '/tu-dien/con-hoa-thi/',
                'indexable' => false,
            ],
        ], [
            'mode' => 'delegated',
            'canonical_url' => '/linh-kien/con-hoa-thi/',
        ]);

        self::assertSame('DELEGATED', $packet['route']['mode']);
        self::assertSame('/linh-kien/con-hoa-thi/', $packet['route']['canonical_url']);
        self::assertFalse($packet['seo']['indexable']);
        self::assertSame('UNAVAILABLE', $packet['knowledge']['status']);
        self::assertSame('AVAILABLE_EMPTY', $packet['media']['status']);
    }
}
