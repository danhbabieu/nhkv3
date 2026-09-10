<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Capture;

use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbCaptureRepository implements CaptureRepository
{
    public function __construct(private object $wpdb) {}

    public function findByIdempotencyKey(string $key): ?CaptureRecord
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE idempotency_key=%s', $key), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(string $captureId): ?CaptureRecord
    {
        try { $binary = UuidCodec::toBinary($captureId); } catch (\Throwable) { return null; }
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE capture_uuid=%s', $binary), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(CaptureRecord $record): CaptureRecord
    {
        $now = $record->createdAt ?? gmdate('Y-m-d H:i:s.u');
        $ok = $this->wpdb->query($this->wpdb->prepare(
            'INSERT INTO ' . $this->table() . ' (capture_uuid,idempotency_key,request_fingerprint,stage,status,wp_post_id,wp_state_token,assets_json,context_json,diagnostics_json,phase_receipts_json,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%d,%s,%s)',
            UuidCodec::toBinary($record->captureId),
            $record->idempotencyKey,
            $record->requestFingerprint,
            $record->stage,
            $record->status,
            $record->articleId ?? 0,
            $record->articleStateToken,
            $this->encode($record->assets),
            $this->encode($record->context),
            $this->encode($record->diagnostics),
            $this->encode($record->phaseReceipts),
            $record->revision,
            $now,
            $record->updatedAt ?? $now,
        ));
        if ($ok !== 1) {
            $existing = $this->findByIdempotencyKey($record->idempotencyKey);
            if ($existing !== null) return $existing;
            throw new \RuntimeException('CAPTURE_STORAGE_UNAVAILABLE');
        }
        return $this->readById($record->captureId);
    }

    public function save(CaptureRecord $record): CaptureRecord
    {
        $expectedRevision = max(1, $record->revision - 1);
        $ok = $this->wpdb->query($this->wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET stage=%s,status=%s,wp_post_id=%d,wp_state_token=%s,assets_json=%s,context_json=%s,diagnostics_json=%s,phase_receipts_json=%s,revision=%d,updated_at=%s WHERE capture_uuid=%s AND revision=%d',
            $record->stage,
            $record->status,
            $record->articleId ?? 0,
            $record->articleStateToken,
            $this->encode($record->assets),
            $this->encode($record->context),
            $this->encode($record->diagnostics),
            $this->encode($record->phaseReceipts),
            $record->revision,
            $record->updatedAt ?? gmdate('Y-m-d H:i:s.u'),
            UuidCodec::toBinary($record->captureId),
            $expectedRevision,
        ));
        if ($ok !== 1) throw new \RuntimeException('CAPTURE_STALE_REVISION');
        return $this->readById($record->captureId);
    }

    private function readById(string $captureId): CaptureRecord
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE capture_uuid=%s', UuidCodec::toBinary($captureId)), ARRAY_A);
        if (!is_array($row)) throw new \RuntimeException('CAPTURE_READBACK_FAILED');
        return $this->hydrate($row);
    }

    private function table(): string { return $this->wpdb->prefix . 'nhk_editorial_captures'; }

    /** @param array<mixed> $value */
    private function encode(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): CaptureRecord
    {
        try { $captureId = UuidCodec::fromBinary((string) $row['capture_uuid']); } catch (\Throwable) { throw new \RuntimeException('CAPTURE_IDENTITY_READBACK_FAILED'); }
        return new CaptureRecord(
            $captureId,
            (string) $row['idempotency_key'],
            (string) $row['request_fingerprint'],
            (string) $row['stage'],
            (string) $row['status'],
            ((int) ($row['wp_post_id'] ?? 0)) > 0 ? (int) $row['wp_post_id'] : null,
            (($token = trim((string) ($row['wp_state_token'] ?? ''))) !== '') ? $token : null,
            $this->decode((string) ($row['assets_json'] ?? '[]')),
            $this->decode((string) ($row['context_json'] ?? '[]')),
            $this->decode((string) ($row['diagnostics_json'] ?? '[]')),
            $this->decode((string) ($row['phase_receipts_json'] ?? '[]')),
            max(1, (int) ($row['revision'] ?? 1)),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
