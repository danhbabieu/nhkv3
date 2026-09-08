<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaBatchUploadRepository;

final class WpOptionMediaBatchUploadRepository implements MediaBatchUploadRepository
{
    private const OPTION = 'nhk_media_upload_batches';

    public function find(string $idempotencyKey): ?array
    {
        if (!function_exists('get_option')) return null;
        $records = get_option(self::OPTION, []);
        $record = is_array($records) ? ($records[hash('sha256', $idempotencyKey)] ?? null) : null;
        return is_array($record) ? $record : null;
    }

    public function save(string $idempotencyKey, array $record): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STORAGE_UNAVAILABLE');
        $records = get_option(self::OPTION, []);
        $records = is_array($records) ? $records : [];
        $records[hash('sha256', $idempotencyKey)] = $record;
        if (!update_option(self::OPTION, $records, false)) throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STORAGE_FAILED');
    }
}
