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
        public string $title = '',
        public int $revision = 1,
        public string $placementKey = '',
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
        if (strlen($title) > 255 || $revision < 1 || strlen($placementKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $placementKey) === 1) throw new InvalidMedia('Media usage title, placement key or revision is invalid.');
    }

    public function placementAnchor(): string
    {
        $identity = $this->placementKey !== '' ? $this->placementKey : $this->usageId;
        return 'nhk-placement-' . hash('sha256', $this->endpointType . "\0" . $this->endpointKey . "\0" . $identity);
    }
}
