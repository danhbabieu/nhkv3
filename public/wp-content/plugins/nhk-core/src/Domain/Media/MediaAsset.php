<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class MediaAsset
{
    public function __construct(
        public string $assetId,
        public string $mediaId,
        public string $kind,
        public string $storageKey,
        public string $checksum,
        public string $mimeType,
        public int $byteSize,
        public ?int $width = null,
        public ?int $height = null,
        public string $visibility = 'PRIVATE',
        public array $metadata = [],
    ) {
        if (!UuidCodec::isValid($assetId) || !UuidCodec::isValid($mediaId)) throw new InvalidMedia('Media asset identity is invalid.');
        if (!in_array($kind, ['original', 'derivative'], true) || $storageKey === '' || !preg_match('/^[0-9a-f]{64}$/i', $checksum) || $mimeType === '' || $byteSize < 0) throw new InvalidMedia('Media asset is invalid.');
        if (!in_array($visibility, ['PUBLIC', 'PRIVATE', 'HIDDEN'], true)) throw new InvalidMedia('Media asset visibility is invalid.');
        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) throw new InvalidMedia('Media dimensions are invalid.');
    }
}
