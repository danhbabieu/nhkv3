<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class HomeSemanticQueryTest extends TestCase
{
    public function test_home_hides_invalid_public_video_references(): void
    {
        $invalid = new Video(UuidCodec::newV7(), 'vimeo', 'bad-reference', 'https://vimeo.com/bad-reference', 'Invalid');
        $videos = new class($invalid) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $media = new class implements MediaRepository {
            public function findByCanonicalId(string $id): ?Media { return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return []; }
        };

        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $media, $videos, new EntityTypeRegistry()))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertSame([], $modules['videos']);
    }
}
