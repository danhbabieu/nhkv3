<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class MediaIngestBatch
{
    /** @param array<string,mixed> $defaultContext */
    public function __construct(
        public string $batchId,
        public string $source,
        public string $uploader,
        public array $defaultContext,
        public int $imageCount,
        public string $status = 'accepted',
    ) {
        if (!UuidCodec::isValid($batchId) || $source === '' || $uploader === '' || $imageCount < 1 || !in_array($status, ['accepted', 'completed', 'partial', 'failed'], true)) throw new InvalidMedia('Media ingest batch is invalid.');
    }

    /** @param array<string,mixed> $defaultContext */
    public static function start(string $source, string $uploader, array $defaultContext, int $imageCount): self
    {
        return new self(UuidCodec::newV7(), $source, $uploader, $defaultContext, $imageCount);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['batch_id' => $this->batchId, 'source' => $this->source, 'uploader' => $this->uploader, 'default_context' => $this->defaultContext, 'image_count' => $this->imageCount, 'status' => $this->status];
    }
}
