<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Read-only Capture subject resolver using the registered identity order. */
final class CanonicalAuthoritySubjectResolver
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types) {}

    /** @return list<array<string,mixed>> */
    public function __invoke(string $hint): array
    {
        return $this->resolve($hint);
    }

    /** @return list<array<string,mixed>> */
    public function resolve(string $hint): array
    {
        $hint = trim($hint);
        if ($hint === '') return [];

        if (UuidCodec::isValid($hint)) {
            $entity = $this->authority->findByCanonicalId($hint);
            return $entity instanceof AuthorityEntity && $entity->active() ? [$this->packet($entity, 'uuid_exact')] : [];
        }

        $stableMatches = [];
        foreach ($this->types->all() as $definition) {
            $entity = $this->authority->findByStableKey($definition->type, $hint);
            if ($entity instanceof AuthorityEntity && $entity->active()) $stableMatches[$entity->canonicalId] = $this->packet($entity, 'stable_key_exact');
        }
        if ($stableMatches !== []) return array_values($stableMatches);

        $needle = $this->normalize($hint);
        if ($needle === '') return [];
        $matches = [];
        foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) {
            if ($this->normalize($entity->canonicalName) === $needle || $this->hasAlias($entity, $needle)) $matches[$entity->canonicalId] = $this->packet($entity, 'exact_name_or_alias');
        }
        return array_values($matches);
    }

    private function hasAlias(AuthorityEntity $entity, string $needle): bool
    {
        foreach ((array) ($entity->payload['aliases'] ?? []) as $alias) if (is_string($alias) && $this->normalize($alias) === $needle) return true;
        return false;
    }

    /** @return array<string,mixed> */
    private function packet(AuthorityEntity $entity, string $match): array
    {
        return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'revision' => $entity->revision, 'match' => $match];
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
