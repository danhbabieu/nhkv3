<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class PublicUrlArchitectureRegressionTest extends TestCase
{
    private const VIDEO_UUID = '01a06815-1e51-7964-b004-1ba79e488ad1';

    public function test_video_public_url_reads_central_public_identity_before_metadata_compatibility_slug(): void
    {
        $repository = new PublicUrlIdentityRepository([
            'video|' . self::VIDEO_UUID . '|video' => ['current_slug' => 'nha-kho-video-canonical'],
        ]);
        $video = new Video(self::VIDEO_UUID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'Video', [
            'public_identity' => ['current_slug' => 'metadata-stale-slug'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'Video', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222']],
        ]);

        $result = (new VideoUrlPolicy($repository))->project($video, new VideoPublicContextSelector());

        self::assertTrue($result['eligible']);
        self::assertSame('/video/nha-kho-video-canonical/', $result['path']);
        self::assertStringNotContainsString(strtolower($video->externalVideoId), strtolower((string) $result['path']));
    }

    public function test_historic_resolution_uses_current_owner_route_for_nested_entities(): void
    {
        $repository = new PublicUrlIdentityRepository([], [
            '/brand-cu/model-cu/' => [
                'status' => 'FOUND',
                'target' => '/model-moi/',
                'owner_kind' => 'authority',
                'owner_id' => '11111111-1111-4111-8111-111111111111',
                'route_type' => 'model',
            ],
        ]);
        $service = new HistoricPublicRouteService(
            $repository,
            static fn (string $ownerKind, string $ownerId, string $routeType): ?string => '/brand-moi/model-moi/',
        );

        self::assertSame(
            ['status' => 'FOUND', 'target' => '/brand-moi/model-moi/', 'hops' => 1],
            $service->resolveHistoric('/brand-cu/model-cu/'),
        );
    }

    public function test_video_slug_lookup_compares_against_full_video_namespace(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/MediaVideoPageQuery.php');
        self::assertStringContainsString("\$result['path'] === '/video/' . \$slug . '/'", $source);
        self::assertStringNotContainsString("\$result['path'] === '/' . \$slug . '/'", $source);
    }

    public function test_technical_media_asset_uuid_route_is_redirect_input_not_binary_canonical_delivery(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicMediaAssetRoutes.php');
        self::assertStringContainsString('legacyAssetRedirectTarget', $source);
        self::assertStringContainsString("wp_safe_redirect", $source);
        self::assertStringContainsString("'/anh/'", $source);
    }
}

final class PublicUrlIdentityRepository implements PublicIdentityRepository
{
    public function __construct(private array $owners = [], private array $historic = []) {}
    public function allocate(array $record, string $idempotencyKey): array { return $record; }
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return $record; }
    public function findCurrentById(string $identityId): ?array { return null; }
    public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return $this->owners[$ownerKind . '|' . $ownerId . '|' . $routeType] ?? null; }
    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
    public function resolveHistoric(string $path): array { return $this->historic[$path] ?? ['status' => 'NOT_FOUND']; }
}
