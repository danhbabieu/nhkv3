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

    public function test_mcp_resolve_projects_through_entry_sense_resolution(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');

        self::assertStringContainsString('resolveEntrySense(', $source);
        self::assertStringContainsString('DictionaryMcpResolveProjection', $source);
    }

    public function test_public_auto_link_terms_come_only_from_approved_dictionary_labels(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');
        self::assertMatchesRegularExpression('/public function publicTerms\(\): array\s*\{(?P<body>.*?)\n    \}/s', $source);
        preg_match('/public function publicTerms\(\): array\s*\{(?P<body>.*?)\n    \}/s', $source, $match);
        $body = (string) ($match['body'] ?? '');

        self::assertStringContainsString('$this->publicQuery->archive(', $body);
        self::assertStringNotContainsString('$this->types->all()', $body);
        self::assertStringNotContainsString('$this->authority->listByType', $body);
    }

    public function test_entry_mode_public_terms_uses_single_sense_identity_and_fails_closed_for_multi_sense(): void
    {
        $runtime = (new \ReflectionClass(\NHK\Core\Application\Dictionary\DictionaryRuntime::class))->newInstanceWithoutConstructor();
        $normalizer = new \ReflectionProperty($runtime, 'normalizer');
        $normalizer->setValue($runtime, new \NHK\Core\Application\Dictionary\DictionaryTermNormalizer());
        $method = new \ReflectionMethod($runtime, 'publicTermsFromHubItems');
        $method->setAccessible(true);
        $terms = $method->invoke($runtime, [
            ['entry_id' => 'entry-1', 'url' => '/tu-dien/anniversary-clock/', 'labels' => [['label' => 'Anniversary clock', 'kind' => 'PREFERRED']], 'senses' => [['sense_id' => 'sense-1']]],
            ['entry_id' => 'entry-2', 'url' => '/tu-dien/ambiguous/', 'labels' => [['label' => 'Ambiguous', 'kind' => 'PREFERRED']], 'senses' => [['sense_id' => 'sense-a'], ['sense_id' => 'sense-b']]],
        ]);

        self::assertSame([['concept_id' => 'sense-1', 'label' => 'Anniversary clock', 'url' => '/tu-dien/anniversary-clock/']], $terms);
        self::assertNotContains('entry-1', array_column($terms, 'concept_id'));
    }
}
