<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class CaptureAddendumRecord
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $diagnostics */
    public function __construct(
        public string $addendumId,
        public string $captureId,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public string $status,
        public array $payload = [],
        public ?int $captureRevision = null,
        public array $diagnostics = [],
        public int $revision = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if (!UuidCodec::isValid($addendumId) || !UuidCodec::isValid($captureId) || trim($idempotencyKey) === '' || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint)) throw new \InvalidArgumentException('Capture addendum identity and fingerprint are invalid.');
        if ($status === '' || $revision < 1 || ($captureRevision !== null && $captureRevision < 1)) throw new \InvalidArgumentException('Capture addendum state is invalid.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['addendum_id' => $this->addendumId, 'capture_id' => $this->captureId, 'idempotency_key' => $this->idempotencyKey, 'request_fingerprint' => $this->requestFingerprint, 'status' => $this->status, 'payload' => $this->payload, 'capture_revision' => $this->captureRevision, 'diagnostics' => $this->diagnostics, 'revision' => $this->revision, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt];
    }
}
