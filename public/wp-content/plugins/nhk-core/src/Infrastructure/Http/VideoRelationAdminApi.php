<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Video\VideoRelationAdminService;

final class VideoRelationAdminApi
{
    public function __construct(private VideoRelationAdminService $service) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/admin/video-relation/context/(?P<video>[0-9A-Fa-f-]{36})', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance'), 'callback' => fn (\WP_REST_Request $request) => $this->context($request)]);
        register_rest_route('nhk/v1', '/admin/video-relation', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_create_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->create($request)]);
    }

    private function context(\WP_REST_Request $request): array|\WP_Error
    {
        try {
            return $this->service->context((string) $request['video']);
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function create(\WP_REST_Request $request): array|\WP_Error
    {
        try {
            $body = $request->get_json_params(); $body = is_array($body) ? $body : [];
            return $this->service->create((string) ($body['video_id'] ?? ''), sanitize_key((string) ($body['target_type'] ?? '')), (string) ($body['target_id'] ?? ''), (string) get_current_user_id());
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function error(\Throwable $error): \WP_Error { $status = $error instanceof \InvalidArgumentException ? 400 : 500; return new \WP_Error('nhk_video_relation_error', $error->getMessage(), ['status' => $status]); }
}
