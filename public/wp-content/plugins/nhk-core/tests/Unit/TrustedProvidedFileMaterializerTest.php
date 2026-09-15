<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\{ChatGptMcpGatewayException, TrustedProvidedFileMaterializer};
use PHPUnit\Framework\TestCase;

final class TrustedProvidedFileMaterializerTest extends TestCase
{
    private const HOST = 'files.openai.test';

    public function test_structured_reference_materializes_one_native_file(): void
    {
        $result = TrustedProvidedFileMaterializer::materialize([
            ['download_url' => 'https://' . self::HOST . '/one', 'file_id' => 'file_one', 'mime_type' => 'image/gif', 'file_name' => 'one.gif'],
        ], static function (string $url, string $path, int $remaining): array {
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, static fn (string $host, string $url): bool => $host === self::HOST);

        try {
            self::assertSame(['one.gif'], $result['files']['files']['name']);
            self::assertSame(['image/gif'], $result['files']['files']['type']);
            self::assertCount(1, $result['files']['files']['tmp_name']);
            self::assertFileExists($result['files']['files']['tmp_name'][0]);
        } finally {
            self::cleanup($result['temporary_paths']);
        }
    }

    public function test_structured_references_preserve_order_and_cardinality(): void
    {
        $result = TrustedProvidedFileMaterializer::materialize([
            ['download_url' => 'https://' . self::HOST . '/a.gif', 'file_id' => 'file_a', 'file_name' => 'a.gif'],
            ['download_url' => 'https://' . self::HOST . '/b.gif', 'file_id' => 'file_b', 'file_name' => 'b.gif'],
        ], static function (string $url, string $path, int $remaining): array {
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, static fn (string $host, string $url): bool => $host === self::HOST);

        try {
            self::assertCount(2, $result['files']['files']['tmp_name']);
            self::assertSame(['a.gif', 'b.gif'], $result['files']['files']['name']);
        } finally {
            self::cleanup($result['temporary_paths']);
        }
    }

    public function test_opaque_reference_fails_closed_without_creating_a_file(): void
    {
        $this->expectException(ChatGptMcpGatewayException::class);
        TrustedProvidedFileMaterializer::materialize(['opaque-file-id'], null, static fn (string $host, string $url): bool => true);
    }

    public function test_rejects_images_that_exceed_the_decoded_pixel_budget(): void
    {
        try {
            TrustedProvidedFileMaterializer::materialize([
                ['download_url' => 'https://' . self::HOST . '/bomb.png', 'file_id' => 'file_bomb', 'mime_type' => 'image/png', 'file_name' => 'bomb.png'],
            ], static function (string $url, string $path, int $remaining): array {
                file_put_contents($path, self::pngHeader(10000, 10000));
                return ['status' => 200];
            }, static fn (string $host, string $url): bool => $host === self::HOST);
            self::fail('A decoded-pixel bomb must be rejected.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_DECODED_PIXEL_LIMIT', $error->reasonCode());
        }
    }

    private static function gifBytes(): string
    {
        return hex2bin('47494638396101000100800000ffffff21f90401000001002c00000000010001000002024401003b') ?: '';
    }

    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('N', 13) . 'IHDR' . pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
        $iend = 'IEND';
        return "\x89PNG\r\n\x1a\n" . $ihdr . pack('N', crc32(substr($ihdr, 4))) . pack('N', 0) . $iend . pack('N', crc32($iend));
    }

    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) if (is_string($path) && is_file($path)) @unlink($path);
    }
}
