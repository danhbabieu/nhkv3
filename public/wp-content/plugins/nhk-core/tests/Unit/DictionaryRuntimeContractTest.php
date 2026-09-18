<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryCurationService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
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
        $parameters = (new \ReflectionClass(DictionaryCurationService::class))->getConstructor()?->getParameters() ?? [];
        $parameterNames = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);

        self::assertContains('media', $parameterNames);
        self::assertContains('mediaRepository', $parameterNames);
        self::assertContains('assetRepository', $parameterNames);
        self::assertContains('usageRepository', $parameterNames);
        self::assertContains(MediaRepository::class, array_map(static fn (\ReflectionParameter $parameter): ?string => $parameter->getType()?->getName(), $parameters));
        self::assertContains(MediaAssetRepository::class, array_map(static fn (\ReflectionParameter $parameter): ?string => $parameter->getType()?->getName(), $parameters));
        self::assertContains(MediaUsageRepository::class, array_map(static fn (\ReflectionParameter $parameter): ?string => $parameter->getType()?->getName(), $parameters));
    }

    public function test_cuon_111_reuses_one_media_identity_across_registered_contexts_without_copying_binaries(): void
    {
        $media = new DictionaryIllustrationMediaRepository([
            new \NHK\Core\Domain\Media\Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'dictionary-cuon-111', 'Côn 111', 'ready'),
        ]);
        $asset = new DictionaryIllustrationAssetRepository([
            new \NHK\Core\Domain\Media\MediaAsset('01a0ab0c-fde0-7c01-a89d-fc5eef832c94', '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'derivative', 'dictionary-cuon-111.webp', hash('sha256', 'dictionary-cuon-111'), 'image/webp', 1200, 1200, 800, 'PUBLIC'),
        ]);
        $usage = new DictionaryIllustrationUsageRepository();
        $before = [$media->items, $asset->items, $usage->items];

        self::assertSame($before[0], $media->items);
        self::assertSame($before[1], $asset->items);
        self::assertSame($before[2], $usage->items);
        self::assertCount(0, $usage->listByEndpoint('dictionary_concept', 'concept-cuon-111', 'representative'));
        self::assertSame([], $usage->listByEndpoint('evidence', 'concept-cuon-111'));
        self::assertSame([], $usage->listByEndpoint('graph', 'concept-cuon-111'));
    }
}
