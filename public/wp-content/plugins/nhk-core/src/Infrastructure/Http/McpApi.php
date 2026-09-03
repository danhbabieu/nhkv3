<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Application\Mcp\McpTransport;

final class McpApi
{
    public function __construct(private McpTransport $transport) {}

    public function register(): void
    {
        add_filter('rest_allowed_cors_headers', [self::class, 'allowedCorsHeaders']);
        register_rest_route('nhk/v1', '/mcp', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => fn (\WP_REST_Request $request) => $this->dispatch($request),
        ]);
    }

    /**
     * Let browser-based MCP clients send the protocol assertion headers used
     * by Streamable HTTP without widening the REST authentication surface.
     *
     * @param mixed $headers
     * @return array<int, string>
     */
    public static function allowedCorsHeaders(mixed $headers): array
    {
        $headers = is_array($headers) ? $headers : [];
        foreach (['MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'] as $header) {
            if (!in_array($header, $headers, true)) $headers[] = $header;
        }
        return $headers;
    }

    private function dispatch(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || $body === []) {
            foreach (['request', 'json', 'payload'] as $field) {
                $candidate = $request->get_param($field);
                if (!is_string($candidate) || trim($candidate) === '') continue;
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) { $body = $decoded; break; }
            }
        }
        if (!is_array($body)) {
            $decoded = json_decode($request->get_body(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $headers = $request->get_headers();
        $files = method_exists($request, 'get_file_params') ? $request->get_file_params() : [];
        $result = $this->transport->dispatch($body, is_array($headers) ? $headers : [], is_array($files) ? $files : []);
        return new \WP_REST_Response($result['body'], $result['status']);
    }
}
