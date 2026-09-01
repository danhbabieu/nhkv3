<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class Source
{
    public function __construct(public string $canonicalId, public string $stableKey, public string $title, public string $sourceType = 'website', public ?string $locator = null, public array $metadata = [], public bool $active = true, public int $revision = 1)
    {
        if (!UuidCodec::isValid($canonicalId) || !preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $stableKey) || trim($title) === '') throw new KnowledgeException('Source identity or title is invalid.');
        if (!in_array($sourceType, ['publication', 'website', 'archive', 'catalog', 'interview', 'other'], true) || $revision < 1) throw new KnowledgeException('Source type or revision is invalid.');
        if ($locator !== null && filter_var($locator, FILTER_VALIDATE_URL) === false && trim($locator) === '') throw new KnowledgeException('Source locator is invalid.');
    }

    public function isPublic(): bool
    {
        return strtoupper(trim((string) ($this->metadata['visibility'] ?? 'PRIVATE'))) === 'PUBLIC';
    }
}
