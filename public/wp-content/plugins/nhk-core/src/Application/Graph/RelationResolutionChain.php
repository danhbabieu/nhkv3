<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

final class RelationResolutionChain
{
    private const ORDER = ['explicit_uuid', 'structured_metadata', 'stable_key', 'intended_relations', 'deterministic_identity', 'reviewed_mapping'];

    /** @param array<string,callable(array<string,mixed>):(?array)> $resolvers */
    public function __construct(private array $resolvers) {}

    /** @return array<string,mixed>|null */
    public function resolve(array $record): ?array
    {
        foreach (self::ORDER as $method) {
            if (!isset($this->resolvers[$method])) continue;
            $result = ($this->resolvers[$method])($record);
            if (is_array($result)) return $result + ['resolution_method' => $method];
        }
        return null;
    }
}
