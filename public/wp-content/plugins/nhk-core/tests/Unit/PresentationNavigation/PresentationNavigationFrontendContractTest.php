<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use PHPUnit\Framework\TestCase;

final class PresentationNavigationFrontendContractTest extends TestCase
{
    public function test_public_consumers_use_curated_navigation_and_not_clock_type_archive_fallback(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Home/HomeSemanticQuery.php');
        $collection = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Entity/PublicEntityCollectionQuery.php');
        $header = (string) file_get_contents(dirname(__DIR__, 5) . '/themes/nhk-v3/header.php');
        self::assertStringContainsString('curatedClockTypeArchive', $home);
        self::assertStringNotContainsString("archiveProfile('clock_type'", $home);
        self::assertStringContainsString('show_in_type_index', $collection . file_get_contents(dirname(__DIR__, 3) . '/src/Domain/PresentationNavigation/NavigationPlacement.php'));
        self::assertStringContainsString('nhk_v3_clock_type_navigation_items', $header);
    }

    public function test_public_navigation_definition_does_not_contain_curated_clock_type_nodes(): void
    {
        $definition = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Presentation/PublicNavigationDefinition.php');
        foreach (['Đồng hồ tủ', 'Đồng hồ treo tường', 'Đồng hồ Pháp', 'Đồng hồ Đức', 'Đồng hồ chim cúc cu', 'Đồng hồ 400 ngày', 'Đồng hồ công cộng', 'Đồng hồ vai bò', 'Đồng hồ để bàn'] as $label) self::assertStringNotContainsString($label, $definition);
    }

    public function test_curated_archive_does_not_filter_origin_nodes_by_clock_type_profile(): void
    {
        $collection = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Entity/PublicEntityCollectionQuery.php');
        self::assertStringNotContainsString('($item[\'profile_key\'] ?? \'\') !== \'clock_type\'', $collection);
    }
}
