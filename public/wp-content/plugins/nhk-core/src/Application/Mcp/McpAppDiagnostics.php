<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/**
 * Temporary, process-local diagnostics for the Easy MCP Apps wire boundary.
 *
 * This class deliberately accepts wire-shaped arrays but copies only the
 * explicitly allowlisted metadata below. It never retains request or response
 * payloads, headers, cookies, tokens or content bodies.
 */
final class McpAppDiagnostics
{
    private const LIMIT = 20;

    /** @var list<array<string,mixed>> */
    private static array $events = [];

    /** @param array<string,mixed> $rpc @param array<string,mixed> $body */
    public static function record(array $rpc, array $body, int $status, bool $authenticated): void
    {
        $result = is_array($body['result'] ?? null) ? $body['result'] : null;
        $contents = is_array($result['contents'] ?? null) ? $result['contents'] : [];
        $first = is_array($contents[0] ?? null) ? $contents[0] : [];
        $capabilities = is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
        $extensions = is_array($capabilities['extensions'] ?? null) ? $capabilities['extensions'] : [];
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($body)
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        self::$events[] = [
            'timestamp' => gmdate('c'),
            'protocol_method' => is_string($rpc['method'] ?? null) ? $rpc['method'] : null,
            'request_id' => self::safeRequestId($rpc['id'] ?? null),
            'authenticated' => $authenticated,
            'http_status' => $status,
            'requested_resource_uri' => self::safeString(is_array($rpc['params'] ?? null) ? ($rpc['params']['uri'] ?? null) : null, 500),
            'jsonrpc_error_code' => is_int($error['code'] ?? null) || is_string($error['code'] ?? null) ? $error['code'] : null,
            'jsonrpc_error_message' => self::sanitizeErrorMessage($error['message'] ?? null),
            'response_body_byte_length' => is_string($encoded) ? strlen($encoded) : 0,
            'response_top_level_keys' => self::keys($body),
            'result_top_level_keys' => $result === null ? [] : self::keys($result),
            'advertised_extension_names' => self::stringKeys($extensions),
            'result_contents_exists' => is_array($result) && array_key_exists('contents', $result),
            'contents_count' => count($contents),
            'contents_0_uri' => self::safeString($first['uri'] ?? null, 500),
            'contents_0_mime_type' => self::safeString($first['mimeType'] ?? null, 120),
            'contents_0_text_byte_length' => is_string($first['text'] ?? null) ? strlen($first['text']) : 0,
        ];

        if (count(self::$events) > self::LIMIT) array_shift(self::$events);
    }

    /** @return list<array<string,mixed>> */
    public static function latest(): array
    {
        return self::$events;
    }

    public static function reset(): void
    {
        self::$events = [];
    }

    /** @param array<string,mixed> $value @return list<string> */
    private static function keys(array $value): array
    {
        return array_values(array_map('strval', array_keys($value)));
    }

    /** @param array<string,mixed> $value @return list<string> */
    private static function stringKeys(array $value): array
    {
        return self::keys($value);
    }

    private static function safeRequestId(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    private static function safeString(mixed $value, int $limit): ?string
    {
        if (!is_string($value) || $value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private static function sanitizeErrorMessage(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $message = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        foreach ([
            'invalid request' => 'Invalid request.',
            'invalid params' => 'Invalid params.',
            'method not found' => 'Method not found.',
            'authentication' => 'Authentication failed.',
            'unauthorized' => 'Unauthorized.',
            'forbidden' => 'Forbidden.',
            'not found' => 'Not found.',
            'server error' => 'Server error.',
        ] as $needle => $safe) {
            if (str_contains($message, $needle)) return $safe;
        }
        return 'REDACTED';
    }
}
