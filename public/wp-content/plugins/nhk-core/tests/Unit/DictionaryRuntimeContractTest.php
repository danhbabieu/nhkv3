<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryRuntimeContractTest extends TestCase
{
    public function test_runtime_searches_existing_knowledge_and_revalidates_approved_destinations(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');

        self::assertStringContainsString('WpdbKnowledgeRepository', $source);
        self::assertStringContainsString('knowledgeLookup: function', $source);
        self::assertStringContainsString('approvedLabelRows(', $source);
        self::assertStringContainsString('revalidateDelegatedDestination(', $source);
    }

    public function test_public_auto_link_terms_come_only_from_approved_dictionary_labels(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');
        self::assertMatchesRegularExpression('/public function publicTerms\(\): array\s*\{(?P<body>.*?)\n    \}/s', $source);
        preg_match('/public function publicTerms\(\): array\s*\{(?P<body>.*?)\n    \}/s', $source, $match);
        $body = (string) ($match['body'] ?? '');

        self::assertStringContainsString('$this->publicQuery->hub(2000)', $body);
        self::assertStringNotContainsString('$this->types->all()', $body);
        self::assertStringNotContainsString('$this->authority->listByType', $body);
    }
}
