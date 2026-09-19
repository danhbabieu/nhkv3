<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\ImageIngestEntrypoint;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGateway;
use PHPUnit\Framework\TestCase;

final class ImageIngestEntrypointTest extends TestCase
{
    public function test_native_multipart_file_is_delegated_unchanged(): void
    {
        $native = ['files' => ['name' => ['photo.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => ['/tmp/photo'], 'error' => [UPLOAD_ERR_OK], 'size' => [123]]];
        $materializeCalls = 0;
        $upload = null;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$upload): array {
                $upload = [$key, $metadata, $files, $items];
                return ['items' => [['attachment_id' => 10]]];
            },
            static function (mixed $references) use (&$materializeCalls): array {
                $materializeCalls++;
                return [];
            },
        );

        $result = $entrypoint->ingest('one', ['description' => 'Photo'], $native, [], true);

        self::assertSame(['items' => [['attachment_id' => 10]]], $result);
        self::assertSame(0, $materializeCalls);
        self::assertSame(['one', ['description' => 'Photo'], $native, []], $upload);
    }

    public function test_structured_reference_is_materialized_and_original_name_is_preserved(): void
    {
        $reference = [['download_url' => 'https://files.example.test/image', 'file_id' => 'file-1', 'mime_type' => 'image/jpeg', 'file_name' => 'original-photo.jpg']];
        $path = tempnam(sys_get_temp_dir(), 'nhk-image-entrypoint-');
        self::assertIsString($path);
        file_put_contents($path, 'bytes');
        $materializeCalls = [];
        $upload = null;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$upload): array {
                $upload = [$key, $metadata, $files, $items];
                return ['items' => [['attachment_id' => 11]]];
            },
            static function (mixed $references) use (&$materializeCalls, $path): array {
                $materializeCalls[] = $references;
                return ['files' => ['files' => ['name' => ['original-photo.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => [$path], 'error' => [UPLOAD_ERR_OK], 'size' => [5]]], 'temporary_paths' => [$path]];
            },
        );

        try {
            $result = $entrypoint->ingest('structured-one', ['description' => 'Photo'], $reference, [['client_file_id' => 'file-1']]);

            self::assertSame(['items' => [['attachment_id' => 11]]], $result);
            self::assertSame([$reference], $materializeCalls);
            self::assertSame('original-photo.jpg', $upload[2]['files']['name'][0]);
            self::assertSame([['client_file_id' => 'file-1']], $upload[3]);
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }

    public function test_structured_reference_uses_the_existing_trusted_gateway_before_upload(): void
    {
        $fixture = __DIR__ . '/../../../../../wp-admin/images/post-formats-vs.png';
        self::assertFileExists($fixture);
        $upload = null;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$upload): array {
                self::assertFileExists((string) $files['files']['tmp_name'][0]);
                self::assertFileIsReadable((string) $files['files']['tmp_name'][0]);
                $upload = [$key, $metadata, $files, $items];
                return ['items' => [['attachment_id' => 12]]];
            },
            static fn (mixed $references): array => ChatGptMcpGateway::materializeReferences(
                $references,
                static function (string $url, string $path, int $remaining) use ($fixture): array {
                    self::assertSame('https://files.example.test/image', $url);
                    self::assertGreaterThan(0, filesize($fixture));
                    self::assertLessThanOrEqual($remaining, filesize($fixture));
                    self::assertTrue(copy($fixture, $path));
                    return ['status' => 200];
                },
                null,
                static fn (string $host): array => ['93.184.216.34'],
            ),
        );

        $entrypoint->ingest('gateway-one', [], [[
            'download_url' => 'https://files.example.test/image',
            'file_id' => 'file-12',
            'mime_type' => 'image/png',
            'file_name' => 'camera-original.png',
        ]]);

        self::assertSame('camera-original.png', $upload[2]['files']['name'][0]);
        self::assertSame('image/png', $upload[2]['files']['type'][0]);
        self::assertFileDoesNotExist((string) ($upload[2]['files']['tmp_name'][0] ?? ''));
    }

    public function test_widget_metadata_is_not_forwarded_into_the_trusted_file_materializer(): void
    {
        $fixture = __DIR__ . '/../../../../../wp-admin/images/post-formats-vs.png';
        $materializerInput = null;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items): array {
                return ['items' => [['attachment_id' => 13]]];
            },
            static function (mixed $references) use (&$materializerInput, $fixture): array {
                $materializerInput = $references;
                return ChatGptMcpGateway::materializeReferences(
                    $references,
                    static function (string $url, string $path, int $remaining) use ($fixture): array {
                        self::assertLessThanOrEqual($remaining, filesize($fixture));
                        self::assertTrue(copy($fixture, $path));
                        return ['status' => 200];
                    },
                    null,
                    static fn (string $host): array => ['93.184.216.34'],
                );
            },
        );

        $entrypoint->ingest('widget-shape', [], [[
            'download_url' => 'https://files.example.test/image',
            'file_id' => 'file-13',
            'mime_type' => 'image/png',
            'file_name' => 'camera-original.png',
            'ordinal' => 0,
            'media' => ['title' => 'Mặt trước'],
        ]], [['client_file_id' => 'file-13', 'ordinal' => 0, 'media' => ['title' => 'Mặt trước']]]);

        self::assertSame([[
            'download_url' => 'https://files.example.test/image',
            'file_id' => 'file-13',
            'mime_type' => 'image/png',
            'file_name' => 'camera-original.png',
        ]], $materializerInput);
    }

