<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

final readonly class KnowledgeFacetProfile
{
    public const FACETS = ['identity', 'chronology', 'recognition', 'configuration', 'movement', 'music', 'component', 'provenance', 'domestic_cultural', 'rarity_frequency', 'specimen_observation'];
    public const SCOPES = ['entity', 'brand', 'model', 'variant', 'movement', 'specimen_observation'];

    public function __construct(public string $facet, public string $scope)
    {
        if (!in_array($facet, self::FACETS, true) || !in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Unknown Knowledge facet or scope.');
        }
    }

    public function toMetadata(): array
    {
        return ['facet' => $this->facet, 'scope' => $this->scope, 'version' => 1];
    }
}
