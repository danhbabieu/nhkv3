<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryHarvesterWiringTest extends TestCase
{
    public function test_capture_observation_registry_uses_harvester_and_all_content_observers_share_it(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Dictionary/DictionaryBootstrap.php');
        $bridge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Dictionary/DictionaryWordPressBridge.php');
        $article = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Article/ArticleResearchPreflight.php');
        $knowledge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Knowledge/KnowledgeService.php');
        $video = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Video/VideoService.php');

        self::assertStringContainsString('self::$runtime->harvester()', $bootstrap);
        self::assertStringContainsString('$harvester->harvest(', $bootstrap);
        self::assertStringContainsString('DictionaryObservationRegistry::observe', $bridge);
        self::assertStringContainsString('DictionaryObservationRegistry::preview', $article);
        self::assertStringContainsString('DictionaryObservationRegistry::observe', $knowledge);
        self::assertStringContainsString('DictionaryObservationRegistry::observe', $video);
        self::assertStringNotContainsString('DictionaryMigration015', $knowledge);
        self::assertStringNotContainsString('DictionaryMigration015', $video);
    }
}
