<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use PHPUnit\Framework\TestCase;
use NHK\Core\Application\Presentation\PublicNavigationDefinition;

final class PresentationNavigationFrontendContractTest extends TestCase
{
    public function test_public_consumers_keep_curated_clock_type_navigation_out_of_the_global_header(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Home/HomeSemanticQuery.php');
        $collection = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Application/Entity/PublicEntityCollectionQuery.php');
        $header = (string) file_get_contents(dirname(__DIR__, 5) . '/themes/nhk-v3/header.php');
        self::assertStringContainsString('curatedClockTypeArchive', $home);
        self::assertStringNotContainsString("archiveProfile('clock_type'", $home);
        self::assertStringContainsString('show_in_type_index', $collection . file_get_contents(dirname(__DIR__, 3) . '/src/Domain/PresentationNavigation/NavigationPlacement.php'));
        self::assertStringNotContainsString('nhk_v3_clock_type_navigation_items', $header);
        self::assertSame(1, substr_count($header, 'class="nav-discovery"'));
    }

    public function test_global_navigation_prioritizes_lookup_and_groups_secondary_discovery(): void
    {
        $groups = PublicNavigationDefinition::groups();

        self::assertSame(
            ['Sản phẩm', 'Thương hiệu', 'Loại đồng hồ', 'Từ điển', 'Tri thức'],
            array_column($groups['primary'], 'label')
        );
        self::assertSame(
            ['/san-pham/', '/thuong-hieu/', '/loai-dong-ho/', '/tu-dien/', '/tri-thuc/'],
            array_column($groups['primary'], 'path')
        );
        self::assertSame(
            ['Hình ảnh', 'Video', 'Mẫu', 'Bộ máy', 'Bản nhạc', 'Linh kiện', 'Hiện vật', 'So sánh', 'Góc chia sẻ'],
            array_column($groups['discovery'], 'label')
        );
        self::assertSame(
            ['Sản phẩm', 'Thương hiệu', 'Loại đồng hồ', 'Từ điển'],
            array_column($groups['footer']['Tra cứu'], 'label')
        );
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
