<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Infrastructure\Admin\VideoRelationAdminContract;
use PHPUnit\Framework\TestCase;

final class VideoRelationAdminContractTest extends TestCase
{
    public function test_video_relation_contract_accepts_only_registered_about_flow(): void
    {
        $contract = new VideoRelationAdminContract();
        self::assertSame('video', $contract->sourceType());
        self::assertSame('about', $contract->predicate());
        self::assertSame(['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'], $contract->targetTypes());
        self::assertSame('EXPLICIT_USER_RELATION', $contract->evidenceOrigin());
    }

    public function test_relation_payload_requires_canonical_video_target_and_evidence_refs(): void
    {
        $contract = new VideoRelationAdminContract();
        $payload = $contract->payload('video-id', 'variant', 'target-id', [['evidence_id' => 'evidence-id']], 'video-fingerprint');

        self::assertSame([
            'source_type' => 'video',
            'source_uuid' => 'video-id',
            'target_type' => 'variant',
            'target_uuid' => 'target-id',
            'predicate' => 'about',
            'origin' => 'EXPLICIT_USER_RELATION',
            'evidence_refs' => [['evidence_id' => 'evidence-id']],
            'source_fingerprint' => 'video-fingerprint',
        ], $payload);
    }

    public function test_missing_evidence_is_rejected_before_proposal_creation(): void
    {
        $this->expectExceptionMessage('EVIDENCE_REFS_REQUIRED');
        (new VideoRelationAdminContract())->payload('video-id', 'variant', 'target-id', [], 'fingerprint');
    }
}
