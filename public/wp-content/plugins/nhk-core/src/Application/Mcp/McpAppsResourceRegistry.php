<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class McpAppsResourceRegistry
{
    public const IMAGE_UPLOAD_URI = 'ui://nhk/image-upload.html';

    /** @return array{resources:list<array<string,string>>} */
    public static function list(): array
    {
        return ['resources' => [[
            'uri' => self::IMAGE_UPLOAD_URI,
            'name' => 'NHK image uploader',
            'mimeType' => 'text/html;profile=mcp-app',
        ]]];
    }

    /** @return array{contents:list<array<string,mixed>>} */
    public static function read(string $uri): array
    {
        if ($uri !== self::IMAGE_UPLOAD_URI) throw new \InvalidArgumentException('MCP resource not found.');
        $path = dirname(__DIR__, 3) . '/resources/ui/image-upload.html';
        $text = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($text)) throw new \RuntimeException('MCP_UI_RESOURCE_UNAVAILABLE');
        return ['contents' => [[
            'uri' => self::IMAGE_UPLOAD_URI,
            'mimeType' => 'text/html;profile=mcp-app',
            'text' => $text,
            '_meta' => ['ui' => ['prefersBorder' => true]],
        ]]];
    }
}
