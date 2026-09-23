<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Video\{VideoService, YouTubeUrlNormalizer};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class VideoExternalIdentityTest extends TestCase
{
    /** @dataProvider equivalentUrlProvider */
    public function test_equivalent_youtube_urls_share_one_normalized_external_identity(string $url): void
    {
        $identity = YouTubeUrlNormalizer::normalize($url);

        self::assertSame('youtube', $identity->platform);
        self::assertSame('dQw4w9WgXcQ', $identity->videoId);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $identity->canonicalUrl);
    }

    public function test_video_service_reuses_existing_owner_for_equivalent_url_and_creates_unrelated_identity_once(): void
    {
        $repository = new class implements VideoRepository {
            /** @var list<Video> */
            public array $items = [];

            public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $externalId) return $item; return null; }
            public function create(Video $video): Video { $this->items[] = $video; return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        $service = new VideoService($repository);

        $first = $service->ingestUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'First');
        $reused = $service->ingestUrl('https://youtu.be/dQw4w9WgXcQ?feature=share', 'Changed title');
        $other = $service->ingestUrl('https://youtube.com/shorts/9bZkp7q19f0?feature=share', 'Other');

        self::assertSame($first->canonicalId, $reused->canonicalId);
        self::assertSame(2, count($repository->items));
        self::assertNotSame($first->canonicalId, $other->canonicalId);
    }

    public static function equivalentUrlProvider(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ?feature=share'],
            ['https://youtube.com/shorts/dQw4w9WgXcQ?feature=share'],
            ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&si=example'],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=1'],
        ];
    }
}
