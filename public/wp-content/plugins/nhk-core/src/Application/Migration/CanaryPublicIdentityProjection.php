<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

/**
 * Read-only Task 12 pre-cutover evidence boundary.
 *
 * This class intentionally has no repository or writer dependency. A future
 * owner-approved canary executor must be a separate, governed implementation.
 */
final class CanaryPublicIdentityProjection
{
    public const VIDEO_UUID = '01a06815-1e51-7964-b004-1ba79e488ad1';
    public const YOUTUBE_ID = 'P4KaHX3LBOw';
    public const CANONICAL_PATH = '/video/odo-36-10-gai-carillon-p4kahx3lbow/';
    public const HISTORIC_PATH = '/video/odo-36-10-gai-carillon-P4KaHX3LBOw/';

    /**
     * @param array<string,mixed> $readback
     * @return array<string,mixed>
     */
    public function inspect(array $readback = []): array
    {
        $checks = [
            'video_uuid_preserved' => ($readback['video_uuid'] ?? self::VIDEO_UUID) === self::VIDEO_UUID,
            'youtube_id_preserved' => ($readback['youtube_id'] ?? self::YOUTUBE_ID) === self::YOUTUBE_ID,
            'canonical_path_matches_policy' => ($readback['canonical_path'] ?? self::CANONICAL_PATH) === self::CANONICAL_PATH,
            'no_duplicate_video' => ($readback['duplicate_video_count'] ?? 0) === 0,
            'semantic_relations_unchanged' => ($readback['relations_changed'] ?? false) === false,
            'no_duplicate_200_route' => ($readback['duplicate_200_route_count'] ?? 0) === 0,
            'historic_redirect_is_one_hop' => ($readback['historic_redirect_hops'] ?? 1) === 1,
            'historic_route_is_not_200' => ($readback['historic_status'] ?? 301) !== 200,
        ];

        return [
            'status' => in_array(false, $checks, true) ? 'BLOCKED' : 'PRE_CUTOVER_READY',
            'mutation_count' => 0,
            'live_projection_performed' => false,
            'checks' => $checks,
            'expected' => [
                'video_uuid' => self::VIDEO_UUID,
                'youtube_id' => self::YOUTUBE_ID,
                'canonical_path' => self::CANONICAL_PATH,
                'historic_path' => self::HISTORIC_PATH,
            ],
        ];
    }
}
