<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpAppDiagnostics;
use PHPUnit\Framework\TestCase;

final class McpAppDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        McpAppDiagnostics::reset();
    }

    public function test_records_only_sanitized_wire_metadata_and_evicts_old_events(): void
    {
        for ($index = 1; $index <= 21; $index++) {
            McpAppDiagnostics::record(
                [
                    'jsonrpc' => '2.0',
                    'id' => $index,
                    'method' => 'resources/read',
                    'params' => ['uri' => 'ui://nhk/image-upload/v3.html'],
                    'credentials' => 'must-not-leak',
                ],
                [
                    'jsonrpc' => '2.0',
                    'id' => $index,
                    'result' => [
                        'contents' => [[
                            'uri' => 'ui://nhk/image-upload/v3.html',
                            'mimeType' => 'text/html;profile=mcp-app',
                            'text' => '<p>user content must not be stored</p>',
                        ]],
                        'secret' => 'must-not-leak',
                    ],
                    'user_content' => 'must-not-leak',
                ],
                200,
                true,
            );
        }

        $events = McpAppDiagnostics::latest();

        self::assertCount(20, $events);
        self::assertSame(2, $events[0]['request_id']);
        self::assertSame(21, $events[19]['request_id']);
        self::assertSame(['contents', 'secret'], $events[19]['result_top_level_keys']);
        self::assertTrue($events[19]['result_contents_exists']);
        self::assertSame(1, $events[19]['contents_count']);
        self::assertSame('ui://nhk/image-upload/v3.html', $events[19]['contents_0_uri']);
        self::assertSame('text/html;profile=mcp-app', $events[19]['contents_0_mime_type']);
        self::assertSame(strlen('<p>user content must not be stored</p>'), $events[19]['contents_0_text_byte_length']);
        self::assertArrayNotHasKey('credentials', $events[19]);
        self::assertArrayNotHasKey('secret', $events[19]);
        self::assertArrayNotHasKey('user_content', $events[19]);
    }

    public function test_sanitizes_untrusted_json_rpc_error_message(): void
    {
        McpAppDiagnostics::record(
            ['jsonrpc' => '2.0', 'id' => 'x', 'method' => 'tools/call'],
            ['jsonrpc' => '2.0', 'id' => 'x', 'error' => ['code' => -32602, 'message' => 'secret user prompt: do not retain this']],
            400,
            false,
        );

        $event = McpAppDiagnostics::latest()[0];

        self::assertSame(-32602, $event['jsonrpc_error_code']);
        self::assertSame('REDACTED', $event['jsonrpc_error_message']);
    }
}
