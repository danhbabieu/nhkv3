<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryUnifiedRendererContractTest extends TestCase
{
    public function test_dedicated_and_delegated_templates_reference_the_same_dictionary_detail_partial(): void
    {
        $root = dirname(__DIR__, 2);
        $partial = $root . '/../../themes/nhk-v3/template-parts/dictionary/dictionary-detail.php';
        $dictionary = $root . '/../../themes/nhk-v3/dictionary.php';
        $entity = $root . '/../../themes/nhk-v3/entity.php';

        self::assertFileExists($partial);
        self::assertStringContainsString('template-parts/dictionary/dictionary-detail', (string) file_get_contents($dictionary));
        self::assertStringContainsString('template-parts/dictionary/dictionary-detail', (string) file_get_contents($entity));
        $source = (string) file_get_contents($partial);
        self::assertStringNotContainsString('if ($term', $source);
        self::assertStringContainsString("\$lexical['alternate_forms']", $source);
        self::assertStringContainsString('dictionary-form-list', $source);
        self::assertSame(1, substr_count($source, '<h1>'));

        $bridge = (string) file_get_contents($root . '/src/Infrastructure/Dictionary/DictionaryWordPressBridge.php');
        $runtime = (string) file_get_contents($root . '/src/Application/Dictionary/DictionaryRuntime.php');
        self::assertStringContainsString("\$item['_nhk_dictionary_owner_projection']", $bridge);
        self::assertSame(2, substr_count($runtime, "'_nhk_dictionary_owner_projection' => true"));
    }
}
