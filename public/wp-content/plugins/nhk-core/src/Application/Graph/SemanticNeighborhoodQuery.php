<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\NodeReference;

/** Named, bounded read-model over the canonical Graph; it never persists derived edges. */
final class SemanticNeighborhoodQuery
{
    /** @param array<string,SemanticNeighborhoodProfile>|null $profiles */
    public function __construct(private RelatedSemanticQuery $related, ?array $profiles = null)
    {
        $this->profiles = $profiles ?? SemanticNeighborhoodProfile::defaults();
    }

    /** @var array<string,SemanticNeighborhoodProfile> */
    private array $profiles;

    /** @return array{status:string,items:list<array<string,mixed>>,reason?:string} */
    public function query(NodeReference $root, string $profile, int $maxHops = 2, int $limit = 50): array
    {
        $definition = $this->profiles[$profile] ?? null;
        if ($definition === null || $maxHops < 1 || $maxHops > $definition->maxHops) {
            return ['status' => 'unsupported', 'items' => [], 'reason' => 'PROFILE_OR_BOUNDS_INVALID'];
        }
        return $this->related->query($root, $definition->targetTypes, $maxHops, $limit);
    }
}
