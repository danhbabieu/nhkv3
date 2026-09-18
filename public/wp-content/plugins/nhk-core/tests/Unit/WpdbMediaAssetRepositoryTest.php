<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media {
    if (!function_exists(__NAMESPACE__ . '\\wp_json_encode')) {
        function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
    }
}

namespace NHK\Tests\Unit {

use NHK\Core\Domain\Media\MediaAsset;
use NHK\Core\Infrastructure\Media\WpdbMediaAssetRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbMediaAssetRepositoryTest extends TestCase
{
    public function test_invalid_domain_row_is_omitted(): void
    {
        $assetId = UuidCodec::newV7();
        $mediaId = UuidCodec::newV7();
        $repository = new WpdbMediaAssetRepository($this->database([
            'asset_uuid' => UuidCodec::toBinary($assetId), 'media_id' => 1,
            'asset_kind' => 'original', 'storage_key' => 'asset.jpg',
            'checksum' => hex2bin(str_repeat('a', 64)), 'mime_type' => '',
            'byte_size' => 1, 'width' => null, 'height' => null,
            'visibility' => 'PRIVATE', 'metadata_json' => '{}',
        ], UuidCodec::toBinary($mediaId)));

        self::assertNull($repository->findByAssetId($assetId));
    }

    public function test_programming_type_error_is_not_hidden_as_an_empty_row(): void
    {
        $assetId = UuidCodec::newV7();
        $mediaId = UuidCodec::newV7();
        $repository = new WpdbMediaAssetRepository($this->database([
            'asset_uuid' => UuidCodec::toBinary($assetId), 'media_id' => 1,
            'asset_kind' => 'original', 'storage_key' => 'asset.jpg',
            'checksum' => [], 'mime_type' => 'image/jpeg', 'byte_size' => 1,
            'width' => null, 'height' => null, 'visibility' => 'PRIVATE',
            'metadata_json' => '{}',
        ], UuidCodec::toBinary($mediaId)));

        $this->expectException(\TypeError::class);
        $repository->findByAssetId($assetId);
    }

    public function test_update_persists_checksum_storage_dimensions_metadata_and_uses_expected_revision(): void
    {
        $assetId = UuidCodec::newV7();
        $mediaId = UuidCodec::newV7();
        $checksum = hash('sha256', 'edited-bytes');
        $database = new class($assetId, $mediaId, $checksum) {
            public string $prefix = 'wp_';
            public array $prepared = [];
            public array $queries = [];

            public function __construct(private string $assetId, private string $mediaId, private string $checksum) {}
            public function prepare(string $query, mixed ...$arguments): string
            {
                $this->prepared[] = [$query, $arguments];
                return $query;
            }
            public function get_row(string $query, mixed $output): array
            {
                return [
                    'asset_uuid' => UuidCodec::toBinary($this->assetId), 'media_id' => 1,
                    'asset_kind' => 'original', 'storage_key' => 'private/edited.png',
                    'checksum' => hex2bin($this->checksum), 'mime_type' => 'image/png',
                    'byte_size' => 12, 'width' => 640, 'height' => 480,
                    'visibility' => 'PRIVATE', 'metadata_json' => '{"source_original":true}',
                ];
            }
            public function get_var(string $query): string { return UuidCodec::toBinary($this->mediaId); }
            public function query(string $query): int { $this->queries[] = $query; return 1; }
            public function get_results(string $query, mixed $output): array { return []; }
        };

        $asset = new MediaAsset($assetId, $mediaId, 'original', 'private/edited.png', $checksum, 'image/png', 12, 640, 480, 'PRIVATE', ['source_original' => true]);
        $result = (new WpdbMediaAssetRepository($database))->update($asset, 7);

        self::assertSame($assetId, $result->assetId);
        self::assertStringContainsString('checksum=UNHEX(%s)', $database->prepared[0][0]);
        self::assertStringContainsString('storage_key=%s', $database->prepared[0][0]);
        self::assertStringContainsString('width=%d,height=%d', $database->prepared[0][0]);
        self::assertStringContainsString('metadata_json=%s', $database->prepared[0][0]);
        self::assertSame($checksum, $database->prepared[0][1][0]);
        self::assertSame(UuidCodec::toBinary($assetId), $database->prepared[0][1][count($database->prepared[0][1]) - 1]);
    }

    private function database(array $row, string $mediaUuid): object
    {
        return new class($row, $mediaUuid) {
            public string $prefix = 'wp_';
            public function __construct(private array $row, private string $mediaUuid) {}
            public function prepare(string $query, mixed ...$arguments): string { return $query; }
            public function get_row(string $query, mixed $output): array { return $this->row; }
            public function get_var(string $query): string { return $this->mediaUuid; }
            public function get_results(string $query, mixed $output): array { return []; }
        };
    }
}
}
