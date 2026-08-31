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
        register_rest_route('nhk/v1', '/mcp', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => fn (\WP_REST_Request $request) => $this->dispatch($request),
        ]);
    }

    private function dispatch(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $headers = $request->get_headers();
        $result = $this->transport->dispatch($body, is_array($headers) ? $headers : []);
        return new \WP_REST_Response($result['body'], $result['status']);
    }
}
