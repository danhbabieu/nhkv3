<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaBatchUploadService;
use NHK\Core\Contracts\Media\{AtomicMediaBatchUploadRepository, MediaBatchUploadRepository, WordPressMediaAttachmentIngestor};
use PHPUnit\Framework\TestCase;

final class MediaBatchUploadServiceTest extends TestCase
{
    public function test_multi_image_batch_context_is_not_fanned_out_as_per_item_title(): void
    {
        $files = [$this->file('front.jpg', 'front'), $this->file('paper.jpg', 'paper'), $this->file('back.jpg', 'back')];
        $titles = [];
        $ingestor = new class($titles) implements WordPressMediaAttachmentIngestor {
            public function __construct(private array &$titles) {}
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                $this->titles[] = $title;
                return ['attachment_id' => count($this->titles), 'canonical_url' => '/' . $filename, 'filename' => $filename, 'mime' => 'image/webp', 'filesize' => (int) ($file['size'] ?? 0), 'width' => 1, 'height' => 1];
            }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            (new MediaBatchUploadService($ingestor, $repository))->upload(
                'ordered-context',
                ['description' => 'Đây lần lượt là ảnh mặt trước, ảnh giấy hướng dẫn và ảnh mặt sau máy.'],
                ['files' => $files],
            );
            self::assertCount(3, $titles);
            self::assertNotSame('Đây lần lượt là ảnh mặt trước, ảnh giấy hướng dẫn và ảnh mặt sau máy.', $titles[0]);
            self::assertCount(3, array_unique($titles));
        } finally {
            foreach ($files as $file) @unlink((string) $file['tmp_name']);
        }
    }

    public function test_explicit_ordered_description_maps_only_when_cardinality_matches(): void
    {
        $files = [$this->file('front.jpg', 'front'), $this->file('paper.jpg', 'paper'), $this->file('back.jpg', 'back')];
        $titles = [];
        $ingestor = new class($titles) implements WordPressMediaAttachmentIngestor {
            public function __construct(private array &$titles) {}
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                $this->titles[] = $title;
                return ['attachment_id' => count($this->titles), 'canonical_url' => '/' . $filename, 'filename' => $filename, 'mime' => 'image/webp', 'filesize' => (int) ($file['size'] ?? 0), 'width' => 1, 'height' => 1];
            }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            (new MediaBatchUploadService($ingestor, $repository))->upload(
                'ordered-description',
                ['description' => 'Đây lần lượt là ảnh mặt trước, ảnh giấy hướng dẫn và ảnh mặt sau máy.'],
                ['files' => $files],
            );
            self::assertSame(['ảnh mặt trước', 'ảnh giấy hướng dẫn', 'ảnh mặt sau máy'], $titles);
        } finally {
            foreach ($files as $file) @unlink((string) $file['tmp_name']);
        }
    }

    public function test_per_item_media_context_is_preserved_separately_from_batch_context(): void
    {
        $files = [$this->file('front.jpg', 'front'), $this->file('paper.jpg', 'paper')];
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                return ['attachment_id' => 1, 'canonical_url' => '/' . $filename, 'filename' => $filename, 'mime' => 'image/webp', 'filesize' => (int) ($file['size'] ?? 0), 'width' => 1, 'height' => 1];
            }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            $result = (new MediaBatchUploadService($ingestor, $repository))->upload(
                'per-item-context',
                ['description' => 'Cùng một bộ ảnh của hiện vật.'],
                ['files' => $files],
                [
                    ['media' => ['title' => 'Mặt trước', 'alt_text' => 'Ảnh mặt trước', 'caption' => 'Mặt trước hiện vật.']],
                    ['media' => ['title' => 'Tài liệu', 'alt_text' => 'Tờ giấy hướng dẫn', 'caption' => 'Tài liệu đi kèm.']],
                ],
            );
            self::assertSame(['Cùng một bộ ảnh của hiện vật.'], [$result['batch_context']['description']]);
            self::assertSame('Mặt trước', $result['items'][0]['media_context']['title']);
            self::assertSame('Tài liệu', $result['items'][1]['media_context']['title']);
            self::assertSame([0, 1], array_column($result['items'], 'ordinal'));
        } finally {
            foreach ($files as $file) @unlink((string) $file['tmp_name']);
        }
    }

    public function test_batch_returns_ordered_manifest_and_keeps_partial_success(): void
    {
        $first = $this->file('front.jpg', 'jpeg-bytes');
        $second = $this->file('bad.exe', 'invalid');
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                if (str_ends_with($filename, '.exe')) throw new \InvalidArgumentException('MIME_INVALID');
                return ['attachment_id' => 10, 'canonical_url' => '/wp-content/uploads/front.webp', 'filename' => 'front.webp', 'mime' => 'image/webp', 'filesize' => 12, 'width' => 100, 'height' => 80];
            }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public array $records = [];
            public function find(string $idempotencyKey): ?array { return $this->records[$idempotencyKey] ?? null; }
            public function save(string $idempotencyKey, array $record): void { $this->records[$idempotencyKey] = $record; }
        };
        $result = (new MediaBatchUploadService($ingestor, $repository))->upload('batch-1', ['description' => 'clock'], ['files' => [$first, $second]], [
            ['client_file_id' => 'front', 'sort_order' => 2, 'title' => 'Front'],
            ['client_file_id' => 'invalid', 'sort_order' => 1, 'title' => 'Invalid'],
        ]);
        self::assertSame(2, $result['total_files']);
        self::assertSame(1, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertTrue($result['partial_success']);
        self::assertSame('front', $result['items'][0]['client_file_id']);
        self::assertSame(hash_file('sha256', $first['tmp_name']), $result['items'][0]['checksum_sha256']);
        self::assertSame('invalid', $result['errors'][0]['client_file_id']);
        @unlink($first['tmp_name']); @unlink($second['tmp_name']);
    }

    public function test_missing_trustworthy_filename_context_is_reported_per_file(): void
    {
        $file = $this->file('IMG_0001.jpg', 'bytes');
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { return []; }
            public function read(int $attachmentId): ?array { return null; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            $result = (new MediaBatchUploadService($ingestor, $repository))->upload('missing-context', [], ['files' => [$file]]);
            self::assertSame(0, $result['succeeded']);
            self::assertSame('TRUSTWORTHY_FILENAME_CONTEXT_REQUIRED', $result['errors'][0]['code']);
        } finally {
            @unlink($file['tmp_name']);
        }
    }

    public function test_same_key_with_changed_payload_is_a_deterministic_conflict(): void
    {
        $file = $this->file('one.jpg', 'one');
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { return ['attachment_id' => 1, 'canonical_url' => '/image.webp', 'filename' => 'image.webp', 'mime' => 'image/webp', 'filesize' => 3, 'width' => 1, 'height' => 1]; }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public array $records = [];
            public function find(string $idempotencyKey): ?array { return $this->records[$idempotencyKey] ?? null; }
            public function save(string $idempotencyKey, array $record): void { $this->records[$idempotencyKey] = $record; }
        };
        $service = new MediaBatchUploadService($ingestor, $repository);
        $service->upload('same-key', ['description' => 'clock'], ['files' => [$file]], [['client_file_id' => 'one']]);
        file_put_contents($file['tmp_name'], 'changed');
        $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
        $service->upload('same-key', [], ['files' => [$file]], [['client_file_id' => 'one']]);
        @unlink($file['tmp_name']);
    }

    public function test_same_key_with_same_payload_replays_without_a_second_attachment(): void
    {
        $first = $this->file('one.jpg', 'same-bytes');
        $second = $this->file('one.jpg', 'same-bytes');
        $ingestCalls = 0;
        $ingestor = new class($ingestCalls) implements WordPressMediaAttachmentIngestor {
            public function __construct(private int &$calls) {}
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                $this->calls++;
                return ['attachment_id' => 1, 'canonical_url' => '/image.webp', 'filename' => 'image.webp', 'mime' => 'image/webp', 'filesize' => 3, 'width' => 1, 'height' => 1];
            }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public array $records = [];
            public function find(string $idempotencyKey): ?array { return $this->records[$idempotencyKey] ?? null; }
            public function save(string $idempotencyKey, array $record): void { $this->records[$idempotencyKey] = $record; }
        };
        $service = new MediaBatchUploadService($ingestor, $repository);

        try {
            $firstResult = $service->upload('same-key-replay', ['description' => 'clock'], ['files' => [$first]], [['client_file_id' => 'one']]);
            $replay = $service->upload('same-key-replay', ['description' => 'clock'], ['files' => [$second]], [['client_file_id' => 'one']]);
            self::assertSame($firstResult, $replay);
            self::assertSame(1, $ingestCalls);
        } finally {
            @unlink($first['tmp_name']);
            @unlink($second['tmp_name']);
        }
    }

    public function test_atomic_reservation_rejects_a_concurrent_same_key_before_upload(): void
    {
        $file = $this->file('one.jpg', 'one');
        $ingestCalls = 0;
        $ingestor = new class($ingestCalls) implements WordPressMediaAttachmentIngestor {
            public function __construct(private int &$calls) {}
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { $this->calls++; return ['attachment_id' => 1, 'canonical_url' => '/image.webp', 'filename' => 'image.webp', 'mime' => 'image/webp', 'filesize' => 3, 'width' => 1, 'height' => 1]; }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository, AtomicMediaBatchUploadRepository {
            public bool $claimed = true;
            public function claim(string $idempotencyKey, string $fingerprint): ?array
            {
                if ($this->claimed) return ['fingerprint' => $fingerprint, 'state' => 'in_progress'];
                $this->claimed = true;
                return null;
            }
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };
        try {
            (new MediaBatchUploadService($ingestor, $repository))->upload('race-key', [], ['files' => [$file]], [['client_file_id' => 'one']]);
            self::fail('Expected the concurrent reservation to fail closed.');
        } catch (\RuntimeException $error) {
            self::assertSame('MEDIA_BATCH_IN_PROGRESS', $error->getMessage());
        } finally {
            @unlink($file['tmp_name']);
        }
        self::assertSame(0, $ingestCalls);
    }

    public function test_batch_accepts_exactly_twenty_files_in_order(): void
    {
        $files = [];
        foreach (range(1, 20) as $index) $files[] = $this->file('ordered-' . $index . '.jpg', 'bytes-' . $index);
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { return ['attachment_id' => 1, 'canonical_url' => '/' . $filename, 'filename' => $filename, 'mime' => 'image/webp', 'filesize' => (int) ($file['size'] ?? 0), 'width' => 1, 'height' => 1]; }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            $result = (new MediaBatchUploadService($ingestor, $repository))->upload('twenty-files', ['description' => 'ordered image set'], ['files' => $files]);
            self::assertSame(20, $result['total_files']);
            self::assertSame(20, $result['succeeded']);
            self::assertSame(array_map(static fn (int $index): string => 'file-' . $index, range(1, 20)), array_column($result['items'], 'client_file_id'));
        } finally {
            foreach ($files as $file) @unlink((string) ($file['tmp_name'] ?? ''));
        }
    }

    public function test_batch_rejects_the_twenty_first_file_before_any_upload(): void
    {
        $files = [];
        foreach (range(1, 21) as $index) $files[] = $this->file('too-many-' . $index . '.jpg', 'bytes-' . $index);
        $ingestCalls = 0;
        $ingestor = new class($ingestCalls) implements WordPressMediaAttachmentIngestor {
            public function __construct(private int &$calls) {}
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { $this->calls++; return []; }
            public function read(int $attachmentId): ?array { return null; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            $this->expectExceptionMessage('Too many files in batch.');
            (new MediaBatchUploadService($ingestor, $repository))->upload('twenty-one-files', [], ['files' => $files]);
        } finally {
            foreach ($files as $file) @unlink((string) ($file['tmp_name'] ?? ''));
        }
    }

    public function test_batch_rejects_total_payload_over_fifty_megabytes_with_partial_result(): void
    {
        $first = $this->sparseFile('large-one.jpg', 25_000_000);
        $second = $this->sparseFile('large-two.jpg', 25_000_001);
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { return ['attachment_id' => 1, 'canonical_url' => '/large.webp', 'filename' => 'large.webp', 'mime' => 'image/webp', 'filesize' => (int) ($file['size'] ?? 0), 'width' => 1, 'height' => 1]; }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public function find(string $idempotencyKey): ?array { return null; }
            public function save(string $idempotencyKey, array $record): void {}
        };

        try {
            $result = (new MediaBatchUploadService($ingestor, $repository))->upload('over-fifty-megabytes', ['description' => 'large image set'], ['files' => [$first, $second]]);
            self::assertSame(2, $result['total_files']);
            self::assertSame(1, $result['succeeded']);
            self::assertSame('BATCH_SIZE_LIMIT', $result['errors'][0]['code']);
        } finally {
            @unlink((string) $first['tmp_name']);
            @unlink((string) $second['tmp_name']);
        }
    }

    public function test_same_bytes_with_a_different_filename_conflict_is_not_treated_as_replay(): void
    {
        $first = $this->file('first.jpg', 'same-bytes');
        $second = $this->file('second.jpg', 'same-bytes');
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array { return ['attachment_id' => 1, 'canonical_url' => '/same.webp', 'filename' => 'same.webp', 'mime' => 'image/webp', 'filesize' => 10, 'width' => 1, 'height' => 1]; }
            public function read(int $attachmentId): ?array { return ['attachment_id' => $attachmentId]; }
        };
        $repository = new class implements MediaBatchUploadRepository {
            public array $records = [];
            public function find(string $idempotencyKey): ?array { return $this->records[$idempotencyKey] ?? null; }
            public function save(string $idempotencyKey, array $record): void { $this->records[$idempotencyKey] = $record; }
        };
        $service = new MediaBatchUploadService($ingestor, $repository);

        try {
            $service->upload('filename-is-payload', ['description' => 'same image'], ['files' => [$first]]);
            $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
            $service->upload('filename-is-payload', ['description' => 'same image'], ['files' => [$second]]);
        } finally {
            @unlink((string) $first['tmp_name']);
            @unlink((string) $second['tmp_name']);
        }
    }

    /** @return array<string,mixed> */
    private function file(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-batch-');
        file_put_contents($path, $contents);
        return ['name' => $name, 'type' => 'image/jpeg', 'size' => strlen($contents), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path];
    }

    /** @return array<string,mixed> */
    private function sparseFile(string $name, int $size): array
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-batch-large-');
        self::assertIsString($path);
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, $size);
        fclose($handle);
        return ['name' => $name, 'type' => 'image/jpeg', 'size' => $size, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path];
    }
}
