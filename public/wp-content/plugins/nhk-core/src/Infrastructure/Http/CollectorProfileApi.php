<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Collector\CollectorProfileQuery;

/** Read-only HTTP adapter for the branch-scoped Collector Profile projection. */
final class CollectorProfileApi
{
    public function __construct(private CollectorProfileQuery $profile) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/collector-profile/(?P<id>[0-9a-f-]{36})', [
            'methods' => 'GET',
            'permission_callback' => static fn (): bool => current_user_can('manage_options'),
            'args' => self::args(),
            'callback' => fn (\WP_REST_Request $request): array|\WP_Error => $this->request($request),
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    public static function args(): array
    {
        return [
            'page' => ['default' => 1, 'type' => 'integer', 'minimum' => 1],
            'per_page' => ['default' => 50, 'type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            'render_cap' => ['default' => 0, 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function read(string $classificationId, int $page = 1, int $perPage = 50, int $renderCap = 0): array|\WP_Error
    {
        $result = $this->profile->build($classificationId, $page, $perPage, $renderCap);
        if (($result['status'] ?? '') === 'unavailable' && class_exists('WP_Error')) {
            return new \WP_Error('nhk_collector_profile_unavailable', 'Collector profile is not available.', ['status' => 503, 'reason' => $result['reason'] ?? 'PROFILE_UNAVAILABLE']);
        }
        return $result;
    }

    /** @return array<string,mixed>|\WP_Error */
    private function request(\WP_REST_Request $request): array|\WP_Error
    {
        return $this->read(
            (string) $request['id'],
            max(1, (int) ($request['page'] ?? 1)),
            min(200, max(1, (int) ($request['per_page'] ?? 50))),
            min(10000, max(0, (int) ($request['render_cap'] ?? 0))),
        );
    }
}
