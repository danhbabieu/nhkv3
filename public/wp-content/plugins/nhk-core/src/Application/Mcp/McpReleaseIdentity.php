<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/** Deterministic identity for the MCP surfaces that must ship together. */
final class McpReleaseIdentity
{
    public static function catalogVersion(): string
    {
        return hash('sha256', self::json(McpToolCatalog::tools()));
    }

    public static function resourceVersion(): string
    {
        $path = dirname(__DIR__, 3) . '/resources/ui/image-upload.html';
        $contentHash = is_file($path) ? (hash_file('sha256', $path) ?: '') : '';
        return hash('sha256', self::json(['resources' => McpAppsResourceRegistry::list(), 'content_sha256' => $contentHash]));
    }

    /** @param array<string,mixed> $identity */
    public static function hash(array $identity): string
    {
        unset($identity['release_identity']);
        ksort($identity);
        return hash('sha256', self::json($identity));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
