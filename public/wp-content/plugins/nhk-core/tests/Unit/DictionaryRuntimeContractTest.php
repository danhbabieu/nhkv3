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

    public function test_dictionary_runtime_wires_governed_media_reuse_without_owning_media_evidence_or_graph_truth(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');

        self::assertStringContainsString('selectPreferredIllustration', $runtime);
        self::assertStringContainsString('MediaUsageRepository', $runtime);
        self::assertStringContainsString('MediaAssetRepository', $runtime);
        self::assertStringContainsString('dictionary_concept', $runtime);
        self::assertStringNotContainsString('EvidenceRepository', $runtime);
        self::assertStringNotContainsString('GraphRepository', $runtime);
    }

    public function test_cuon_111_reuses_one_media_identity_across_registered_contexts_without_copying_binaries(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryRuntime.php');

        self::assertStringNotContainsString('Media::create', $runtime);
        self::assertStringNotContainsString('Attachment::create', $runtime);
        self::assertStringNotContainsString('Evidence::create', $runtime);
        self::assertStringNotContainsString('Graph::create', $runtime);
        self::assertStringContainsString('MediaUsage', $runtime);
    }
}
