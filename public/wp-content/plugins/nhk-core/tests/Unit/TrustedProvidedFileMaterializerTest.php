<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\{ChatGptMcpGatewayException, TrustedProvidedFileMaterializer};
use PHPUnit\Framework\TestCase;

final class TrustedProvidedFileMaterializerTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    public function test_any_resolved_public_chatgpt_region_host_uses_the_same_policy(): void
    {
        foreach (['oaisdmntprwestus.blob.core.windows.net', 'sdmntprjapaneast.oaiusercontent.com'] as $host) {
            $result = $this->materialize([['download_url' => 'https://' . $host . '/one', 'file_id' => 'file_' . $host]]);
            try {
                self::assertSame(['image/gif'], $result['files']['files']['type']);
            } finally {
                $this->cleanup($result['temporary_paths']);
            }
        }
    }

    public function test_structured_reference_materializes_one_native_file(): void
    {
        $result = $this->materialize([
            ['download_url' => 'https://files.openai.test/one', 'file_id' => 'file_one', 'mime_type' => 'image/gif', 'file_name' => 'one.gif'],
        ]);
        try {
            self::assertSame(['one.gif'], $result['files']['files']['name']);
            self::assertSame(['image/gif'], $result['files']['files']['type']);
            self::assertCount(1, $result['files']['files']['tmp_name']);
            self::assertFileExists($result['files']['files']['tmp_name'][0]);
        } finally {
            $this->cleanup($result['temporary_paths']);
        }
    }

    public function test_url_without_file_id_is_rejected(): void
    {
        $this->expectReason('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', static function (): void {
            TrustedProvidedFileMaterializer::materialize([['download_url' => 'https://files.openai.test/one']], null, null, static fn (string $host): array => [self::PUBLIC_IP]);
        });
    }

    public function test_widget_mapping_metadata_is_accepted_but_not_forwarded_to_native_file_bag(): void
    {
        $result = $this->materialize([[
            'download_url' => 'https://files.openai.test/one',
            'file_id' => 'file_one',
            'ordinal' => 0,
            'media' => ['title' => 'Mặt trước'],
        ]]);
        try {
            self::assertSame(['one.gif'], $result['files']['files']['name']);
            self::assertArrayNotHasKey('ordinal', $result['files']['files']);
            self::assertArrayNotHasKey('media', $result['files']['files']);
        } finally {
            $this->cleanup($result['temporary_paths']);
        }
    }

    public function test_legacy_string_reference_is_rejected(): void
    {
        $this->expectReason('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', static function (): void {
            TrustedProvidedFileMaterializer::materialize(['https://files.openai.test/one'], null, null, static fn (string $host): array => [self::PUBLIC_IP]);
        });
    }

    /** @dataProvider invalidUrlProvider */
    public function test_url_structure_is_fail_closed(string $url, string $reason): void
    {
        $this->expectReason($reason, function () use ($url): void {
            TrustedProvidedFileMaterializer::materialize([['download_url' => $url, 'file_id' => 'file_invalid']], null, null, static fn (string $host): array => [self::PUBLIC_IP]);
        });
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidUrlProvider(): iterable
    {
        yield 'http' => ['http://files.openai.test/one', 'CHATGPT_FILE_URL_REJECTED'];
        yield 'non default port' => ['https://files.openai.test:8443/one', 'CHATGPT_FILE_URL_REJECTED'];
        yield 'username' => ['https://user@files.openai.test/one', 'CHATGPT_FILE_URL_REJECTED'];
        yield 'password' => ['https://user:pass@files.openai.test/one', 'CHATGPT_FILE_URL_REJECTED'];
        yield 'ip literal' => ['https://93.184.216.34/one', 'CHATGPT_FILE_URL_REJECTED'];
    }

    /** @dataProvider privateAddressProvider */
    public function test_every_non_public_dns_destination_is_rejected(string $ip): void
    {
        $this->expectReason('CHATGPT_FILE_PRIVATE_IP_REJECTED', function () use ($ip): void {
            TrustedProvidedFileMaterializer::materialize([['download_url' => 'https://files.openai.test/one', 'file_id' => 'file_private']], null, null, static fn (string $host): array => [$ip]);
        });
    }

    /** @return iterable<string,array{string}> */
    public static function privateAddressProvider(): iterable
    {
        yield 'localhost' => ['127.0.0.1'];
        yield 'rfc1918' => ['192.168.1.20'];
        yield 'metadata link local' => ['169.254.169.254'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 unique local' => ['fc00::1'];
        yield 'ipv6 link local' => ['fe80::1'];
        yield 'ipv4 mapped private ipv6' => ['::ffff:192.168.1.20'];
    }

    public function test_public_redirect_is_revalidated_and_private_target_is_rejected(): void
    {
        $this->expectReason('CHATGPT_FILE_PRIVATE_IP_REJECTED', static function (): void {
            TrustedProvidedFileMaterializer::validateRedirectTarget(
                'https://files.openai.test/one',
                'https://redirect.openai.test/two',
                null,
                static fn (string $host): array => [$host === 'redirect.openai.test' ? '127.0.0.1' : self::PUBLIC_IP],
            );
        });
    }

    public function test_dns_rebinding_is_rejected_before_the_injected_transport_runs(): void
    {
        $calls = 0;
        $this->expectReason('CHATGPT_FILE_PRIVATE_IP_REJECTED', function () use (&$calls): void {
            TrustedProvidedFileMaterializer::materialize(
                [['download_url' => 'https://files.openai.test/one', 'file_id' => 'file_rebind']],
                static function (string $url, string $path, int $remaining): array {
                    file_put_contents($path, self::gifBytes());
                    return ['status' => 200];
                },
                null,
                static function (string $host) use (&$calls): array {
                    $calls++;
                    return [$calls === 1 ? self::PUBLIC_IP : '10.0.0.8'];
                },
            );
        });
        self::assertSame(2, $calls);
    }

    public function test_stream_byte_limit_is_enforced_without_content_length(): void
    {
        $this->expectReason('CHATGPT_FILE_SIZE_LIMIT', function (): void {
            TrustedProvidedFileMaterializer::materialize(
                [['download_url' => 'https://files.openai.test/large', 'file_id' => 'file_large']],
                static function (string $url, string $path, int $remaining): array {
                    $handle = fopen($path, 'wb');
                    self::assertIsResource($handle);
                    fwrite($handle, str_repeat('x', $remaining + 1));
                    fclose($handle);
                    return ['status' => 200];
                },
                null,
                static fn (string $host): array => [self::PUBLIC_IP],
            );
        });
    }

    public function test_fake_image_content_type_and_corrupt_image_bytes_are_rejected(): void
    {
        foreach (['not an image', "\xff\xd8\xff\xe0\0\x10JFIF"] as $bytes) {
            try {
                $this->materialize([
                    ['download_url' => 'https://files.openai.test/fake', 'file_id' => 'file_fake', 'mime_type' => 'image/jpeg'],
                ], static function (string $url, string $path) use ($bytes): array {
                    file_put_contents($path, $bytes);
                    return ['status' => 200];
                });
                self::fail('Invalid image bytes must be rejected.');
            } catch (ChatGptMcpGatewayException $error) {
                self::assertContains($error->reasonCode(), ['CHATGPT_FILE_MIME_REJECTED', 'CHATGPT_FILE_DECODE_FAILED', 'CHATGPT_FILE_MIME_MISMATCH']);
            }
        }
    }

    public function test_rejects_images_that_exceed_the_decoded_pixel_budget(): void
    {
        $this->expectReason('CHATGPT_FILE_DECODED_PIXEL_LIMIT', function (): void {
            $this->materialize([
                ['download_url' => 'https://files.openai.test/bomb.png', 'file_id' => 'file_bomb', 'mime_type' => 'image/png', 'file_name' => 'bomb.png'],
            ], static function (string $url, string $path): array {
                file_put_contents($path, self::pngHeader(10000, 10000));
                return ['status' => 200];
            });
        });
    }

    /** @param list<array<string,mixed>> $references */
    private function materialize(array $references, ?callable $downloader = null): array
    {
        return TrustedProvidedFileMaterializer::materialize(
            $references,
            $downloader ?? static function (string $url, string $path): array {
                file_put_contents($path, self::gifBytes());
                return ['status' => 200];
            },
            null,
            static fn (string $host): array => [self::PUBLIC_IP],
        );
    }

    private function expectReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected ' . $reason . '.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame($reason, $error->reasonCode());
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

    /** @param list<string> $paths */
    private function cleanup(array $paths): void
    {
        foreach ($paths as $path) if (is_string($path) && is_file($path)) unlink($path);
    }
}
