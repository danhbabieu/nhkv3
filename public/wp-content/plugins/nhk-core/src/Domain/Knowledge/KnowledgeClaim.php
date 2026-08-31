<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

final readonly class KnowledgeClaim
{
    public function __construct(public string $canonicalId, public string $stableKey, public string $claimText, public string $claimType = 'fact', public array $provenance = [], public bool $active = true, public int $revision = 1)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $canonicalId) || !preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $stableKey) || trim($claimText) === '') throw new KnowledgeException('Knowledge claim identity or text is invalid.');
        if (!in_array($claimType, ['fact', 'specification', 'history', 'technical', 'provenance', 'other'], true) || $revision < 1) throw new KnowledgeException('Knowledge claim type or revision is invalid.');
    }
}
