<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\ChatGptMcpGateway;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGatewayException;
use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use PHPUnit\Framework\TestCase;

final class ChatGptMcpGatewayTest extends TestCase
{
    private const URL_HOST = 'files.openai.test';

    public function test_live_connector_provided_file_shape_enters_the_capture_gateway(): void
    {
        self::assertTrue(ChatGptMcpGateway::shouldHandle('/easy-mcp-ai/v1/mcp', [
            'method' => 'tools/call',
            'params' => [
                'name' => ChatGptMcpGateway::TARGET_TOOL,
                'arguments' => ['files' => [['download_url' => 'https://' . self::URL_HOST . '/signed/file.gif?fixture=redacted', 'file_id' => 'live-file']]],
            ],
        ]));
    }

    public function test_live_provided_file_becomes_native_file_before_ability_validation(): void
    {
        $reference = ['download_url' => 'https://' . self::URL_HOST . '/signed/file.gif?fixture=redacted', 'file_id' => 'live-file'];
        $result = ChatGptMcpGateway::materializeReferences([
            $reference,
        ], static function (string $url, string $path, int $remaining): array {
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, null, static fn (string $host): array => ['93.184.216.34']);

        $nativeInput = EasyMcpNativeFileCompatibilityAdapter::normalizeNativeFileInput(
            ['files' => [$reference], 'items' => [['client_file_id' => 'live-file']]],
            'nhk-v3/capture-ingest',
            $result['files'],
            '1.7.17'
        );
        $proxy = ChatGptMcpGateway::proxyRpcWithoutFiles([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => ChatGptMcpGateway::TARGET_TOOL, 'arguments' => [
                'files' => [$reference],
                'items' => [['client_file_id' => 'live-file']],
            ]],
        ]);

        self::assertSame('image/gif', $nativeInput['files'][0]['type']);
        self::assertSame($result['files']['files']['tmp_name'][0], $nativeInput['files'][0]['tmp_name']);
        self::assertSame([['client_file_id' => 'live-file']], $nativeInput['items']);
        self::assertArrayNotHasKey('files', $proxy['params']['arguments']);
        self::assertSame($nativeInput['items'], $proxy['params']['arguments']['items']);
        self::assertFalse(McpAbilityRegistration::requiresNativeTransportFiles('nhk.capture.ingest', $nativeInput, $result['files']));
        self::assertSame(['items' => $nativeInput['items']], McpAbilityRegistration::canonicalTransportArguments('nhk.capture.ingest', $nativeInput));
        self::assertFileExists($nativeInput['files'][0]['tmp_name']);
        self::cleanup($result['temporary_paths']);
        self::assertFileDoesNotExist($nativeInput['files'][0]['tmp_name']);
    }

