<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Projection\ClaimProjectionService;
use NHK\Core\Domain\Graph\NodeReference;

final class ProjectionAdminApi
{
    public function __construct(private ClaimProjectionService $projections) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/projection/(?P<node>[a-zA-Z0-9._:-]{1,191})/status', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance'), 'callback' => fn (\WP_REST_Request $request) => $this->status($request)]);
        register_rest_route('nhk/v1', '/projection/(?P<node>[a-zA-Z0-9._:-]{1,191})/rebuild', ['methods' => 'POST', 'permission_callback' => fn (\WP_REST_Request $request): bool => $this->canWrite($request), 'callback' => fn (\WP_REST_Request $request) => $this->rebuild($request)]);
        register_rest_route('nhk/v1', '/projection/(?P<node>[a-zA-Z0-9._:-]{1,191})/validate', ['methods' => 'POST', 'permission_callback' => fn (\WP_REST_Request $request): bool => $this->canWrite($request), 'callback' => fn (\WP_REST_Request $request) => $this->validate($request)]);
        register_rest_route('nhk/v1', '/projection/(?P<node>[a-zA-Z0-9._:-]{1,191})/publish', ['methods' => 'POST', 'permission_callback' => fn (\WP_REST_Request $request): bool => $this->canWrite($request), 'callback' => fn (\WP_REST_Request $request) => $this->publish($request)]);
        register_rest_route('nhk/v1', '/projection/(?P<node>[a-zA-Z0-9._:-]{1,191})/discard', ['methods' => 'POST', 'permission_callback' => fn (\WP_REST_Request $request): bool => $this->canWrite($request), 'callback' => fn (\WP_REST_Request $request) => $this->discard($request)]);
    }

    private function status(\WP_REST_Request $request): array { return $this->projections->getProjectionStatus((string) $request['node']); }

    private function rebuild(\WP_REST_Request $request): array|\WP_Error
    {
        try { $body = $request->get_json_params(); $body = is_array($body) ? $body : []; $revision = $this->projections->rebuild(new NodeReference((string) ($body['node_type'] ?? ''), (string) $request['node']), (string) ($body['canonical_url'] ?? ''), (string) ($body['h1'] ?? ''), ['per_section' => min(100, max(1, (int) ($body['per_section'] ?? 50)))]); return $revision->toArray(); } catch (\Throwable $error) { return $this->error('nhk_projection_rebuild_failed', $error); }
    }
    private function validate(\WP_REST_Request $request): array|\WP_Error { try { return $this->projections->validate((string) $request['node'], (int) $request->get_param('revision'))->toArray(); } catch (\Throwable $error) { return $this->error('nhk_projection_validation_failed', $error); } }
    private function publish(\WP_REST_Request $request): array|\WP_Error { try { return $this->projections->publish((string) $request['node'], (int) $request->get_param('revision'))->toArray(); } catch (\Throwable $error) { return $this->error('nhk_projection_publish_failed', $error); } }
    private function discard(\WP_REST_Request $request): array|\WP_Error { try { $this->projections->discard((string) $request['node'], (int) $request->get_param('revision')); return ['status' => 'discarded']; } catch (\Throwable $error) { return $this->error('nhk_projection_discard_failed', $error); } }
    private function error(string $code, \Throwable $error): \WP_Error { $unavailable = $error->getMessage() === 'PROJECTION_STORAGE_UNAVAILABLE' || $error->getMessage() === 'MIGRATION_016_SCHEMA_UNAVAILABLE'; return new \WP_Error($unavailable ? 'nhk_projection_storage_unavailable' : $code, $error->getMessage(), ['status' => $unavailable ? 503 : 400]); }
    private function canWrite(\WP_REST_Request $request): bool
    {
        if (!current_user_can('nhk_apply_proposals')) return false;
        $nonce = (string) $request->get_header('X-WP-Nonce');
        return !function_exists('wp_verify_nonce') ? true : ($nonce !== '' && (bool) wp_verify_nonce($nonce, 'wp_rest'));
    }
}
