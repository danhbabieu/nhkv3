<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Read-only, deterministic context resolution; it never creates or mutates semantic records. */
final class McpSemanticContextResolver
{
    private CanonicalAuthoritySubjectResolver $subjects;

    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types)
    {
        $this->subjects = new CanonicalAuthoritySubjectResolver($authority, $types);
    }

    /** @param array<string,mixed> $context */
    public function resolve(array $context): array
    {
        $context = $this->normalizeRequest($context);
        $resolved = [];
        $candidates = [];
        $ambiguities = [];
        $missing = [];
        $conflicts = [];
        $diagnostics = [];
        foreach ($context as $type => $query) {
            $specialLocator = str_starts_with((string) $type, '__');
            if (!$specialLocator && !$this->types->has((string) $type)) { $missing[] = (string) $type; $diagnostics[] = ['code' => 'UNKNOWN_ENTITY_TYPE', 'locator' => (string) $type]; continue; }
            $result = $this->resolveType((string) $type, is_array($query) ? $query : ['name' => (string) $query]);
            if ($result['conflict'] !== null) { $conflicts[(string) $type] = $result['conflict']; if ($specialLocator) $diagnostics[] = ['code' => strtoupper((string) $result['conflict']), 'locator' => $query]; continue; }
            if ($result['resolved'] !== null) { $resolved[$result['resolved']['type'] ?? (string) $type] = $result['resolved']; continue; }
            if ($result['candidates'] !== []) $candidates[(string) $type] = $result['candidates'];
            if ($result['ambiguous']) $ambiguities[(string) $type] = 'multiple_exact_candidates';
            else { $missing[] = (string) $type; if ($specialLocator) $diagnostics[] = ['code' => 'LOCATOR_NOT_FOUND', 'locator' => $query]; }
        }
        $response = ['resolved' => $resolved, 'candidates' => $candidates, 'ambiguities' => $ambiguities, 'missing' => array_values(array_unique($missing)), 'conflicts' => $conflicts, 'relations' => []];
        if ($diagnostics !== []) $response['diagnostics'] = $diagnostics;
        return $response;
    }

    /**
     * Accept both the canonical structured request and the older typed map.
     * The transport contract describes the former as one locator packet; the
     * application resolver consumes the latter internally.
     *
     * @param array<string,mixed> $context
     * @return array<string,array<string,mixed>>
     */
    private function normalizeRequest(array $context): array
    {
        $hasCanonicalFields = array_key_exists('canonical_uuid', $context)
            || array_key_exists('stable_key', $context)
            || array_key_exists('exact', $context)
            || array_key_exists('subject_hints', $context)
            || array_key_exists('subjects', $context);
        if (!$hasCanonicalFields) return $context;

        $normalized = [];
        $subjects = is_array($context['subjects'] ?? null) ? $context['subjects'] : [];
        foreach ($subjects as $subject) {
            if (!is_array($subject)) continue;
            $type = trim((string) ($subject['entity_type'] ?? $subject['type'] ?? ''));
            if ($type === '') continue;
            $normalized[$type] = array_replace($normalized[$type] ?? [], $subject);
        }

        $exact = is_array($context['exact'] ?? null) ? $context['exact'] : [];
        $type = trim((string) ($exact['entity_type'] ?? $context['entity_type'] ?? ''));
        if ($type === '') {
            $canonicalUuid = trim((string) ($context['canonical_uuid'] ?? ''));
            if ($canonicalUuid !== '') {
                if (!UuidCodec::isValid($canonicalUuid)) return ['__invalid_locator__' => ['canonical_uuid' => $canonicalUuid]];
                $entity = $this->authority->findByCanonicalId($canonicalUuid);
                if ($entity !== null) $type = $entity->entityType;
            }
        }
        if ($type === '' && trim((string) ($context['stable_key'] ?? '')) !== '') {
            $matches = $this->findByStableKey((string) $context['stable_key']);
            if (count($matches) === 1) $type = $matches[0]->entityType;
        }
        if ($type !== '') {
            $normalized[$type] = array_replace($normalized[$type] ?? [], [
                'canonical_uuid' => $context['canonical_uuid'] ?? null,
                'stable_key' => $context['stable_key'] ?? null,
                'name' => $exact['name'] ?? ($context['name'] ?? null),
            ], $exact);
        }

        if ($type === '' && ($context['canonical_uuid'] ?? null) !== null) {
            $canonicalUuid = (string) $context['canonical_uuid'];
            $normalized[UuidCodec::isValid($canonicalUuid) ? '__global_locator__' : '__invalid_locator__'] = ['canonical_uuid' => $canonicalUuid];
        } elseif ($type === '' && ($context['stable_key'] ?? null) !== null) {
            $normalized['__global_locator__'] = ['stable_key' => (string) $context['stable_key']];
        }

        // Hints are locators only. They may assist an explicitly typed query,
        // but cannot invent an entity type or candidate on their own.
        $hints = array_values(array_filter(array_map('strval', (array) ($context['subject_hints'] ?? [])), static fn (string $hint): bool => trim($hint) !== ''));
        if ($type !== '' && $hints !== []) $normalized[$type]['subject_hints'] = $hints;
        return $normalized;
    }

    /** @return list<\NHK\Core\Domain\Authority\AuthorityEntity> */
    private function findByStableKey(string $stableKey): array
    {
        $matches = [];
        foreach ($this->types->all() as $definition) {
            $entity = $this->authority->findByStableKey($definition->type, trim($stableKey));
            if ($entity !== null && $entity->active()) $matches[$entity->canonicalId] = $entity;
        }
        return array_values($matches);
    }

    /** @param array<string,mixed> $query */
    private function resolveType(string $type, array $query): array
    {
        if ($type === '__invalid_locator__') return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'invalid_canonical_uuid'];
        if ($type === '__global_locator__') {
            $canonicalUuid = trim((string) ($query['canonical_uuid'] ?? ''));
            if ($canonicalUuid !== '') {
                if (!UuidCodec::isValid($canonicalUuid)) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'invalid_canonical_uuid'];
                $entity = $this->authority->findByCanonicalId($canonicalUuid);
                return $entity !== null && $entity->active()
                    ? ['resolved' => $this->packet($entity, 'uuid_exact'), 'candidates' => [], 'ambiguous' => false, 'conflict' => null]
                    : ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'uuid_not_found'];
            }
            $matches = $this->findByStableKey((string) ($query['stable_key'] ?? ''));
            if (count($matches) === 1) return ['resolved' => $this->packet($matches[0], 'stable_key_exact'), 'candidates' => [], 'ambiguous' => false, 'conflict' => null, 'type' => $matches[0]->entityType];
            return ['resolved' => null, 'candidates' => array_map(fn ($entity): array => $this->packet($entity, 'stable_key_exact'), $matches), 'ambiguous' => count($matches) > 1, 'conflict' => null];
        }
        $explicit = trim((string) ($query['canonical_uuid'] ?? $query['id'] ?? $query['uuid'] ?? ''));
        if ($explicit !== '' && !UuidCodec::isValid($explicit)) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'invalid_canonical_uuid'];
        if ($explicit !== '' && $this->authority->findByCanonicalId($explicit) === null) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'uuid_not_found_or_type_mismatch'];
        $packets = $this->subjects->resolveForType($type, $query);
        return ['resolved' => count($packets) === 1 ? $packets[0] : null, 'candidates' => $packets, 'ambiguous' => count($packets) > 1, 'conflict' => null];
    }

}
