<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

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