    /** @dataProvider providedReferenceShapeProvider */
    public function test_widget_transport_materializes_only_complete_structured_references(array $reference, bool $shouldMaterialize): void
    {
        $materialized = 0;
        $entrypoint = new ImageIngestEntrypoint(
            static fn (string $key, array $metadata, array $files, array $items): array => ['items' => []],
            static function (mixed $references) use (&$materialized): array {
                $materialized++;
                return ['files' => ['files' => ['name' => ['one.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => ['/tmp/one'], 'error' => [UPLOAD_ERR_OK], 'size' => [1]]], 'temporary_paths' => []];
            },
        );

        if (!$shouldMaterialize) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('IMAGE_FILE_INPUT_INVALID');
        }
        $entrypoint->ingest('shape-' . $materialized, [], [$reference], [], false);
        self::assertSame($shouldMaterialize ? 1 : 0, $materialized);
    }

    public static function providedReferenceShapeProvider(): iterable
    {
        yield 'file id only' => [['file_id' => 'file-one'], false];
        yield 'download url only' => [['download_url' => 'https://files.example.test/one'], false];
        yield 'complete reference' => [['download_url' => 'https://files.example.test/one', 'file_id' => 'file-one', 'mime_type' => 'image/jpeg', 'file_name' => 'one.jpg'], true];
        yield 'native file bag accidentally passed as reference' => [['tmp_name' => '/tmp/one', 'name' => 'one.jpg'], false];
    }

    /** @dataProvider invalidReferenceProvider */
    public function test_non_structured_reference_is_rejected(mixed $provided): void
    {
        $entrypoint = new ImageIngestEntrypoint(
            static fn (string $key, array $metadata, array $files, array $items): array => [],
            static fn (mixed $references): array => throw new \LogicException('materializer must not be called'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IMAGE_FILE_INPUT_INVALID');
        $entrypoint->ingest('invalid', [], $provided);
    }

    public static function invalidReferenceProvider(): iterable
    {
        yield 'opaque string' => ['opaque-file-id'];
        yield 'bare https URL' => [['https://files.example.test/image']];
        yield 'local path' => [['/tmp/photo.jpg']];
        yield 'base64' => [['data:image/jpeg;base64,ZmFrZQ==']];
        yield 'missing file id' => [['download_url' => 'https://files.example.test/image']];
        yield 'filesystem-shaped descriptor' => [['name' => 'photo.jpg', 'tmp_name' => '/etc/passwd', 'size' => 10]];
    }
}
