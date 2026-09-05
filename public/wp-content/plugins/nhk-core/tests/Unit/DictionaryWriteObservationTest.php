<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class DictionaryWriteObservationTest extends TestCase
{
    public function test_knowledge_write_notifies_lexical_observer_after_success(): void
    {
        $events = [];
        $claims = new class implements KnowledgeRepository {
            private array $items = [];
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { $this->items[$claim->canonicalId] = $claim; return $claim; }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { $this->items[$claim->canonicalId] = $claim; return $claim; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $key): ?Source { return null; }
            public function create(Source $s): Source { return $s; } public function update(Source $s, int $revision): Source { return $s; } public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $item): Evidence { return $item; } public function update(Evidence $item, int $revision): Evidence { return $item; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $service = new KnowledgeService($claims, $sources, $evidence, static function (string $kind, string $id, string $text, array $context) use (&$events): void { $events[] = compact('kind','id','text','context'); });
        $claim = $service->createClaim('clock.term', 'Côn lòng máng là cách gọi đang được nghiên cứu.', 'technical', ['subject_id' => 'subject-1']);
        self::assertSame('KNOWLEDGE', $events[0]['kind']);
        self::assertSame($claim->canonicalId, $events[0]['id']);
        self::assertStringContainsString('Côn lòng máng', $events[0]['text']);
    }

    public function test_video_write_notifies_lexical_observer_after_success(): void
    {
        $events = [];
        $repo = new class implements VideoRepository {
            private array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $externalId) return $item; return null; }
            public function create(Video $video): Video { $this->items[$video->canonicalId] = $video; return $video; }
            public function update(Video $video, int $expectedRevision): Video { $this->items[$video->canonicalId] = $video; return $video; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $service = new VideoService($repo, static function (string $kind, string $id, string $text, array $context) use (&$events): void { $events[] = compact('kind','id','text','context'); });
        $video = $service->ingestUrl('https://www.youtube.com/watch?v=SaLpWgitdSE', 'Cơ chế ngắt chuông đêm', ['source' => ['source_description' => 'Giải thích ngắt chuông đêm.']]);
        self::assertSame('VIDEO', $events[0]['kind']);
        self::assertSame($video->canonicalId, $events[0]['id']);
        self::assertStringContainsString('ngắt chuông đêm', $events[0]['text']);
    }
}
