<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

final class ComparisonPageQuery
{
    public function __construct(private EntityPageQuery $entities) {}

    /** @return array{references:array{left:string,right:string},items:array{left:?array,right:?array}} */
    public function read(string $left = '', string $right = ''): array
    {
        return [
            'references' => ['left' => $left, 'right' => $right],
            'items' => ['left' => $this->resolve($left), 'right' => $this->resolve($right)],
        ];
    }

    private function resolve(string $reference): ?array
    {
        $parts = explode('/', trim($reference, '/'), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
        $entity = $this->entities->detail(rawurldecode($parts[0]), rawurldecode($parts[1]));
        if (!is_array($entity)) return null;
        return [
            'id' => (string) ($entity['id'] ?? ''),
            'type' => (string) ($entity['type'] ?? ''),
            'stable_key' => (string) ($entity['stable_key'] ?? ''),
            'name' => (string) ($entity['name'] ?? ''),
            'payload' => is_array($entity['payload'] ?? null) ? $entity['payload'] : [],
        ];
    }
}
