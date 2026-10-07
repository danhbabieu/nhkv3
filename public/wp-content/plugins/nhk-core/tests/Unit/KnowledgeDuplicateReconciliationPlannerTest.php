<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeClaimIdentity;
use NHK\Core\Application\Knowledge\KnowledgeDuplicateReconciliationPlanner;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Video\VideoIdentityReader;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use PHPUnit\Framework\TestCase;

final class KnowledgeDuplicateReconciliationPlannerTest extends TestCase
{
    private const SUBJECT = '01a09786-dd67-70e7-9d30-9b8d3931766d';
    private const CLAIM_A = '01a09786-dd67-70e7-9d30-9b8d39317670';
    private const CLAIM_B = '01a09786-dd67-70e7-9d30-9b8d39317671';

    public function testPossibleDuplicateCannotProduceExecutableCommands(): void
    {
        $planner = $this->planner($this->claim(self::CLAIM_A, 'X dùng máy M'), $this->claim(self::CLAIM_B, 'X sử dụng máy M'));

        $result = $planner->plan(['classification' => 'POSSIBLE_DUPLICATE', 'canonical_ids' => [self::CLAIM_A, self::CLAIM_B]]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertFalse($result['apply']);
        self::assertSame([], $result['commands']);
    }

    public function testUnresolvedIdentityCannotProduceExecutableCommands(): void
    {
        $first = new KnowledgeClaim(self::CLAIM_A, 'claim-a', 'Claim A', 'fact', []);
        $second = new KnowledgeClaim(self::CLAIM_B, 'claim-b', 'Claim B', 'fact', []);
        $result = $this->planner($first, $second)->plan(['classification' => 'DEFINITE_DUPLICATE', 'canonical_ids' => [self::CLAIM_A, self::CLAIM_B]]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('KNOWLEDGE_IDENTITY_UNRESOLVED', $result['blockers']);
        self::assertSame([], $result['commands']);
    }

    public function testStaleRevisionOrDependencyBindingFailsClosed(): void
    {
        [$first, $second] = [$this->claim(self::CLAIM_A, 'X dùng máy M'), $this->claim(self::CLAIM_B, 'X dùng máy M')];
        $identity = KnowledgeClaimIdentity::resolveClaim($first);
        $result = $this->planner($first, $second)->plan([
            'classification' => 'DEFINITE_DUPLICATE',
            'canonical_ids' => [self::CLAIM_A, self::CLAIM_B],
            'identity_policy' => 'knowledge-identity-old',
            'identity_fingerprint' => $identity->fingerprint(),
            'record_revisions' => [self::CLAIM_A => 99, self::CLAIM_B => 1],
            'dependency_fingerprint' => str_repeat('a', 64),
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('KNOWLEDGE_IDENTITY_POLICY_STALE', $result['blockers']);
        self::assertContains('KNOWLEDGE_RECONCILIATION_REVISION_STALE', $result['blockers']);
        self::assertContains('KNOWLEDGE_DEPENDENCY_FINGERPRINT_STALE', $result['blockers']);
        self::assertSame([], $result['commands']);
    }

    public function testFreshEqualIdentityPlanIsReadOnlyAndRevisionBound(): void
    {
        [$first, $second] = [$this->claim(self::CLAIM_A, 'X dùng máy M'), $this->claim(self::CLAIM_B, 'X dùng máy M')];
        $identity = KnowledgeClaimIdentity::resolveClaim($first);
        $dependencyFingerprint = hash('sha256', json_encode(['evidence' => [], 'graph' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $result = $this->planner($first, $second)->plan([
            'classification' => 'DEFINITE_DUPLICATE',
            'canonical_ids' => [self::CLAIM_A, self::CLAIM_B],
            'identity_policy' => $identity->policyVersion(),
            'identity_fingerprint' => $identity->fingerprint(),
            'record_revisions' => [self::CLAIM_A => 1, self::CLAIM_B => 1],
            'dependency_fingerprint' => $dependencyFingerprint,
        ]);

        self::assertSame('SAFE_TO_RECONCILE', $result['status']);
        self::assertFalse($result['apply']);
        self::assertSame([self::CLAIM_A => 1, self::CLAIM_B => 1], $result['record_revisions']);
        self::assertNotEmpty($result['commands']);
        self::assertSame('retire', $result['commands'][0]['operation']);
    }

    public function testCanonicalVideoIdentityIsResolvedThroughReader(): void
    {
        $first = $this->videoClaim(self::CLAIM_A, 'video-canonical-a');
        $second = $this->videoClaim(self::CLAIM_B, 'video-canonical-a');
        $reader = new class implements VideoIdentityReader {
            public function findVideoIdentity(string $canonicalVideoId): ?array
            {
                return ['canonical_video_id' => $canonicalVideoId, 'platform' => 'youtube', 'external_video_id' => 'external-a'];
            }
        };
        $planner = $this->plannerWithReader($reader, $first, $second);
        $identity = KnowledgeClaimIdentity::resolveClaim($first, $reader);
        $dependencyFingerprint = hash('sha256', json_encode(['evidence' => [], 'graph' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $result = $planner->plan([
            'classification' => 'DEFINITE_DUPLICATE',
            'canonical_ids' => [self::CLAIM_A, self::CLAIM_B],
            'identity_policy' => $identity->policyVersion(),
            'identity_fingerprint' => $identity->fingerprint(),
            'record_revisions' => [self::CLAIM_A => 1, self::CLAIM_B => 1],
            'dependency_fingerprint' => $dependencyFingerprint,
        ]);

        self::assertSame('SAFE_TO_RECONCILE', $result['status']);
    }

    public function testCanonicalAndExternalVideoConflictRemainsReviewRequired(): void
    {
        $claim = new KnowledgeClaim(self::CLAIM_A, 'video-conflict', 'Video provenance.', 'provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => ['subject_id' => self::SUBJECT, 'canonical_video_id' => 'video-canonical-a', 'platform' => 'youtube', 'external_video_id' => 'external-other'],
        ]);
        $reader = new class implements VideoIdentityReader {
            public function findVideoIdentity(string $canonicalVideoId): ?array
            {
                return ['canonical_video_id' => $canonicalVideoId, 'platform' => 'youtube', 'external_video_id' => 'external-a'];
            }
        };
        $result = $this->plannerWithReader($reader, $claim, $this->videoClaim(self::CLAIM_B, 'video-canonical-a'))->plan([
            'classification' => 'DEFINITE_DUPLICATE',
            'canonical_ids' => [self::CLAIM_A, self::CLAIM_B],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('KNOWLEDGE_IDENTITY_CONFLICTING', $result['blockers']);
        self::assertSame([], $result['commands']);
    }

    private function claim(string $id, string $text): KnowledgeClaim
    {
        return new KnowledgeClaim($id, 'stable:' . $id, $text, 'fact', ['metadata' => ['subject_id' => self::SUBJECT, 'facet' => 'movement', 'scope' => 'variant']]);
    }

    private function videoClaim(string $id, string $videoId): KnowledgeClaim
    {
        return new KnowledgeClaim($id, 'stable:' . $id, 'Video provenance.', 'provenance', ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => self::SUBJECT, 'canonical_video_id' => $videoId, 'proposition_class' => 'VIDEO_CONCERNS_SUBJECT']]);
    }

    private function planner(KnowledgeClaim ...$claims): KnowledgeDuplicateReconciliationPlanner
    {
        return $this->plannerWithReader(null, ...$claims);
    }

    private function plannerWithReader(?VideoIdentityReader $reader, KnowledgeClaim ...$claims): KnowledgeDuplicateReconciliationPlanner
    {
        $claimRepository = new class($claims) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('planner must not write'); }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('planner must not write'); }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        $evidenceRepository = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $item): Evidence { throw new \LogicException('planner must not write'); }
            public function update(Evidence $item, int $revision): Evidence { throw new \LogicException('planner must not write'); }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        return new KnowledgeDuplicateReconciliationPlanner($claimRepository, $evidenceRepository, null, $reader);
    }
}
