<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Capture\{CaptureRecord, ResolvedSubjectReconciliationPacket, SubjectResolutionPacket};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ResolvedSubjectReconciliationPacketTest extends TestCase
{
    public function test_packet_is_bound_to_exact_capture_request_revision_and_idempotency(): void
    {
        $captureId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $subject = new SubjectResolutionPacket('resolved', $subjectId, 'specimen', 'object-a', 'Object A', 3, 'USER_CONFIRMED', [], 'EXPLICIT_USER_KNOWLEDGE');
        $packet = new ResolvedSubjectReconciliationPacket($captureId, str_repeat('a', 64), 'capture-idem', 4, $subject, [['evidence_id' => $evidenceId]], gmdate('c', time() + 3600));
        $capture = new CaptureRecord($captureId, 'capture-idem', str_repeat('a', 64), 'subject_reconciliation', 'REVIEW_REQUIRED', revision: 4);

        self::assertTrue($packet->matches($capture));
        self::assertSame($packet->fingerprint(), ResolvedSubjectReconciliationPacket::fromArray($packet->toArray())?->fingerprint());
        self::assertSame($subjectId, $packet->subject->canonicalSubjectId);
    }

    public function test_packet_replay_with_changed_fingerprint_or_capture_revision_fails_closed(): void
    {
        $id = UuidCodec::newV7();
        $subject = new SubjectResolutionPacket('resolved', UuidCodec::newV7(), 'specimen', 'object-b', 'Object B', 1, 'USER_CONFIRMED');
        $packet = new ResolvedSubjectReconciliationPacket($id, str_repeat('b', 64), 'idem-b', 1, $subject, [['evidence_id' => UuidCodec::newV7()]], gmdate('c', time() + 3600));
        $changed = $packet->toArray();
        $changed['capture_revision'] = 2;
        $changed['packet_fingerprint'] = $packet->fingerprint();

        self::assertNull(ResolvedSubjectReconciliationPacket::fromArray($changed));
    }
}