    public function test_initial_signed_query_is_preserved_on_the_first_get(): void
    {
        $requestedUrl = null;
        $result = ChatGptMcpGateway::materializeReferences([
            ['download_url' => 'https://' . self::URL_HOST . '/signed/file.gif?sig=redacted&exp=123', 'file_id' => 'signed-file'],
        ], static function (string $url, string $path, int $remaining) use (&$requestedUrl): array {
            $requestedUrl = $url;
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, null, static fn (string $host): array => ['93.184.216.34']);

        self::assertSame('https://' . self::URL_HOST . '/signed/file.gif?sig=redacted&exp=123', $requestedUrl);
        self::cleanup($result['temporary_paths']);
    }

    public function test_live_provided_files_preserve_order_and_items_alignment(): void
    {
        $references = [
            ['download_url' => 'https://' . self::URL_HOST . '/a.gif?fixture=a', 'file_id' => 'a'],
            ['download_url' => 'https://' . self::URL_HOST . '/b.gif?fixture=b', 'file_id' => 'b'],
            ['download_url' => 'https://' . self::URL_HOST . '/c.gif?fixture=c', 'file_id' => 'c'],
        ];
        $result = ChatGptMcpGateway::materializeReferences($references, static function (string $url, string $path, int $remaining): array {
            file_put_contents($path, self::gifBytes() . basename((string) parse_url($url, PHP_URL_PATH)));
            return ['status' => 200];
        }, null, static fn (string $host): array => ['93.184.216.34']);

        $nativeInput = EasyMcpNativeFileCompatibilityAdapter::normalizeNativeFileInput(
            ['files' => $references, 'items' => [
                ['client_file_id' => 'a'],
                ['client_file_id' => 'b'],
                ['client_file_id' => 'c'],
            ]],
            'nhk-v3/capture-ingest',
            $result['files'],
            '1.7.17'
        );

        self::assertSame(['a.gif', 'b.gif', 'c.gif'], array_column($nativeInput['files'], 'name'));
        self::assertSame([
            ['client_file_id' => 'a'],
            ['client_file_id' => 'b'],
            ['client_file_id' => 'c'],
        ], $nativeInput['items']);
        self::assertCount(3, $nativeInput['files']);
        self::cleanup($result['temporary_paths']);
    }

    public function test_one_official_file_object_becomes_one_native_file_part(): void
    {
        $paths = [];
        $result = ChatGptMcpGateway::materializeReferences([
            ['download_url' => 'https://' . self::URL_HOST . '/one', 'file_id' => 'file_one', 'mime_type' => 'image/gif', 'file_name' => 'one.gif'],
        ], static function (string $url, string $path, int $remaining) use (&$paths): array {
            $paths[] = $path;
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, null, static fn (string $host): array => ['93.184.216.34']);

        self::assertCount(1, $result['temporary_paths']);
        self::assertSame(['one.gif'], $result['files']['files']['name']);
        self::assertSame(['image/gif'], $result['files']['files']['type']);
        self::assertSame(UPLOAD_ERR_OK, $result['files']['files']['error'][0]);
        self::assertSame($paths[0], $result['files']['files']['tmp_name'][0]);
        self::assertFileExists($paths[0]);
        self::cleanup($result['temporary_paths']);
    }

    public function test_three_files_preserve_order_and_cardinality_for_items_alignment(): void
    {
        $result = ChatGptMcpGateway::materializeReferences([
            ['download_url' => 'https://' . self::URL_HOST . '/first', 'file_id' => 'file_1', 'file_name' => 'first.gif'],
            ['download_url' => 'https://' . self::URL_HOST . '/second', 'file_id' => 'file_2', 'file_name' => 'second.gif'],
            ['download_url' => 'https://' . self::URL_HOST . '/third', 'file_id' => 'file_3', 'file_name' => 'third.gif'],
        ], static function (string $url, string $path, int $remaining): array {
            file_put_contents($path, self::gifBytes() . basename($url));
            return ['status' => 200];
        }, null, static fn (string $host): array => ['93.184.216.34']);

        self::assertCount(3, $result['files']['files']['tmp_name']);
        self::assertSame(['first.gif', 'second.gif', 'third.gif'], $result['files']['files']['name']);
        self::assertSame(['image/gif', 'image/gif', 'image/gif'], $result['files']['files']['type']);
        self::assertSame([UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK], $result['files']['files']['error']);
        self::assertSame([
            strlen(self::gifBytes() . 'first'),
            strlen(self::gifBytes() . 'second'),
            strlen(self::gifBytes() . 'third'),
        ], $result['files']['files']['size']);
        self::cleanup($result['temporary_paths']);
    }

    public function test_proxy_removes_only_file_references_and_preserves_items_order(): void
    {
        $rpc = [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => ChatGptMcpGateway::TARGET_TOOL,
                'arguments' => [
                    'items' => [['client_file_id' => 'file_1'], ['client_file_id' => 'file_2'], ['client_file_id' => 'file_3']],
                    'files' => [['download_url' => 'https://files.openai.test/1', 'file_id' => 'file_1']],
                ],
            ],
        ];

        $proxy = ChatGptMcpGateway::proxyRpcWithoutFiles($rpc);
        self::assertArrayNotHasKey('files', $proxy['params']['arguments']);
        self::assertSame($rpc['params']['arguments']['items'], $proxy['params']['arguments']['items']);
        self::assertSame($rpc['id'], $proxy['id']);
    }

    public function test_text_only_call_does_not_enter_file_gateway(): void
    {
        self::assertFalse(ChatGptMcpGateway::shouldHandle('/easy-mcp-ai/v1/mcp', [
            'method' => 'tools/call',
            'params' => ['name' => ChatGptMcpGateway::TARGET_TOOL, 'arguments' => ['text' => 'caption']],
        ]));
    }

    /** @dataProvider invalidReferenceProvider */
    public function test_invalid_file_references_fail_closed(string $expectedCode, mixed $files): void
    {
        try {
            ChatGptMcpGateway::materializeReferences($files, null, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected a fail-closed file reference error.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame($expectedCode, $error->reasonCode());
        } finally {
            self::cleanup(glob(sys_get_temp_dir() . '/nhk-chatgpt-*') ?: []);
        }
    }

    public static function invalidReferenceProvider(): array
    {
        return [
            'opaque id' => ['PROVIDED_FILE_REFERENCE_UNRESOLVABLE', [['file_id' => 'file_only']]],
            'opaque live string' => ['PROVIDED_FILE_REFERENCE_UNRESOLVABLE', ['file_opaque']],
            'filesystem path' => ['PROVIDED_FILE_REFERENCE_UNRESOLVABLE', ['/mnt/data/foo.jpg']],
            'http live string' => ['PROVIDED_FILE_REFERENCE_UNRESOLVABLE', ['http://' . self::URL_HOST . '/file']],
            'http url' => ['CHATGPT_FILE_URL_REJECTED', [['download_url' => 'http://' . self::URL_HOST . '/file', 'file_id' => 'file_http']]],
            'private ip literal' => ['CHATGPT_FILE_URL_REJECTED', [['download_url' => 'https://127.0.0.1/file', 'file_id' => 'file_private']]],
            'extra field' => ['PROVIDED_FILE_REFERENCE_UNRESOLVABLE', [['download_url' => 'https://' . self::URL_HOST . '/file', 'file_id' => 'file_extra', 'path' => '/tmp/file']]],
            'too many files' => ['CHATGPT_FILE_COUNT_LIMIT', array_fill(0, 21, ['download_url' => 'https://' . self::URL_HOST . '/file', 'file_id' => 'file_many'])],
        ];
    }

    public function test_expired_or_unavailable_download_is_rejected_and_cleaned(): void
    {
        $path = null;
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://' . self::URL_HOST . '/expired', 'file_id' => 'file_expired'],
            ], static function (string $url, string $temporaryPath, int $remaining) use (&$path): array {
                $path = $temporaryPath;
                return ['status' => 410];
            }, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected the expired file reference to be rejected.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', $error->reasonCode());
        } finally {
            self::assertIsString($path);
            self::assertFileDoesNotExist($path);
        }
    }

