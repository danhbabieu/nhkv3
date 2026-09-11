<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;

/** Reader projection; it never persists a hierarchy or facet relation. */
final class ClassificationReadModel
{
    public function __construct(private AuthorityRepository $authority, private GraphService $graph) {}

    /** @return array<string,mixed> */
    public function project(string $classificationId): array
    {
        $root = $this->authority->findByCanonicalId($classificationId);
        if (!$root instanceof AuthorityEntity || $root->entityType !== 'classification' || !$root->active()) throw new \InvalidArgumentException('CLASSIFICATION_NOT_AVAILABLE');
        $children = [];
        foreach ((array) $this->graph->findIncoming(new NodeReference('classification', $classificationId), 'subtype_of', 0, 200, false, 'classification')['items'] as $edge) {
            if (!$edge->isActive()) continue;
            $child = $this->authority->findByCanonicalId($edge->source->reference->endpoint_key);
            if ($child instanceof AuthorityEntity && $child->entityType === 'classification' && $child->active()) $children[] = ['kind' => 'CHILD_TYPE', 'id' => $child->canonicalId, 'name' => $child->canonicalName, 'revision' => $child->revision];
        }
        usort($children, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $facets = [];
        $family = (string) ($root->payload['family'] ?? '');
        foreach ($this->authority->listByType('classification') as $entity) {
            if ($entity->canonicalId === $root->canonicalId || (string) ($entity->payload['family'] ?? '') === $family) continue;
            $facets[] = ['kind' => 'FACET_FILTER', 'id' => $entity->canonicalId, 'name' => $entity->canonicalName, 'family' => (string) ($entity->payload['family'] ?? ''), 'revision' => $entity->revision];
        }
        usort($facets, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return ['root' => ['id' => $root->canonicalId, 'name' => $root->canonicalName, 'family' => $family, 'revision' => $root->revision], 'children' => $children, 'facet_filters' => $facets];
    }
}
