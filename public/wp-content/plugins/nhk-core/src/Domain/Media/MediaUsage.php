<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class MediaUsage
{
    public function __construct(
        public string $usageId,
        public string $mediaId,
        public string $endpointType,
        public string $endpointKey,
        public string $role,
        public int $sortOrder = 0,
    ) {
        if (!UuidCodec::isValid($usageId) || !UuidCodec::isValid($mediaId)) throw new InvalidMedia('Media usage identity is invalid.');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $endpointType) || $endpointKey === '' || !in_array($role, ['featured', 'inline', 'gallery', 'thumbnail', 'source'], true) || $sortOrder < 0) throw new InvalidMedia('Media usage is invalid.');
    }
}
