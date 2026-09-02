<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

final class VideoInternalSemanticResearcher
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types)
    {
    }

    /** @return array{resolved:list<array<string,mixed>>,ambiguous:list<array<string,mixed>>,missing:list<string>} */
    public function research(string $text): array
    {
        $needle = $this->normalize($text);
        $resolved = []; $ambiguous = []; $missing = [];
        foreach ($this->types->all() as $definition) {
            $matches = [];
            foreach ($this->authority->listByType($definition->type) as $entity) {
                if (!$entity->active() || !$this->contains($needle, $entity->canonicalName, $entity->payload['aliases'] ?? [])) continue;
                $matches[] = $entity;
            }
            if (count($matches) === 1) $resolved[] = $this->packet($matches[0]);
            elseif (count($matches) > 1) $ambiguous[] = ['type' => $definition->type, 'candidates' => array_map($this->packet(...), $matches)];
        }
        return ['resolved' => $resolved, 'ambiguous' => $ambiguous, 'missing' => $missing];
    }

    private function contains(string $haystack, string $name, mixed $aliases): bool
    {
        foreach (array_merge([$name], is_array($aliases) ? $aliases : []) as $term) {
            $term = $this->normalize((string) $term);
            if ($term !== '' && strlen($term) >= 2 && str_contains($haystack, $term)) return true;
        }
        return false;
    }

    /** @return array{id:string,type:string,name:string,match:string} */
    private function packet(AuthorityEntity $entity): array { return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'name' => $entity->canonicalName, 'match' => 'internal_exact_name_or_alias']; }
    private function normalize(string $value): string { $value = trim($value); return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value); }
}
