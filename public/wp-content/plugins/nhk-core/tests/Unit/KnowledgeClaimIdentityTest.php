<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeClaimIdentity;
use NHK\Core\Contracts\Video\VideoIdentityReader;
use PHPUnit\Framework\TestCase;

final class KnowledgeClaimIdentityTest extends TestCase
{
    private const SUBJECT = '01a09786-dd67-70e7-9d30-9b8d3931766d';
    private const VIDEO = '01a09786-dd67-70e7-9d30-9b8d39317670';

    public function testOrdinaryClaimResolvesToCanonicalOwnerPacket(): void
    {
        $result = KnowledgeClaimIdentity::resolveInput('fact', [
            'metadata' => [
                'subject_id' => self::SUBJECT,
                'facet' => 'recognition',
                'scope' => 'variant',
                'proposition' => 'cọc đen',
            ],
        ]);

        self::assertSame('RESOLVED', $result->status());
        self::assertSame(['claim_type' => 'fact', 'facet' => 'recognition', 'proposition' => 'cọc đen', 'scope' => 'variant', 'subject_id' => self::SUBJECT], $result->packet());
        self::assertSame($result->fingerprint(), KnowledgeClaimIdentity::resolveInput('fact', [
            'metadata' => [
                'scope' => 'variant', 'proposition' => 'cọc đen', 'facet' => 'recognition', 'subject_id' => self::SUBJECT,
            ],
        ])->fingerprint());
    }

    public function testOrdinaryClaimMissingRequiredIdentityIsUnresolved(): void
    {
        $result = KnowledgeClaimIdentity::resolveInput('fact', ['metadata' => ['subject_id' => self::SUBJECT]]);

        self::assertSame('UNRESOLVED', $result->status());
        self::assertFalse($result->equivalentTo($result));
        self::assertContains('KNOWLEDGE_IDENTITY_REQUIRED_FIELD_MISSING', $result->reasonCodes());
    }

    public function testTwoMissingVideoReferentsNeverCompareEquivalent(): void
    {
        $first = KnowledgeClaimIdentity::resolveInput('provenance', ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => self::SUBJECT]]);
        $second = KnowledgeClaimIdentity::resolveInput('provenance', ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => self::SUBJECT]]);

        self::assertSame('UNRESOLVED', $first->status());
        self::assertSame('UNRESOLVED', $second->status());
        self::assertFalse($first->equivalentTo($second));
    }

    public function testCanonicalVideoUuidResolvesToPlatformAndExternalId(): void
    {
        $reader = new class implements VideoIdentityReader {
            public function findVideoIdentity(string $canonicalVideoId): ?array
            {
                return ['canonical_video_id' => $canonicalVideoId, 'platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ'];
            }
        };

        $result = KnowledgeClaimIdentity::resolveInput('provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => ['subject_id' => self::SUBJECT, 'canonical_video_id' => self::VIDEO, 'proposition_class' => 'VIDEO_CONCERNS_SUBJECT'],
        ], $reader);

        self::assertSame('RESOLVED', $result->status());
        self::assertSame(['proposition_class' => 'VIDEO_CONCERNS_SUBJECT', 'subject_id' => self::SUBJECT, 'video_referent' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']], $result->packet());
    }

    public function testConflictingVideoSignalsAreConflicting(): void
    {
        $result = KnowledgeClaimIdentity::resolveInput('provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => [
                'subject_id' => self::SUBJECT,
                'platform' => 'youtube',
                'external_video_id' => 'dQw4w9WgXcQ',
                'canonical_video_id' => self::VIDEO,
                'proposition_class' => 'VIDEO_CONCERNS_SUBJECT',
            ],
        ], new class implements VideoIdentityReader {
            public function findVideoIdentity(string $canonicalVideoId): ?array
            {
                return ['canonical_video_id' => $canonicalVideoId, 'platform' => 'youtube', 'external_video_id' => 'different-id'];
            }
        });

        self::assertSame('CONFLICTING', $result->status());
        self::assertFalse($result->equivalentTo($result));
    }

    public function testCanonicalPolicyAndFingerprintAreDeterministic(): void
    {
        $result = KnowledgeClaimIdentity::resolveInput('provenance', [
            'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
            'metadata' => ['subject_id' => self::SUBJECT, 'platform' => 'YouTube', 'external_video_id' => 'dQw4w9WgXcQ', 'proposition_class' => 'VIDEO_CONCERNS_SUBJECT'],
        ]);

        self::assertSame('knowledge-identity-v2', $result->policyVersion());
        self::assertSame(64, strlen($result->fingerprint()));
    }
}
