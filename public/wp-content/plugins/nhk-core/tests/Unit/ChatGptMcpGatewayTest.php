<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\ChatGptMcpGateway;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGatewayException;
use PHPUnit\Framework\TestCase;

final class ChatGptMcpGatewayTest extends TestCase
{
    private const URL_HOST = 'files.openai.test';

    public function test_one_official_file_object_becomes_one_native_file_part(): void
    {
        $paths = [];
        $result = ChatGptMcpGateway::materializeReferences([
            ['download_url' => 'https://' . self::URL_HOST . '/one', 'file_id' => 'file_one', 'mime_type' => 'image/gif', 'file_name' => 'one.gif'],
        ], static function (string $url, string $path, int $remaining) use (&$paths): array {
            $paths[] = $path;
            file_put_contents($path, self::gifBytes());
            return ['status' => 200];
        }, static fn (string $host, string $url): bool => $host === self::URL_HOST);

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
        }, static fn (string $host, string $url): bool => $host === self::URL_HOST);

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
            ChatGptMcpGateway::materializeReferences($files, null, static fn (string $host, string $url): bool => $host === self::URL_HOST);
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
            'arbitrary url' => ['CHATGPT_FILE_HOST_NOT_ALLOWED', [['download_url' => 'https://attacker.test/file', 'file_id' => 'file_attacker']]],
            'http url' => ['CHATGPT_FILE_URL_REJECTED', [['download_url' => 'http://' . self::URL_HOST . '/file', 'file_id' => 'file_http']]],
            'private host' => ['CHATGPT_FILE_HOST_NOT_ALLOWED', [['download_url' => 'https://127.0.0.1/file', 'file_id' => 'file_private']]],
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
            }, static fn (string $host, string $url): bool => $host === self::URL_HOST);
            self::fail('Expected the expired file reference to be rejected.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', $error->reasonCode());
        } finally {
            self::assertIsString($path);
            self::assertFileDoesNotExist($path);
        }
    }

    public function test_rejected_host_diagnostic_contains_only_normalized_host(): void
    {
        try {
            ChatGptMcpGateway::materializeReferences([
                ['download_url' => 'https://ATTACKER.test/file?X-Amz-Signature=secret', 'file_id' => 'file_secret'],
            ], null, static fn (string $host, string $url): bool => false);
            self::fail('Expected an allowlist rejection.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_HOST_NOT_ALLOWED', $error->reasonCode());
            self::assertSame('attacker.test', $error->host());
            self::assertSame('CHATGPT_FILE_HOST_NOT_ALLOWED host=attacker.test', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringNotContainsString('?', $error->getMessage());
        }
    }

    public function test_redirect_revalidates_each_target_host_before_download(): void
    {
        $allowlist = static fn (string $host, string $url): bool => $host === self::URL_HOST;
        self::assertSame(
            'https://' . self::URL_HOST . '/next',
            ChatGptMcpGateway::validateRedirectTarget('https://' . self::URL_HOST . '/start', '/next', $allowlist)
        );

        try {
            ChatGptMcpGateway::validateRedirectTarget(
                'https://' . self::URL_HOST . '/start',
                'https://attacker.test/next?sig=secret',
                $allowlist
            );
            self::fail('Expected the redirect host to be revalidated.');
        } catch (ChatGptMcpGatewayException $error) {
            self::assertSame('CHATGPT_FILE_HOST_NOT_ALLOWED', $error->reasonCode());
            self::assertSame('attacker.test', $error->host());
            self::assertStringNotContainsString('sig=secret', $error->getMessage());
        }
    }

    public function test_runtime_allowlist_is_exact_and_rejects_siblings_and_subdomains(): void
    {
        $method = new \ReflectionMethod(ChatGptMcpGateway::class, 'isExactAllowlistedHost');
        self::assertTrue($method->invoke(null, 'sdmntpraustraliaeast.oaiusercontent.com', ['sdmntpraustraliaeast.oaiusercontent.com']));
        self::assertFalse($method->invoke(null, 'sdmntpraustraliaeast2.oaiusercontent.com', ['sdmntpraustraliaeast.oaiusercontent.com']));
        self::assertFalse($method->invoke(null, 'child.sdmntpraustraliaeast.oaiusercontent.com', ['sdmntpraustraliaeast.oaiusercontent.com']));
        self::assertFalse($method->invoke(null, 'oaiusercontent.com', ['sdmntpraustraliaeast.oaiusercontent.com']));
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
            }, static fn (string $host, string $url): bool => $host === self::URL_HOST);
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
            }, static fn (string $host, string $url): bool => $host === self::URL_HOST);
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
        $ability = (string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpAbilityRegistration.php');

        self::assertStringContainsString('proxyRpcWithoutFiles', $gateway);
        self::assertStringContainsString("rest_get_server()->dispatch(\$proxy)", $gateway);
        self::assertStringContainsString("new \\WP_REST_Request('POST', '/nhk/v1/mcp')", $ability);
        self::assertStringContainsString("self::executeMcp(\$toolName, \$input)", $ability);
        self::assertStringContainsString("'redirection' => 0", $gateway);
        self::assertStringContainsString('MAX_REDIRECTS', $gateway);
        self::assertStringContainsString('self::validateUrl($current, $hostPolicy)', $gateway);
        self::assertStringNotContainsString('wp_upload_media', $gateway);
        self::assertStringNotContainsString('wp_upload_media_from_url', $gateway);
        self::assertStringNotContainsString('base64_decode', $gateway);
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
