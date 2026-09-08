<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{AtomicMediaBatchUploadRepository, MediaBatchUploadRepository, WordPressMediaAttachmentIngestor};
use NHK\Core\Infrastructure\Media\WpOptionMediaBatchUploadRepository;

final class MediaBatchUploadService
{
    public const MAX_FILES = 20;
    public const MAX_BATCH_BYTES = 50_000_000;

    public function __construct(
        private WordPressMediaAttachmentIngestor $ingestor,
        private ?MediaBatchUploadRepository $repository = null,
    ) {}

    /** @param array<string,mixed> $metadata @param array<string,mixed> $files @param array<string,mixed> $items */
    public function upload(string $idempotencyKey, array $metadata, array $files, array $items = []): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') throw new \InvalidArgumentException('idempotency_key is required.');
        $files = $this->flattenFiles($files);
        if ($files === []) throw new \InvalidArgumentException('files is required.');
        if (count($files) > self::MAX_FILES) throw new \InvalidArgumentException('Too many files in batch.');
        $normalizedItems = $this->normalizeItems($items, count($files));
        $fingerprint = hash('sha256', json_encode([$metadata, array_map([$this, 'fileFingerprint'], $files), $normalizedItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $repository = $this->repository ?? new WpOptionMediaBatchUploadRepository();
        $existing = $repository instanceof AtomicMediaBatchUploadRepository
            ? $repository->claim($idempotencyKey, $fingerprint)
            : $repository->find($idempotencyKey);
        if ($existing !== null) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('IDEMPOTENCY_CONFLICT');
            if (($existing['state'] ?? '') === 'in_progress') throw new \RuntimeException('MEDIA_BATCH_IN_PROGRESS');
            return is_array($existing['manifest'] ?? null) ? $existing['manifest'] : throw new \RuntimeException('MEDIA_BATCH_IDEMPOTENCY_STALE_BINDING');
        }

        $batchId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid();
        $results = [];
        $errors = [];
        $totalBytes = 0;
        foreach ($files as $index => $file) {
            $clientId = (string) ($normalizedItems[$index]['client_file_id'] ?? ('file-' . ($index + 1)));
            try {
                $size = (int) ($file['size'] ?? 0);
                if ($size < 1 || $size > $this->maxFileBytes()) throw new \InvalidArgumentException('FILE_SIZE_INVALID');
                $path = (string) ($file['tmp_name'] ?? '');
                if (!is_file($path) || !is_readable($path) || (int) filesize($path) !== $size) throw new \InvalidArgumentException('FILE_READBACK_INVALID');
                $totalBytes += $size;
                if ($totalBytes > self::MAX_BATCH_BYTES) throw new \InvalidArgumentException('BATCH_SIZE_LIMIT');
                $item = $normalizedItems[$index];
                $title = trim((string) ($item['title'] ?? $metadata['description'] ?? ''));
                if ($title === '') $title = 'NHK media ' . $clientId;
                $filename = trim((string) ($item['filename'] ?? $file['name'] ?? ''));
                $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) throw new \InvalidArgumentException('FILE_EXTENSION_INVALID');
                $result = $this->ingestor->ingest($file, $filename, $title, 2048, 2048, 82);
                $checksum = hash_file('sha256', (string) ($file['tmp_name'] ?? ''));
                if (!is_string($checksum) || $checksum === '') throw new \RuntimeException('CHECKSUM_FAILED');
                $results[] = array_merge($this->manifestItem($result, $checksum, $clientId), ['sort_order' => (int) ($item['sort_order'] ?? $index)]);
            } catch (\Throwable $error) {
                $errors[] = ['client_file_id' => $clientId, 'upload_status' => 'FAILED', 'code' => $error->getMessage() !== '' ? $error->getMessage() : 'UPLOAD_FAILED'];
            }
        }
        usort($results, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));
        $manifest = ['batch_id' => $batchId, 'idempotency_key' => $idempotencyKey, 'total_files' => count($files), 'succeeded' => count($results), 'failed' => count($errors), 'partial_success' => $results !== [] && $errors !== [], 'items' => $results, 'errors' => $errors];
        $repository->save($idempotencyKey, ['fingerprint' => $fingerprint, 'manifest' => $manifest]);
        return $manifest;
    }

    private function maxFileBytes(): int
    {
        if (function_exists('wp_max_upload_size')) return min(self::MAX_BATCH_BYTES, max(1, (int) wp_max_upload_size()));
        return self::MAX_BATCH_BYTES;
    }

    /** @return list<array<string,mixed>> */
    private function flattenFiles(array $files): array
    {
        $out = [];
        $walk = function (mixed $value) use (&$walk, &$out): void {
            if (!is_array($value)) return;
            if (array_key_exists('tmp_name', $value) && is_array($value['tmp_name'])) {
                foreach ($value['tmp_name'] as $index => $tmpName) $out[] = ['name' => is_array($value['name'] ?? null) ? (string) ($value['name'][$index] ?? '') : (string) ($value['name'] ?? ''), 'type' => is_array($value['type'] ?? null) ? (string) ($value['type'][$index] ?? '') : (string) ($value['type'] ?? ''), 'tmp_name' => (string) $tmpName, 'error' => is_array($value['error'] ?? null) ? (int) ($value['error'][$index] ?? UPLOAD_ERR_NO_FILE) : (int) ($value['error'] ?? UPLOAD_ERR_OK), 'size' => is_array($value['size'] ?? null) ? (int) ($value['size'][$index] ?? 0) : (int) ($value['size'] ?? 0)];
                return;
            }
            if (array_key_exists('tmp_name', $value)) { $out[] = $value; return; }
            foreach ($value as $nested) $walk($nested);
        };
        $walk($files['files'] ?? $files);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeItems(array $items, int $count): array
    {
        $normalized = [];
        for ($index = 0; $index < $count; $index++) $normalized[] = is_array($items[$index] ?? null) ? $items[$index] : [];
        return $normalized;
    }

    private function fileFingerprint(array $file): array
    {
        $path = (string) ($file['tmp_name'] ?? '');
        return ['name' => (string) ($file['name'] ?? ''), 'size' => (int) ($file['size'] ?? 0), 'checksum' => is_file($path) ? hash_file('sha256', $path) : null];
    }

    /** @return array<string,mixed> */
    private function manifestItem(array $result, string $checksum, string $clientId): array
    {
        return ['client_file_id' => $clientId, 'attachment_id' => (int) ($result['attachment_id'] ?? 0), 'media_id' => (string) ($result['media_id'] ?? ''), 'source_url' => (string) ($result['canonical_url'] ?? ''), 'filename' => (string) ($result['filename'] ?? ''), 'mime_type' => (string) ($result['mime'] ?? ''), 'byte_size' => (int) ($result['filesize'] ?? 0), 'width' => (int) ($result['width'] ?? 0), 'height' => (int) ($result['height'] ?? 0), 'checksum_sha256' => $checksum, 'attachment_readback_status' => 'verified', 'reused' => false, 'upload_status' => 'CREATED'];
    }

    private function uuid(): string
    {
        return sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3ff), random_int(0, 0xffffffffffff));
    }
}
