<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Search\SearchSemanticQuery;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class SearchSemanticQueryTest extends TestCase
{
    public function test_semantic_groups_are_paginated_and_report_totals(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        for ($index = 1; $index <= 14; $index++) $authority->create('brand', 'search-clock-' . $index, 'Clock ' . $index);

        $query = new SearchSemanticQuery(
            $authorityRepository,
            new class implements MediaRepository {
                public function findByCanonicalId(string $id): ?Media { return null; }
                public function findByStableKey(string $key): ?Media { return null; }
                public function create(Media $item): Media { return $item; }
                public function update(Media $item, int $expectedRevision): Media { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            new class implements VideoRepository {
                public function findByCanonicalId(string $id): ?Video { return null; }
                public function findByExternalReference(string $platform, string $id): ?Video { return null; }
                public function create(Video $item): Video { return $item; }
                public function update(Video $item, int $expectedRevision): Video { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            new class implements KnowledgeRepository {
                public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
                public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
                public function create(KnowledgeClaim $item): KnowledgeClaim { return $item; }
                public function update(KnowledgeClaim $item, int $expectedRevision): KnowledgeClaim { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            $types,
        );

        $result = $query->extend(['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []], 'clock', 2, 5);

        self::assertCount(5, $result['entities']);
        self::assertSame('Clock 6', $result['entities'][0]['title']);
        self::assertSame(14, $result['_totals']['entities']);

        $empty = $query->extend(['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []], '   ');
        self::assertSame(0, $empty['_totals']['entities']);
        self::assertSame([], $empty['entities']);
    }

    public function test_semantic_search_does_not_match_unregistered_payload_fields(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, ['description']));
        $authorityRepository = new InMemoryAuthorityRepository();
        $authorityRepository->create(new AuthorityEntity(UuidCodec::newV7(), 'brand', 'search-private-only', 'Visible name', 1, ['description' => 'safe', 'private_note' => 'do-not-index']));
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $item): Media { return $item; } public function update(Media $item, int $expectedRevision): Media { return $item; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyVideo = new class implements VideoRepository { public function findByCanonicalId(string $id): ?Video { return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $item): Video { return $item; } public function update(Video $item, int $expectedRevision): Video { return $item; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyKnowledge = new class implements KnowledgeRepository { public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $item): KnowledgeClaim { return $item; } public function update(KnowledgeClaim $item, int $expectedRevision): KnowledgeClaim { return $item; } public function list(bool $includeRetired = false): array { return []; } };
        $result = (new SearchSemanticQuery($authorityRepository, $emptyMedia, $emptyVideo, $emptyKnowledge, $types))->extend(['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []], 'do-not-index');
        self::assertSame(0, $result['_totals']['entities']);
    }

    public function test_semantic_search_hides_invalid_public_video_references(): void
    {
        $invalid = new Video(UuidCodec::newV7(), 'vimeo', 'bad-reference', 'https://vimeo.com/bad-reference', 'clock');
        $videos = new class($invalid) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $item): Video { return $item; }
            public function update(Video $item, int $expectedRevision): Video { return $item; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $emptyMedia = new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $item): Media { return $item; } public function update(Media $item, int $expectedRevision): Media { return $item; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyKnowledge = new class implements KnowledgeRepository { public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $item): KnowledgeClaim { return $item; } public function update(KnowledgeClaim $item, int $expectedRevision): KnowledgeClaim { return $item; } public function list(bool $includeRetired = false): array { return []; } };
        $result = (new SearchSemanticQuery(new InMemoryAuthorityRepository(), $emptyMedia, $videos, $emptyKnowledge, new EntityTypeRegistry()))->extend(['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []], 'clock');
        self::assertSame([], $result['videos']);
        self::assertSame(0, $result['_totals']['videos']);
    }
}
