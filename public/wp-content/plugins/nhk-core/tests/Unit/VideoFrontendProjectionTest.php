<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoFrontendProjection;
use NHK\Core\Application\PublicIdentity\PublicIdentityReadRegistry;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class VideoFrontendProjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        PublicIdentityReadRegistry::register(null);
    }

    public function test_canonical_video_without_frontend_consumable_public_projection_is_blocked(): void
    {
        $video = $this->video(['source_snapshot' => ['availability' => 'available', 'embeddable' => true]]);
        $identities = new FrontendProjectionIdentityRepository();
        PublicIdentityReadRegistry::register($identities);

        $projection = (new VideoFrontendProjection())->project($video);

        self::assertNull($projection['item']);
        self::assertContains('PUBLIC_IDENTITY_NOT_PERSISTED', $projection['blockers']);
        self::assertFalse($projection['frontend_available']);
    }

    public function test_public_projection_is_deterministic_and_contains_one_canonical_owner(): void
    {
        $video = $this->video([
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'public_identity' => ['current_slug' => 'video-frontend'],
        ]);
        $identities = new FrontendProjectionIdentityRepository();
        $identities->identity = ['identity_id' => UuidCodec::newV7(), 'owner_kind' => 'video', 'owner_id' => $video->canonicalId, 'route_type' => 'video', 'current_slug' => 'video-frontend', 'current_path' => '/video/video-frontend/', 'revision' => 1];
        PublicIdentityReadRegistry::register($identities);

        $reader = new VideoFrontendProjection();
        $first = $reader->project($video);
        $second = $reader->project($video);

        self::assertTrue($first['frontend_available']);
        self::assertSame($first, $second);
        self::assertSame($video->canonicalId, $first['item']['canonical_id']);
        self::assertSame('/video/video-frontend/', $first['item']['public_url']);
        self::assertSame(1, substr_count((string) json_encode($first['item']), $video->canonicalId));
    }

    private function video(array $metadata): Video
    {
        return Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Frontend video', $metadata + [
            'editorial' => ['title' => 'Frontend video', 'summary' => 'Tóm tắt'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'TEST'],
            'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]],
        ]);
    }
}

final class FrontendProjectionIdentityRepository implements PublicIdentityRepository
{
    public ?array $identity = null;
    public function allocate(array $record, string $idempotencyKey): array { return $this->identity = $record; }
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return $this->identity = $record; }
    public function findCurrentById(string $identityId): ?array { return $this->identity; }
    public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return $this->identity; }
    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
    public function resolveHistoric(string $path): array { return []; }
}
