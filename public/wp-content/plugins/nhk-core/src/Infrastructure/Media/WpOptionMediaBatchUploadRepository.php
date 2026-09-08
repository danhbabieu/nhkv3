<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\{AtomicMediaBatchUploadRepository, MediaBatchUploadRepository};

final class WpOptionMediaBatchUploadRepository implements MediaBatchUploadRepository, AtomicMediaBatchUploadRepository
{
    private const OPTION = 'nhk_media_upload_batches';

    private function option(string $idempotencyKey): string
    {
        return 'nhk_media_upload_batch_' . hash('sha256', $idempotencyKey);
    }

    public function claim(string $idempotencyKey, string $fingerprint): ?array
    {
        if (!function_exists('add_option') || !function_exists('get_option')) throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STORAGE_UNAVAILABLE');
        $existing = $this->find($idempotencyKey);
        if ($existing !== null) return $existing;
        $option = $this->option($idempotencyKey);
        if (add_option($option, ['fingerprint' => $fingerprint, 'state' => 'in_progress'], '', false)) return null;
        $record = get_option($option, null);
        return is_array($record) ? $record : throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STALE_BINDING');
    }

    public function find(string $idempotencyKey): ?array
    {
        if (!function_exists('get_option')) return null;
        $record = get_option($this->option($idempotencyKey), null);
        if (is_array($record)) return $record;
        $records = get_option(self::OPTION, []);
        $record = is_array($records) ? ($records[hash('sha256', $idempotencyKey)] ?? null) : null;
        return is_array($record) ? $record : null;
    }

    public function save(string $idempotencyKey, array $record): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STORAGE_UNAVAILABLE');
        if (function_exists('update_option') && update_option($this->option($idempotencyKey), $record, false)) return;
        $records = get_option(self::OPTION, []);
        $records = is_array($records) ? $records : [];
        $records[hash('sha256', $idempotencyKey)] = $record;
        if (!update_option(self::OPTION, $records, false)) throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STORAGE_FAILED');
    }
}
