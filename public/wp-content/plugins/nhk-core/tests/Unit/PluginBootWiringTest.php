<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginBootWiringTest extends TestCase
{
    public function test_public_entity_projection_dependencies_are_created_before_projection(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        $projection = strpos($plugin, 'new EntityMediaProjection($publicMedia, $publicAssets, $publicUsages)');
        $assets = strpos($plugin, '$publicAssets = new WpdbMediaAssetRepository($wpdb);');
        $usages = strpos($plugin, '$publicUsages = new WpdbMediaUsageRepository($wpdb);');

        self::assertNotFalse($projection);
        self::assertNotFalse($assets);
        self::assertNotFalse($usages);
        self::assertLessThan($projection, $assets);
        self::assertLessThan($projection, $usages);
    }
}
