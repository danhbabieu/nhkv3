<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

final readonly class Evidence
{
    public function __construct(public string $canonicalId, public string $claimId, public string $sourceId, public string $relation = 'supports', public string $excerpt = '', public ?string $locator = null, public bool $active = true, public int $revision = 1, public array $metadata = [])
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (!preg_match($uuid, $canonicalId) || !preg_match($uuid, $claimId) || !preg_match($uuid, $sourceId) || trim($excerpt) === '') throw new KnowledgeException('Evidence identity or excerpt is invalid.');
        if (!in_array($relation, ['supports', 'contradicts', 'qualifies'], true) || $revision < 1) throw new KnowledgeException('Evidence relation or revision is invalid.');
    }
}
