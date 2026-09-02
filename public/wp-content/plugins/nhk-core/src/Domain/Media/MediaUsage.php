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
        public string $altText = '',
        public string $caption = '',
        /** @var list<string> */
        public array $keywordGroups = [],
    ) {
        if (!UuidCodec::isValid($usageId) || !UuidCodec::isValid($mediaId)) throw new InvalidMedia('Media usage identity is invalid.');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $endpointType) || $endpointKey === '' || $sortOrder < 0) throw new InvalidMedia('Media usage is invalid.');
        try {
            MediaUsageRoleRegistry::assertKnown($role);
            foreach ($keywordGroups as $group) SeoKeywordGroupRegistry::assertKnown((string) $group);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidMedia($error->getMessage(), (int) $error->getCode(), $error);
        }
        if (strlen($altText) > 1000 || strlen($caption) > 2000) throw new InvalidMedia('Media usage contextual SEO text is too long.');
    }
}
