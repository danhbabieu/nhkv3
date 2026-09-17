<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class MediaBindingOperation
{
    public const VALIDATE = 'VALIDATE';
    public const RESOLVE_MEDIA = 'RESOLVE_MEDIA';
    public const RESOLVE_TARGET = 'RESOLVE_TARGET';
    public const PLAN = 'PLAN';
    public const APPLY_USAGE = 'APPLY_USAGE';
    public const RECONCILE_REPRESENTATIVE = 'RECONCILE_REPRESENTATIVE';
    public const SEO_INVALIDATE = 'SEO_INVALIDATE';
    public const PROJECTION_INVALIDATE = 'PROJECTION_INVALIDATE';
    public const FINAL_READBACK = 'FINAL_READBACK';
    public const COMPLETE = 'COMPLETE';

    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $operationId,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public ?string $mediaId,
        public string $targetType,
        public string $targetId,
        public string $requestedRole,
        public string $selectionSource,
        public string $selectionPolicy,
        public string $stage = self::VALIDATE,
        public string $status = 'IN_PROGRESS',
        public ?string $resultingUsageId = null,
        public ?string $previousUsageId = null,
        public int $revision = 1,
        public ?string $errorCode = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public array $metadata = [],
    ) {
        if (!UuidCodec::isValid($operationId) || ($mediaId !== null && !UuidCodec::isValid($mediaId))) throw new MediaException('Media binding operation identity is invalid.');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191 || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint)) throw new MediaException('Media binding operation idempotency is invalid.');
        if ($targetType === '' || !UuidCodec::isValid($targetId) || $requestedRole === '' || $revision < 1) throw new MediaException('Media binding operation target is invalid.');
        if (!in_array($selectionSource, ['USER_EXPLICIT', 'SYSTEM_AUTO'], true) || !in_array($selectionPolicy, ['PINNED', 'AUTO'], true)) throw new MediaException('Media binding operation selection metadata is invalid.');
        if (!in_array($stage, [self::VALIDATE, self::RESOLVE_MEDIA, self::RESOLVE_TARGET, self::PLAN, self::APPLY_USAGE, self::RECONCILE_REPRESENTATIVE, self::SEO_INVALIDATE, self::PROJECTION_INVALIDATE, self::FINAL_READBACK, self::COMPLETE], true)) throw new MediaException('Media binding operation stage is invalid.');
        if (!in_array($status, ['IN_PROGRESS', 'COMPLETE', 'FAILED_RETRYABLE', 'REVIEW_REQUIRED', 'BLOCKED'], true)) throw new MediaException('Media binding operation status is invalid.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'idempotency_key' => $this->idempotencyKey,
            'request_fingerprint' => $this->requestFingerprint,
            'media_id' => $this->mediaId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'requested_role' => $this->requestedRole,
            'selection_source' => $this->selectionSource,
            'selection_policy' => $this->selectionPolicy,
            'stage' => $this->stage,
            'status' => $this->status,
            'resulting_usage_id' => $this->resultingUsageId,
            'previous_usage_id' => $this->previousUsageId,
            'revision' => $this->revision,
            'error_code' => $this->errorCode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'metadata' => $this->metadata,
        ];
    }
}
