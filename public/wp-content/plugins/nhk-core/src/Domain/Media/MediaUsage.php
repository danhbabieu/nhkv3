<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

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
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $usageId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $mediaId)) throw new InvalidMedia('Media usage identity is invalid.');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $endpointType) || $endpointKey === '' || !in_array($role, ['featured', 'inline', 'gallery', 'thumbnail', 'source'], true) || $sortOrder < 0) throw new InvalidMedia('Media usage is invalid.');
    }
}
