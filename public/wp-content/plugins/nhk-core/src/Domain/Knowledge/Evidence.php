<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class Evidence
{
    public function __construct(public string $canonicalId, public string $claimId, public string $sourceId, public string $relation = 'supports', public string $excerpt = '', public ?string $locator = null, public bool $active = true, public int $revision = 1, public array $metadata = [])
    {
        if (!UuidCodec::isValid($canonicalId) || !UuidCodec::isValid($claimId) || !UuidCodec::isValid($sourceId) || trim($excerpt) === '') throw new KnowledgeException('Evidence identity or excerpt is invalid.');
        if (!in_array($relation, ['supports', 'contradicts', 'qualifies'], true) || $revision < 1) throw new KnowledgeException('Evidence relation or revision is invalid.');
    }

    public function isPublic(): bool
    {
        return strtoupper(trim((string) ($this->metadata['visibility'] ?? 'PRIVATE'))) === 'PUBLIC';
    }
}
