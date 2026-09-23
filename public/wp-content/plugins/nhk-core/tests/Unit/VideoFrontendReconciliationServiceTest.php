<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoFrontendReconciliationService;
use NHK\Core\Application\Mcp\{McpDispatchRegistry, McpToolCatalog};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class VideoFrontendReconciliationServiceTest extends TestCase
{
    public function test_existing_canonical_video_has_a_runtime_frontend_reconciliation_entry_point(): void
    {
        self::assertTrue(class_exists(VideoFrontendReconciliationService::class));
        self::assertTrue(McpToolCatalog::has('nhk.video.frontend.reconcile'));
        self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler('nhk.video.frontend.reconcile'));
        self::assertSame('nhk.video.frontend.reconcile', McpDispatchRegistry::handlerKey('nhk.video.frontend.reconcile'));
        $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $item): bool => $item['name'] === 'nhk.video.frontend.reconcile'))[0];
        self::assertSame(['video_owner_id', 'idempotency_key', 'confirmed'], $tool['inputSchema']['required']);
    }

    public function test_valid_existing_owner_is_reconciled_without_creating_or_retrying_any_owner(): void
    {
        [$service, $videos] = $this->service();

        $result = $service->reconcile(self::VIDEO_ID, 'frontend-reconcile-1');

        self::assertSame('VERIFIED', $result['frontend_state']);
        self::assertTrue($result['canonical_reused']);
        self::assertSame(self::IDENTITY_ID, $result['public_identity']['identity_id']);
        self::assertSame('/video/field-watch/', $result['public_identity']['path']);
        self::assertTrue($result['projection']['valid']);
        self::assertSame('VERIFIED', $result['detail']['state']);
        self::assertSame('VERIFIED', $result['archive']['state']);
        self::assertSame('VERIFIED', $result['homepage']['state']);
        self::assertSame(0, $result['created_owner_count']);
        self::assertSame(0, $result['duplicate_count']);
        self::assertFalse($result['capture_retry']);
        $videos->expects(self::never())->method('create');
        $videos->expects(self::never())->method('update');
    }

    public function test_missing_frontend_projection_cannot_be_reported_verified(): void
    {
        [$service] = $this->service(['availability' => 'unavailable']);

        $result = $service->reconcile(self::VIDEO_ID, 'frontend-reconcile-blocked');

        self::assertSame('REVIEW_REQUIRED', $result['frontend_state']);
        self::assertFalse($result['projection']['valid']);
        self::assertContains('SOURCE_UNAVAILABLE', $result['blockers']);
        self::assertSame('NOT_APPLICABLE', $result['archive']['state']);
    }

    public function test_detail_only_readback_cannot_be_reported_verified(): void
    {
        [$service] = $this->service([], false, false);

        $result = $service->reconcile(self::VIDEO_ID, 'frontend-reconcile-detail-only');

        self::assertSame('VERIFIED', $result['detail']['state']);
        self::assertSame('REVIEW_REQUIRED', $result['frontend_state']);
        self::assertContains('VIDEO_ARCHIVE_READBACK_FAILED', $result['blockers']);
    }

    public function test_repeated_reconciliation_is_deterministic_and_does_not_duplicate(): void
    {
        [$service] = $this->service();

        $first = $service->reconcile(self::VIDEO_ID, 'frontend-reconcile-repeat');
        $second = $service->reconcile(self::VIDEO_ID, 'frontend-reconcile-repeat');

        self::assertSame($first, $second);
        self::assertSame(0, $second['created_owner_count']);
        self::assertSame(0, $second['duplicate_count']);
    }

    private const VIDEO_ID = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22';
    private const IDENTITY_ID = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f23';

    /** @return array{VideoFrontendReconciliationService,\PHPUnit\Framework\MockObject\MockObject} */
    private function service(array $sourceDelta = [], bool $archiveVisible = true, bool $homeVisible = true): array
    {
        $video = new Video(self::VIDEO_ID, 'youtube', 'abcDEF12345', 'https://www.youtube.com/watch?v=abcDEF12345', 'Field watch', [
            'source_snapshot' => array_merge(['availability' => 'available', 'embeddable' => true, 'provenance' => ['kind' => 'youtube']], $sourceDelta),
            'editorial' => ['title' => 'Field watch', 'summary' => 'A public video'],
            'category' => ['primary' => ['key' => '01']],
            'semantic_attachments' => [['target_type' => 'model', 'target_uuid' => self::VIDEO_ID, 'evidence_refs' => [['evidence_id' => self::IDENTITY_ID]]]],
        ]);
        $videos = $this->createMock(VideoRepository::class);
        $videos->method('findByCanonicalId')->with(self::VIDEO_ID)->willReturn($video);
        $identity = $this->createMock(PublicIdentityRepository::class);
        $identity->method('findCurrentByOwner')->with('video', self::VIDEO_ID, 'video')->willReturn([
            'identity_id' => self::IDENTITY_ID,
            'owner_kind' => 'video',
            'owner_id' => self::VIDEO_ID,
            'route_type' => 'video',
            'current_slug' => 'field-watch',
            'current_path' => '/video/field-watch/',
        ]);
        $detail = static fn (string $id): array => ['canonical_id' => $id, 'public_url' => '/video/field-watch/'];
        $route = static fn (string $slug): array => ['canonical_id' => self::VIDEO_ID, 'public_url' => '/video/' . $slug . '/'];
        $archive = static function (int $page, int $perPage) use ($archiveVisible): array { return ['items' => $archiveVisible ? [['canonical_id' => self::VIDEO_ID, 'public_url' => '/video/field-watch/']] : []]; };
        $home = static function () use ($homeVisible): array { return ['videos_total' => $homeVisible ? 1 : 0, 'videos' => $homeVisible ? [['canonical_id' => self::VIDEO_ID, 'url' => '/video/field-watch/']] : []]; };

        return [new VideoFrontendReconciliationService($videos, $identity, $detail, $route, $archive, $home), $videos];
    }
}
