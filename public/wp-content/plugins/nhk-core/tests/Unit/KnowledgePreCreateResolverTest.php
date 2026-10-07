<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgePreCreateResolver;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use PHPUnit\Framework\TestCase;

final class KnowledgePreCreateResolverTest extends TestCase
{
    private const SUBJECT = '01a09786-dd67-70e7-9d30-9b8d3931766d';
    private const VIDEO = 'dQw4w9WgXcQ';

    public function testEquivalentVideoProvenanceReusesAcrossDifferentWordingAndRequestKey(): void
    {
        $claim = new KnowledgeClaim('01a09786-dd67-70e7-9d30-9b8d39317670', 'legacy-video-claim', 'The source identifies this Video as concerning canonical Variant A.', 'provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => ['subject_id' => self::SUBJECT, 'platform' => 'youtube', 'external_video_id' => self::VIDEO, 'proposition_class' => 'VIDEO_CONCERNS_SUBJECT', 'source_locator' => 'https://youtu.be/' . self::VIDEO],
        ]);
        $resolver = new KnowledgePreCreateResolver($this->claims([$claim]), $this->sources(), $this->evidence());

        $result = $resolver->resolveClaimCreate('new-request-key', 'This canonical Video is identified as concerning Variant A.', 'provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => ['subject_id' => self::SUBJECT, 'platform' => 'youtube', 'external_video_id' => self::VIDEO, 'proposition_class' => 'VIDEO_CONCERNS_SUBJECT', 'source_locator' => 'https://www.youtube.com/watch?v=' . self::VIDEO . '&utm_source=test'],
        ]);

        self::assertSame('REUSE_EXISTING', $result->action);
        self::assertSame($claim->canonicalId, $result->targetId());
    }

    public function testSameVideoDifferentSubjectDoesNotReuseClaim(): void
    {
        $claim = new KnowledgeClaim('01a09786-dd67-70e7-9d30-9b8d39317670', 'video-subject-a', 'Video provenance.', 'provenance', ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => self::SUBJECT, 'platform' => 'youtube', 'external_video_id' => self::VIDEO]]);
        $resolver = new KnowledgePreCreateResolver($this->claims([$claim]), $this->sources(), $this->evidence());
        $result = $resolver->resolveClaimCreate('video-subject-b', 'Video provenance.', 'provenance', ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => '01a09786-dd67-70e7-9d30-9b8d39317671', 'platform' => 'youtube', 'external_video_id' => self::VIDEO]]);

        self::assertSame('CREATE_NEW', $result->action);
    }

    public function testLegacyMissingIdentityDoesNotReuseDifferentStableKeyByText(): void
    {
        $claim = new KnowledgeClaim('01a09786-dd67-70e7-9d30-9b8d39317670', 'legacy-claim', 'Legacy text.', 'fact', []);
        $resolver = new KnowledgePreCreateResolver($this->claims([$claim]), $this->sources(), $this->evidence());

        $result = $resolver->resolveClaimCreate('new-key', 'Legacy text.', 'fact', []);

        self::assertSame('REVIEW_REQUIRED', $result->action);
        self::assertSame('KNOWLEDGE_IDENTITY_UNRESOLVED', $result->diagnostics['reason']);
    }

    public function testLegacyStableKeyReplayRemainsIdempotentWhenPayloadIsIdentical(): void
    {
        $provenance = ['origin' => 'LEGACY', 'metadata' => ['source_locator' => 'https://example.test/legacy']];
        $claim = new KnowledgeClaim('01a09786-dd67-70e7-9d30-9b8d39317670', 'legacy-claim', 'Legacy text.', 'fact', $provenance);
        $resolver = new KnowledgePreCreateResolver($this->claims([$claim]), $this->sources(), $this->evidence());

        $result = $resolver->resolveClaimCreate('legacy-claim', 'Legacy text.', 'fact', $provenance);

        self::assertSame('REUSE_EXISTING', $result->action);
        self::assertSame($claim->canonicalId, $result->targetId());
    }

    /** @param list<KnowledgeClaim> $items */
    private function claims(array $items): KnowledgeRepository
    {
        return new class($items) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('read-only test repository'); }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('read-only test repository'); }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function sources(): SourceRepository
    {
        return new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $key): ?Source { return null; }
            public function create(Source $item): Source { throw new \LogicException('read-only test repository'); }
            public function update(Source $item, int $revision): Source { throw new \LogicException('read-only test repository'); }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }

    private function evidence(): EvidenceRepository
    {
        return new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $item): Evidence { throw new \LogicException('read-only test repository'); }
            public function update(Evidence $item, int $revision): Evidence { throw new \LogicException('read-only test repository'); }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
    }
}
