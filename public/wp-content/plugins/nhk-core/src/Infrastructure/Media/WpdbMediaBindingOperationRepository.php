<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaBindingOperationRepository;
use NHK\Core\Domain\Media\{MediaBindingOperation, MediaException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaBindingOperationRepository implements MediaBindingOperationRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_binding_operations';
    }

    public function findByOperationId(string $operationId): ?MediaBindingOperation
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE operation_uuid=%s LIMIT 1", UuidCodec::toBinary($operationId)), ARRAY_A));
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?MediaBindingOperation
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE idempotency_key=%s LIMIT 1", $idempotencyKey), ARRAY_A));
    }

    public function create(MediaBindingOperation $operation): MediaBindingOperation
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (operation_uuid,idempotency_key,request_fingerprint,media_uuid,target_type,target_uuid,requested_role,selection_source,selection_policy,stage,status,resulting_usage_uuid,previous_usage_uuid,revision,error_code,metadata_json,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s)", UuidCodec::toBinary($operation->operationId), $operation->idempotencyKey, hex2bin($operation->requestFingerprint), $operation->mediaId === null ? null : UuidCodec::toBinary($operation->mediaId), $operation->targetType, UuidCodec::toBinary($operation->targetId), $operation->requestedRole, $operation->selectionSource, $operation->selectionPolicy, $operation->stage, $operation->status, $operation->resultingUsageId === null ? null : UuidCodec::toBinary($operation->resultingUsageId), $operation->previousUsageId === null ? null : UuidCodec::toBinary($operation->previousUsageId), $operation->revision, $operation->errorCode, wp_json_encode($operation->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now));
        if ($ok === false) {
            $existing = $this->findByIdempotencyKey($operation->idempotencyKey);
            if ($existing !== null) return $existing;
            throw new MediaException('Media binding operation create failed.');
        }
        return $this->findByOperationId($operation->operationId) ?? $operation;
    }

    public function save(MediaBindingOperation $operation, int $expectedRevision): MediaBindingOperation
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET media_uuid=%s,stage=%s,status=%s,resulting_usage_uuid=%s,previous_usage_uuid=%s,revision=%d,error_code=%s,metadata_json=%s,updated_at=%s WHERE operation_uuid=%s AND revision=%d", $operation->mediaId === null ? null : UuidCodec::toBinary($operation->mediaId), $operation->stage, $operation->status, $operation->resultingUsageId === null ? null : UuidCodec::toBinary($operation->resultingUsageId), $operation->previousUsageId === null ? null : UuidCodec::toBinary($operation->previousUsageId), $operation->revision, $operation->errorCode, wp_json_encode($operation->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, UuidCodec::toBinary($operation->operationId), $expectedRevision));
        if ($ok !== 1) throw new MediaException('Media binding operation revision conflict.');
        return $this->findByOperationId($operation->operationId) ?? $operation;
    }

    private function hydrate(?array $row): ?MediaBindingOperation
    {
        if (!is_array($row)) return null;
        try {
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            return new MediaBindingOperation(UuidCodec::fromBinary($row['operation_uuid']), (string) $row['idempotency_key'], bin2hex((string) $row['request_fingerprint']), isset($row['media_uuid']) && $row['media_uuid'] !== null ? UuidCodec::fromBinary($row['media_uuid']) : null, (string) $row['target_type'], UuidCodec::fromBinary($row['target_uuid']), (string) $row['requested_role'], (string) $row['selection_source'], (string) $row['selection_policy'], (string) $row['stage'], (string) $row['status'], isset($row['resulting_usage_uuid']) && $row['resulting_usage_uuid'] !== null ? UuidCodec::fromBinary($row['resulting_usage_uuid']) : null, isset($row['previous_usage_uuid']) && $row['previous_usage_uuid'] !== null ? UuidCodec::fromBinary($row['previous_usage_uuid']) : null, (int) ($row['revision'] ?? 1), $row['error_code'] !== null ? (string) $row['error_code'] : null, $row['created_at'] ?? null, $row['updated_at'] ?? null, is_array($metadata) ? $metadata : []);
        } catch (\Throwable) {
            return null;
        }
    }
}
