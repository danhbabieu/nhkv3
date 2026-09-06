<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

final class PublicUrlReprojectionPlanner
{
    public function __construct(private ?CanonicalPublicSlugPolicy $slugs = null)
    {
        $this->slugs ??= new CanonicalPublicSlugPolicy();
    }

    /**
     * @param list<array<string,mixed>> $inventory
     * @param \Closure(array<string,mixed>,string):bool $externallyOccupied
     * @return array{status:string,items:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function plan(array $inventory, \Closure $externallyOccupied): array
    {
        usort($inventory, static function(array $a, array $b): int {
            return [
                (string)($a['route_type'] ?? ''),
                (string)($a['scope'] ?? ''),
                (string)($a['kind'] ?? ''),
                (string)($a['owner_id'] ?? ''),
            ] <=> [
                (string)($b['route_type'] ?? ''),
                (string)($b['scope'] ?? ''),
                (string)($b['kind'] ?? ''),
                (string)($b['owner_id'] ?? ''),
            ];
        });

        $reserved = [];
        $items = [];
        $blocked = 0;
        $changes = 0;
        $kept = 0;

        foreach ($inventory as $item) {
            $routeType = trim((string)($item['route_type'] ?? ''));
            $scope = trim((string)($item['scope'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            $current = trim((string)($item['current_slug'] ?? ''));
            $qualifiers = array_values(array_filter(array_map(static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '', (array)($item['qualifiers'] ?? [])), static fn(string $value): bool => $value !== ''));
            $planned = $item;
            $planned['desired_slug'] = null;
            $planned['action'] = 'BLOCKED';
            $planned['blocker'] = null;

            if ($routeType === '' || $scope === '' || $name === '') {
                $planned['blocker'] = 'PUBLIC_URL_INVENTORY_INVALID';
                $blocked++;
                $items[] = $planned;
                continue;
            }

            $keyPrefix = $routeType . '|' . $scope . '|';
            try {
                $desired = $this->slugs->resolve($name, $qualifiers, function(string $candidate) use (&$reserved, $keyPrefix, $item, $externallyOccupied): bool {
                    return isset($reserved[$keyPrefix . $candidate]) || $externallyOccupied($item, $candidate);
                });
            } catch (\RuntimeException $error) {
                $planned['blocker'] = $error->getMessage() === 'PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION'
                    ? 'COLLISION_REQUIRES_RECONCILIATION'
                    : 'PUBLIC_URL_PLANNING_FAILED';
                $blocked++;
                $items[] = $planned;
                continue;
            }

            $reserved[$keyPrefix . $desired] = true;
            $planned['desired_slug'] = $desired;
            if ($current === $desired) {
                $planned['action'] = 'KEEP';
                $kept++;
            } else {
                $planned['action'] = $current === '' ? 'ALLOCATE' : 'CHANGE';
                $changes++;
            }
            $items[] = $planned;
        }

        return [
            'status' => $blocked === 0 ? 'READY' : 'BLOCKED',
            'items' => $items,
            'counts' => ['total' => count($items), 'change' => $changes, 'keep' => $kept, 'blocked' => $blocked],
        ];
    }
}
