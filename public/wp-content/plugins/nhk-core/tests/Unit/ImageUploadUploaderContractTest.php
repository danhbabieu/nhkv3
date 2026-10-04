<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ImageUploadUploaderContractTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/ui/image-upload.html');
    }

    public function test_uploader_exposes_one_task_description_and_no_per_asset_semantic_inputs(): void
    {
        $source = $this->source();

        self::assertSame(1, substr_count($source, 'id="description"'));
        self::assertStringContainsString('Mô tả nhiệm vụ', $source);
        self::assertStringNotContainsString('Tên ảnh', $source);
        self::assertStringNotContainsString('asset-name', $source);
        self::assertStringNotContainsString('asset-feature', $source);
        self::assertStringNotContainsString('feature_requests', $source);
    }

    public function test_camera_filename_is_lineage_only_and_not_widget_media_title(): void
    {
        $source = $this->source();

        self::assertStringContainsString('file_name: outcome.fileName', $source);
        self::assertStringNotContainsString('media: { title: item.name.trim() }', $source);
        self::assertStringNotContainsString('name: item.name.trim()', $source);
    }

    public function test_capture_lineage_accepts_new_packets_without_legacy_semantic_fields(): void
    {
        $coordinator = (new \ReflectionClass(\NHK\Core\Application\Capture\EditorialCaptureCoordinator::class))
            ->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($coordinator))->getMethod('safeAssetInputs');
        $method->setAccessible(true);

        self::assertSame(
            [['client_file_id' => 'file-a', 'ordinal' => 0], ['client_file_id' => 'file-b', 'ordinal' => 1]],
            $method->invoke($coordinator, [
                ['client_file_id' => 'file-b', 'ordinal' => 1],
                ['client_file_id' => 'file-a', 'ordinal' => 0],
            ]),
        );
        self::assertSame(
            [['client_file_id' => 'legacy', 'ordinal' => 0, 'name' => 'Ảnh cũ', 'feature_requests' => ['mặt số']]],
            $method->invoke($coordinator, [[
                'client_file_id' => 'legacy',
                'ordinal' => 0,
                'name' => 'Ảnh cũ',
                'feature_requests' => ['mặt số'],
            ]]),
        );
    }
}
