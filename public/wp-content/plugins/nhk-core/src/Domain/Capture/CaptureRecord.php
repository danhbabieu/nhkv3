<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class CaptureRecord
{
    /** @param array<string,mixed> $assets @param array<string,mixed> $context @param array<string,mixed> $diagnostics @param array<string,mixed> $phaseReceipts */
    public function __construct(
        public string $captureId,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public string $stage,
        public string $status,
        public ?int $articleId = null,
        public ?string $articleStateToken = null,
        public array $assets = [],
        public array $context = [],
        public array $diagnostics = [],
        public array $phaseReceipts = [],
        public int $revision = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if (!UuidCodec::isValid($captureId) || trim($idempotencyKey) === '' || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint)) {
            throw new \InvalidArgumentException('Capture identity and fingerprint are invalid.');
        }
        if ($stage === '' || $status === '' || $revision < 1 || ($articleId !== null && $articleId < 1)) {
            throw new \InvalidArgumentException('Capture state is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'capture_id' => $this->captureId,
            'idempotency_key' => $this->idempotencyKey,
            'request_fingerprint' => $this->requestFingerprint,
            'stage' => $this->stage,
            'status' => $this->status,
            'article_id' => $this->articleId,
            'article_state_token' => $this->articleStateToken,
            'assets' => $this->assets,
            'context' => $this->context,
            'diagnostics' => $this->diagnostics,
            'phase_receipts' => $this->phaseReceipts,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
