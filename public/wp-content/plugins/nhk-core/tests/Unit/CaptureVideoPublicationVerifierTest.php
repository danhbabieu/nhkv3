<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureVideoPublicationVerifier;
use NHK\Core\Application\PublicIdentity\PublicIdentityService;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureVideoPublicationVerifierTest extends TestCase
{
    public function test_video_readback_and_identity_are_verified_independently_of_article_blockers(): void
    {
        $videoId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $identityRepository = new InMemoryCaptureIdentityRepository();
        $videos = $this->createMock(VideoRepository::class);
        $videos->method('findByCanonicalId')->with($videoId)->willReturn(new Video(
            $videoId,
            'youtube',
            'abcdefghijk',
            'https://www.youtube.com/watch?v=abcdefghijk',
            'Video A',
            [
                'editorial' => [
                    'title' => 'Video A',
                    'summary' => 'Bản ghi cho thấy các chi tiết nhận diện chính của hiện vật được chọn.',
                    'body' => 'Video này ghi lại đúng hiện vật trong một nguồn tham chiếu cụ thể. Người đọc có thể đối chiếu mặt số, cấu hình và âm thanh được mô tả mà không suy rộng những quan sát riêng thành đặc tính của toàn bộ dòng đồng hồ. Các dữ kiện khác cần được kiểm tra qua nguồn canonical phù hợp.',
                    'why_this_matters' => 'Trang giúp tách dữ kiện của hiện vật khỏi bối cảnh chung trước khi nhận diện sâu hơn.',
                ],
                'hub' => ['primary' => ['key' => '01', 'label' => 'Tri thức']],
                'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => $evidenceId]]]],
            ],
        ));
        $service = new CaptureVideoPublicationVerifier(
            $videos,
            new PublicIdentityService($identityRepository, static fn (string $slug): bool => false),
            $identityRepository,
        );

        $result = $service->verify([
            'capture_id' => UuidCodec::newV7(),
            'assets' => [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['operation' => 'ingest']]],
            'article_publication' => ['eligible' => false, 'blockers' => ['MEDIAUSAGE_INCOMPLETE', 'CATEGORY_UNRESOLVED']],
        ]);

        self::assertSame('verified', $result['status']);
        self::assertSame('/video/video-a/', $result['items'][0]['public_identity']['path']);
        self::assertSame($videoId, $identityRepository->ownerId);
    }

    public function test_existing_video_without_identity_is_not_silently_backfilled(): void
    {
        $videoId = UuidCodec::newV7();
        $videos = $this->createMock(VideoRepository::class);
        $videos->method('findByCanonicalId')->willReturn(new Video($videoId, 'youtube', 'abcdefghijk', 'https://www.youtube.com/watch?v=abcdefghijk', 'Video A', ['editorial' => ['title' => 'Video A', 'summary' => 'Bản ghi cho thấy chi tiết nhận diện của hiện vật.', 'body' => 'Video này ghi lại đúng hiện vật trong nguồn tham chiếu. Các chi tiết quan sát được cần được tách khỏi bối cảnh chung để người đọc kiểm tra thêm mà không suy rộng mô tả riêng thành đặc tính phổ quát của dòng đồng hồ.', 'why_this_matters' => 'Trang giữ phạm vi rõ ràng giữa hiện vật và tri thức chung.'], 'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]]], null, true));
        $identityRepository = new InMemoryCaptureIdentityRepository();
        $service = new CaptureVideoPublicationVerifier($videos, new PublicIdentityService($identityRepository, static fn (string $slug): bool => false), $identityRepository);

        $result = $service->verify(['capture_id' => UuidCodec::newV7(), 'assets' => [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['operation' => 'update']]]]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('PUBLIC_IDENTITY_NOT_PERSISTED', $result['blockers']);
        self::assertNull($identityRepository->ownerId);
    }

    public function test_video_requires_active_about_edge_readback_when_graph_is_available(): void
    {
        $videoId = UuidCodec::newV7();
        $identityRepository = new InMemoryCaptureIdentityRepository();
        $videos = $this->createMock(VideoRepository::class);
        $videos->method('findByCanonicalId')->willReturn(new Video(
            $videoId,
            'youtube',
            'abcdefghijk',
            'https://www.youtube.com/watch?v=abcdefghijk',
            'Video A',
            ['editorial' => ['title' => 'Video A', 'summary' => 'Bản ghi cho thấy chi tiết nhận diện của hiện vật.', 'body' => 'Video này ghi lại đúng hiện vật trong nguồn tham chiếu. Các chi tiết quan sát được cần được tách khỏi bối cảnh chung để người đọc kiểm tra thêm mà không suy rộng mô tả riêng thành đặc tính phổ quát của dòng đồng hồ.', 'why_this_matters' => 'Trang giữ phạm vi rõ ràng giữa hiện vật và tri thức chung.'], 'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]]],
        ));
        $service = new CaptureVideoPublicationVerifier($videos, new PublicIdentityService($identityRepository, static fn (string $slug): bool => false), $identityRepository, null, static fn (string $videoId, string $targetType, string $targetId): bool => false);

        $result = $service->verify(['capture_id' => UuidCodec::newV7(), 'assets' => [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['operation' => 'ingest']]]]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('VIDEO_ABOUT_RELATION_READBACK_MISSING', $result['blockers']);
        self::assertNull($identityRepository->ownerId);
    }

    public function test_exact_video_identity_and_actual_frontend_reader_are_independent_from_knowledge_text(): void
    {
        $videoId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $video = new Video($videoId, 'youtube', '4NmkQFrNeWQ', 'https://www.youtube.com/watch?v=4NmkQFrNeWQ', 'Video chính xác', [
            'editorial' => [
                'title' => 'Video chính xác',
                'summary' => 'Bản ghi cho thấy các chi tiết nhận diện chính của hiện vật được chọn.',
                'body' => 'Video này ghi lại đúng hiện vật trong nguồn tham chiếu. Nội dung giữ riêng các chi tiết quan sát được trên chiếc đồng hồ và phân biệt chúng với bối cảnh canonical, để người đọc có thể kiểm tra thêm mà không biến mô tả riêng thành đặc tính phổ quát.',
                'why_this_matters' => 'Trang tạo điểm đối chiếu có phạm vi rõ ràng giữa nguồn video và tri thức NHK.',
            ],
            'hub' => ['primary' => ['key' => '01']],
            'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => $evidenceId]]]],
        ]);
        $videos = $this->createMock(VideoRepository::class);
        $videos->method('findByCanonicalId')->willReturn($video);
        $videos->expects(self::once())->method('findByExternalReference')->with('youtube', '4NmkQFrNeWQ')->willReturn($video);
        $identityRepository = new InMemoryCaptureIdentityRepository();
        $service = new CaptureVideoPublicationVerifier(
            $videos,
            new PublicIdentityService($identityRepository, static fn (string $slug): bool => false),
            $identityRepository,
            null,
            null,
            static fn (string $id, string $path): bool => $id === $videoId && $path === '/video/video-chinh-xac/',
        );

        $result = $service->verify(['capture_id' => UuidCodec::newV7(), 'assets' => [[
            'kind' => 'video', 'video_id' => $videoId, 'platform' => 'youtube', 'external_id' => '4NmkQFrNeWQ',
            'video_proposal' => ['operation' => 'ingest'],
        ]]]);

        self::assertSame('verified', $result['status']);
        self::assertSame('4NmkQFrNeWQ', $result['items'][0]['external_id']);
        self::assertTrue($result['completion']['complete']);
    }
}

final class InMemoryCaptureIdentityRepository implements PublicIdentityRepository
{
    public ?string $ownerId = null;
    private ?array $identity = null;

    public function allocate(array $record, string $idempotencyKey): array
    {
        $this->ownerId = (string) $record['owner_id'];
        $slug = (string) $record['current_slug'];
        return $this->identity = ['identity_id' => UuidCodec::newV7(), 'owner_kind' => $record['owner_kind'], 'owner_id' => $this->ownerId, 'route_type' => $record['route_type'], 'collision_scope' => $record['collision_scope'], 'current_slug' => $slug, 'current_path' => $record['current_path'], 'revision' => 1];
    }
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return $record; }
    public function findCurrentById(string $identityId): ?array { return $this->identity; }
    public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return $this->identity !== null && $this->ownerId === $ownerId ? $this->identity : null; }
    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
    public function resolveHistoric(string $path): array { return []; }
}
