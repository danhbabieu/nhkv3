<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Server-owned, immutable handoff for resolving a reviewed Capture subject. */
final readonly class ResolvedSubjectReconciliationPacket
{
    /** @param list<array<string,mixed>> $evidenceRefs @param array<string,mixed> $context */
    public function __construct(
        public string $captureId,
        public string $requestFingerprint,
        public string $idempotencyKey,
        public int $captureRevision,
        public SubjectResolutionPacket $subject,
        public array $evidenceRefs,
        public string $expiresAt,
        public array $context = [],
    ) {
        if (!UuidCodec::isValid($captureId) || $requestFingerprint === '' || $idempotencyKey === '' || $captureRevision < 1) throw new \InvalidArgumentException('RESOLVED_SUBJECT_PACKET_BINDING_INVALID');
        if ($subject->status !== 'resolved') throw new \InvalidArgumentException('RESOLVED_SUBJECT_PACKET_SUBJECT_REQUIRED');
        if (strtotime($expiresAt) === false || (int) strtotime($expiresAt) <= time()) throw new \InvalidArgumentException('RESOLVED_SUBJECT_PACKET_EXPIRED');
        if ($evidenceRefs === []) throw new \InvalidArgumentException('RESOLVED_SUBJECT_PACKET_EVIDENCE_REQUIRED');
        foreach ($evidenceRefs as $ref) if (!is_array($ref) || !UuidCodec::isValid((string) ($ref['evidence_id'] ?? ''))) throw new \InvalidArgumentException('RESOLVED_SUBJECT_PACKET_EVIDENCE_INVALID');
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): ?self
    {
        try {
            if ((int) ($value['packet_version'] ?? 1) !== 1) return null;
            $subject = SubjectResolutionPacket::fromArray(is_array($value['subject'] ?? null) ? $value['subject'] : (is_array($value['subject_resolution_packet'] ?? null) ? $value['subject_resolution_packet'] : []));
            if ($subject === null) return null;
            $packet = new self(
                trim((string) ($value['capture_id'] ?? '')),
                trim((string) ($value['request_fingerprint'] ?? '')),
                trim((string) ($value['idempotency_key'] ?? '')),
                (int) ($value['capture_revision'] ?? 0),
                $subject,
                array_values(array_filter((array) ($value['evidence_refs'] ?? []), 'is_array')),
                trim((string) ($value['expires_at'] ?? '')),
                is_array($value['context'] ?? null) ? $value['context'] : [],
            );
            $suppliedFingerprint = trim((string) ($value['packet_fingerprint'] ?? ''));
            if ($suppliedFingerprint !== '' && !hash_equals($packet->fingerprint(), $suppliedFingerprint)) return null;
            return $packet;
        } catch (\Throwable) {
            return null;
        }
    }

    public function matches(CaptureRecord $capture): bool
    {
        return hash_equals($this->captureId, $capture->captureId)
            && hash_equals($this->requestFingerprint, $capture->requestFingerprint)
            && hash_equals($this->idempotencyKey, $capture->idempotencyKey)
            && $this->captureRevision === $capture->revision;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'packet_version' => 1,
            'capture_id' => $this->captureId,
            'request_fingerprint' => $this->requestFingerprint,
            'idempotency_key' => $this->idempotencyKey,
            'capture_revision' => $this->captureRevision,
            'subject' => $this->subject->toArray(),
            'evidence_refs' => $this->evidenceRefs,
            'expires_at' => $this->expiresAt,
            'context' => $this->context,
            'packet_fingerprint' => $this->fingerprint(),
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'capture_id' => $this->captureId,
            'request_fingerprint' => $this->requestFingerprint,
            'idempotency_key' => $this->idempotencyKey,
            'capture_revision' => $this->captureRevision,
            'subject' => $this->subject->toArray(),
            'evidence_refs' => $this->evidenceRefs,
            'expires_at' => $this->expiresAt,
            'context' => $this->context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
