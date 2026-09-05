<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryHtmlLinker;
use PHPUnit\Framework\TestCase;

final class DictionaryHtmlLinkerTest extends TestCase
{
    public function test_links_first_longest_phrase_and_skips_existing_links_headings_and_code(): void
    {
        $html = '<h2>Ngắt chuông đêm</h2><p>Ngắt chuông đêm là cơ chế hữu ích. Ngắt chuông đêm xuất hiện lần nữa.</p><p><a href="/x/">Westminster</a> và <code>Westminster</code> rồi Westminster.</p>';
        $terms = [
            ['concept_id' => 'c1', 'label' => 'ngắt chuông', 'url' => '/tu-dien/ngat-chuong/'],
            ['concept_id' => 'c2', 'label' => 'ngắt chuông đêm', 'url' => '/tu-dien/ngat-chuong-dem/'],
            ['concept_id' => 'c3', 'label' => 'Westminster', 'url' => '/ban-nhac/westminster/'],
        ];

        $out = (new DictionaryHtmlLinker())->link($html, $terms);

        self::assertSame(1, substr_count($out, 'href="/tu-dien/ngat-chuong-dem/"'));
        self::assertSame(0, substr_count($out, 'href="/tu-dien/ngat-chuong/"'));
        self::assertSame(1, substr_count($out, 'href="/ban-nhac/westminster/"'));
        self::assertStringContainsString('<h2>Ngắt chuông đêm</h2>', $out);
        self::assertStringContainsString('<code>Westminster</code>', $out);
    }
}