    public function test_http_failure_preserves_a_typed_safe_cause_and_bounded_diagnostics(): void
    {
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://' . self::URL_HOST . '/expired?signature=secret', 'file_id' => 'file_expired'],
            ], static function (string $url, string $temporaryPath, int $remaining): array {
                return ['status' => 410, 'redirect_count' => 0];
            }, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected the HTTP failure to be rejected.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', $error->reasonCode());
            self::assertSame('PROVIDED_FILE_HTTP_STATUS', $error->safeReasonCode());
            self::assertSame('files.openai.test', $error->host());
            self::assertSame(410, $error->diagnostics()['http_status']);
            self::assertSame('download', $error->diagnostics()['stage']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) ($error->diagnostics()['correlation_id'] ?? ''));
            self::assertStringNotContainsString('signature=secret', $error->getMessage());
        }
    }

    public function test_empty_success_body_has_a_distinct_typed_cause(): void
    {
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://' . self::URL_HOST . '/empty', 'file_id' => 'file_empty'],
            ], static function (string $url, string $temporaryPath, int $remaining): array {
                touch($temporaryPath);
                return ['status' => 200, 'content_bytes_received' => 0];
            }, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected an empty response body to be rejected.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('PROVIDED_FILE_EMPTY_BODY', $error->safeReasonCode());
            self::assertSame('download', $error->diagnostics()['stage']);
            self::assertSame(0, $error->diagnostics()['content_bytes_received']);
        }
    }

    public function test_private_destination_diagnostic_contains_no_signed_url(): void
    {
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://private.test/file?signature=redacted', 'file_id' => 'file_secret'],
            ], null, null, static fn (string $host): array => ['10.0.0.8']);
            self::fail('Expected a private destination rejection.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_PRIVATE_IP_REJECTED', $error->reasonCode());
            self::assertStringNotContainsString('signature=redacted', $error->getMessage());
            self::assertStringNotContainsString('?', $error->getMessage());
        }
    }

    public function test_redirect_revalidates_each_target_host_before_download(): void
    {
        self::assertSame(
            'https://' . self::URL_HOST . '/next',
            ChatGptMcpGateway::validateRedirectTarget('https://' . self::URL_HOST . '/start', '/next', null, static fn (string $host): array => ['93.184.216.34'])
        );

        try {
            ChatGptMcpGateway::validateRedirectTarget(
                'https://' . self::URL_HOST . '/start',
                'https://attacker.test/next?fixture=redacted',
                null,
                static fn (string $host): array => ['10.0.0.8']
            );
            self::fail('Expected the redirect destination to be revalidated.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_PRIVATE_IP_REJECTED', $error->reasonCode());
            self::assertStringNotContainsString('fixture=redacted', $error->getMessage());
        }
    }

    public function test_relative_redirect_resolves_against_the_current_path_without_carrying_the_original_query(): void
    {
        self::assertSame(
            'https://' . self::URL_HOST . '/signed/next?fresh=1',
            ChatGptMcpGateway::validateRedirectTarget(
                'https://' . self::URL_HOST . '/signed/file.jpeg?signature=original',
                'next?fresh=1',
                null,
                static fn (string $host): array => ['93.184.216.34'],
            )
        );
        self::assertSame(
            'https://' . self::URL_HOST . '/signed/file.jpeg?replacement=1',
            ChatGptMcpGateway::validateRedirectTarget(
                'https://' . self::URL_HOST . '/signed/file.jpeg?signature=original',
                '?replacement=1',
                null,
                static fn (string $host): array => ['93.184.216.34'],
            )
        );
    }

    public function test_total_size_limit_and_actual_mime_sniff_are_enforced(): void
    {
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://' . self::URL_HOST . '/large', 'file_id' => 'file_large'],
            ], static function (string $url, string $path, int $remaining): array {
                $handle = fopen($path, 'c+b');
                self::assertIsResource($handle);
                ftruncate($handle, ChatGptMcpGateway::MAX_TOTAL_BYTES + 1);
                fclose($handle);
                return ['status' => 200];
            }, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected the total byte limit to reject the file.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_SIZE_LIMIT', $error->reasonCode());
        }

        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://' . self::URL_HOST . '/text', 'file_id' => 'file_text'],
            ], static function (string $url, string $path, int $remaining): array {
                file_put_contents($path, 'not an image');
                return ['status' => 200];
            }, null, static fn (string $host): array => ['93.184.216.34']);
            self::fail('Expected actual MIME sniffing to reject non-image bytes.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_MIME_REJECTED', $error->reasonCode());
        } finally {
            self::cleanup(glob(sys_get_temp_dir() . '/nhk-chatgpt-*') ?: []);
        }
    }

    public function test_gateway_proxy_converges_on_existing_easy_and_nhk_mcp_boundaries(): void
    {
        $gateway = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/ChatGptMcpGateway.php');
        $materializer = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/TrustedProvidedFileMaterializer.php');
        $ability = (string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpAbilityRegistration.php');

        self::assertStringContainsString('proxyRpcWithoutFiles', $gateway);
        self::assertStringContainsString('TrustedProvidedFileMaterializer::materialize', $gateway);
        self::assertStringContainsString("rest_get_server()->dispatch(\$proxy)", $gateway);
        self::assertStringContainsString("new \\WP_REST_Request('POST', '/nhk/v1/mcp')", $ability);
        self::assertStringContainsString("self::executeMcp(\$toolName, \$input)", $ability);
        self::assertStringContainsString('MAX_REDIRECTS', $materializer);
        self::assertStringContainsString('resolvePublicAddresses', $materializer);
        self::assertStringNotContainsString('wp_upload_media', $gateway);
        self::assertStringNotContainsString('wp_upload_media_from_url', $gateway);
        self::assertStringNotContainsString('base64_decode', $gateway);
        self::assertStringContainsString("array_is_list(\$provided)", $materializer);
        self::assertStringContainsString('CURLOPT_RESOLVE', $materializer);
        self::assertStringContainsString('CURLOPT_HTTPGET', $materializer);
        self::assertStringContainsString('CURL_HTTP_VERSION_2TLS', $materializer);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $materializer);
        self::assertStringContainsString('CURLOPT_PROXY =>', $materializer);
        self::assertStringNotContainsString('defaultHostPolicy', $materializer);
        self::assertStringContainsString("unset(\$params['arguments']['files'])", $gateway);
        self::assertStringContainsString('finally {', $gateway);
    }

    private static function gifBytes(): string
    {
        return hex2bin('47494638396101000100800000ffffff21f90401000001002c00000000010001000002024401003b') ?: '';
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) if (is_string($path) && is_file($path)) unlink($path);
    }
}
