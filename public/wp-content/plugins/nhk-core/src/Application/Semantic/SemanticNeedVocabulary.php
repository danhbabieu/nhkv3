<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;

/** Bounded read-only vocabulary bridge for transient semantic need decomposition. */
final class SemanticNeedVocabulary
{
    public function isRegistered(string $facet, string $concept): bool
    {
        if (!in_array($facet, KnowledgeFacetProfile::FACETS, true)) return false;
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $concept) === 1;
    }
}
