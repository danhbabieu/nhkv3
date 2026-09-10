<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Capture;

use NHK\Core\Contracts\Capture\CaptureAddendumRepository;
use NHK\Core\Domain\Capture\CaptureAddendumRecord;

final class WpdbCaptureAddendumRepository implements CaptureAddendumRepository
{
    public function __construct(private object $wpdb) {}

    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE idempotency_key=%s', $key), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord
    {
        $now = $record->createdAt ?? gmdate('Y-m-d H:i:s.u');
        $ok = $this->wpdb->query($this->wpdb->prepare('INSERT INTO ' . $this->table() . ' (addendum_uuid,capture_uuid,idempotency_key,request_fingerprint,status,payload_json,capture_revision,diagnostics_json,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%d,%s,%d,%s,%s)', \NHK\Core\Shared\Uuid\UuidCodec::toBinary($record->addendumId), \NHK\Core\Shared\Uuid\UuidCodec::toBinary($record->captureId), $record->idempotencyKey, $record->requestFingerprint, $record->status, $this->encode($record->payload), $record->captureRevision ?? 0, $this->encode($record->diagnostics), $record->revision, $now, $record->updatedAt ?? $now));
        if ($ok !== 1) { $existing = $this->findByIdempotencyKey($record->idempotencyKey); if ($existing !== null) return $existing; throw new \RuntimeException('CAPTURE_ADDENDUM_STORAGE_UNAVAILABLE'); }
        return $this->readById($record->addendumId);
    }

    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord
    {
        $ok = $this->wpdb->query($this->wpdb->prepare('UPDATE ' . $this->table() . ' SET status=%s,payload_json=%s,capture_revision=%d,diagnostics_json=%s,revision=%d,updated_at=%s WHERE addendum_uuid=%s AND revision=%d', $record->status, $this->encode($record->payload), $record->captureRevision ?? 0, $this->encode($record->diagnostics), $record->revision, $record->updatedAt ?? gmdate('Y-m-d H:i:s.u'), \NHK\Core\Shared\Uuid\UuidCodec::toBinary($record->addendumId), max(1, $record->revision - 1)));
        if ($ok !== 1) throw new \RuntimeException('CAPTURE_ADDENDUM_STALE_REVISION');
        return $this->readById($record->addendumId);
    }

    private function readById(string $id): CaptureAddendumRecord
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE addendum_uuid=%s', \NHK\Core\Shared\Uuid\UuidCodec::toBinary($id)), ARRAY_A);
        if (!is_array($row)) throw new \RuntimeException('CAPTURE_ADDENDUM_READBACK_FAILED');
        return $this->hydrate($row);
    }

    private function table(): string { return $this->wpdb->prefix . 'nhk_editorial_capture_addenda'; }
    private function encode(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }

    private function hydrate(array $row): CaptureAddendumRecord
    {
        return new CaptureAddendumRecord(
            \NHK\Core\Shared\Uuid\UuidCodec::fromBinary((string) $row['addendum_uuid']),
            \NHK\Core\Shared\Uuid\UuidCodec::fromBinary((string) $row['capture_uuid']),
            (string) $row['idempotency_key'],
            (string) $row['request_fingerprint'],
            (string) $row['status'],
            $this->decode((string) ($row['payload_json'] ?? '[]')),
            ((int) ($row['capture_revision'] ?? 0)) > 0 ? (int) $row['capture_revision'] : null,
            $this->decode((string) ($row['diagnostics_json'] ?? '[]')),
            max(1, (int) ($row['revision'] ?? 1)),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
