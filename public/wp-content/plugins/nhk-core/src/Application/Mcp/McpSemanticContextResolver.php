<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Read-only, deterministic context resolution; it never creates or mutates semantic records. */
final class McpSemanticContextResolver
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types) {}

    /** @param array<string,mixed> $context */
    public function resolve(array $context): array
    {
        $resolved = [];
        $candidates = [];
        $ambiguities = [];
        $missing = [];
        $conflicts = [];
        foreach ($context as $type => $query) {
            if (!$this->types->has((string) $type)) { $missing[] = (string) $type; continue; }
            $result = $this->resolveType((string) $type, is_array($query) ? $query : ['name' => (string) $query]);
            if ($result['conflict'] !== null) { $conflicts[(string) $type] = $result['conflict']; continue; }
            if ($result['resolved'] !== null) { $resolved[(string) $type] = $result['resolved']; continue; }
            if ($result['candidates'] !== []) $candidates[(string) $type] = $result['candidates'];
            if ($result['ambiguous']) $ambiguities[(string) $type] = 'multiple_exact_candidates';
            else $missing[] = (string) $type;
        }
        return ['resolved' => $resolved, 'candidates' => $candidates, 'ambiguities' => $ambiguities, 'missing' => array_values(array_unique($missing)), 'conflicts' => $conflicts, 'relations' => []];
    }

    /** @param array<string,mixed> $query */
    private function resolveType(string $type, array $query): array
    {
        $id = trim((string) ($query['canonical_uuid'] ?? $query['id'] ?? $query['uuid'] ?? ''));
        if ($id !== '') {
            if (!UuidCodec::isValid($id)) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'invalid_canonical_uuid'];
            $entity = $this->authority->findByCanonicalId($id);
            if ($entity === null || $entity->entityType !== $type || !$entity->active()) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'uuid_not_found_or_type_mismatch'];
            return ['resolved' => $this->packet($entity, 'uuid_exact'), 'candidates' => [], 'ambiguous' => false, 'conflict' => null];
        }
        $stableKey = trim((string) ($query['stable_key'] ?? ''));
        if ($stableKey !== '') {
            $entity = $this->authority->findByStableKey($type, $stableKey);
            if ($entity !== null && $entity->active()) return ['resolved' => $this->packet($entity, 'stable_key_exact'), 'candidates' => [], 'ambiguous' => false, 'conflict' => null];
        }
        $needle = $this->normalize((string) ($query['name'] ?? $query['value'] ?? ''));
        if ($needle === '') return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => null];
        $matches = [];
        foreach ($this->authority->listByType($type) as $entity) {
            if ($this->normalize($entity->canonicalName) === $needle || $this->aliases($entity, $needle)) $matches[] = $entity;
        }
        $packets = array_map(fn (AuthorityEntity $entity): array => $this->packet($entity, 'exact_name_or_alias'), $matches);
        return ['resolved' => count($matches) === 1 ? $packets[0] : null, 'candidates' => $packets, 'ambiguous' => count($matches) > 1, 'conflict' => null];
    }

    private function aliases(AuthorityEntity $entity, string $needle): bool
    {
        foreach (($entity->payload['aliases'] ?? []) as $alias) if (is_string($alias) && $this->normalize($alias) === $needle) return true;
        return false;
    }

    private function packet(AuthorityEntity $entity, string $match): array
    {
        return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'match' => $match];
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
