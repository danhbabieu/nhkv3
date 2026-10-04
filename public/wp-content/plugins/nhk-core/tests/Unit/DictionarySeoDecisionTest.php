<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionarySeoDecision;
use PHPUnit\Framework\TestCase;

final class DictionarySeoDecisionTest extends TestCase
{
    public function test_standalone_lexical_entry_is_indexable_and_self_canonical(): void
    {
        $result = (new DictionarySeoDecision())->decide('/tu-dien/con/', [['description' => 'Định nghĩa độc lập.']]);

        self::assertSame('INDEXABLE', $result['state']);
        self::assertSame('/tu-dien/con/', $result['canonical']);
        self::assertSame('index,follow', $result['robots']);
        self::assertTrue($result['sitemap']);
    }

    public function test_owner_only_entry_redirects_directly_to_one_owner(): void
    {
        $result = (new DictionarySeoDecision())->decide('/tu-dien/westminster/', [['canonical_owner' => ['url' => '/ban-nhac/westminster/']]]);

        self::assertSame('REDIRECT', $result['state']);
        self::assertSame('/ban-nhac/westminster/', $result['canonical']);
        self::assertFalse($result['sitemap']);
    }

    public function test_rich_owner_backed_entry_is_noindex_with_self_canonical(): void
    {
        $result = (new DictionarySeoDecision())->decide('/tu-dien/400-ngay/', [['description' => 'Định nghĩa giàu nội dung.', 'canonical_owner' => ['url' => '/loai-dong-ho/400-ngay/']]]);

        self::assertSame('NOINDEX', $result['state']);
        self::assertSame('/tu-dien/400-ngay/', $result['canonical']);
        self::assertSame('noindex,follow', $result['robots']);
        self::assertFalse($result['sitemap']);
    }

    public function test_multi_sense_entry_keeps_lexical_url_and_never_canonicalizes_to_first_owner(): void
    {
        $result = (new DictionarySeoDecision())->decide('/tu-dien/con/', [
            ['description' => 'Nghĩa máy.', 'canonical_owner' => ['url' => '/mau/con-may/']],
            ['description' => 'Nghĩa bút.', 'canonical_owner' => ['url' => '/linh-kien/con-but/']],
        ]);

        self::assertNotSame('REDIRECT', $result['state']);
        self::assertSame('/tu-dien/con/', $result['canonical']);
        self::assertNotSame('/mau/con-may/', $result['canonical']);
    }

    public function test_invalid_mapping_is_blocked_and_excluded_from_sitemap_and_index(): void
    {
        $result = (new DictionarySeoDecision())->decide('/tu-dien/ambiguous/', [['semantic_reference' => ['status' => 'INVALID'], 'canonical_owner' => null]]);

        self::assertSame('BLOCKED', $result['state']);
        self::assertFalse($result['sitemap']);
        self::assertSame('noindex,follow', $result['robots']);
    }
}
