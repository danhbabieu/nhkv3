<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
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
        $explicit = trim((string) ($query['canonical_uuid'] ?? $query['id'] ?? $query['uuid'] ?? ''));
        if ($explicit !== '' && !UuidCodec::isValid($explicit)) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'invalid_canonical_uuid'];
        if ($explicit !== '' && $this->authority->findByCanonicalId($explicit) === null) return ['resolved' => null, 'candidates' => [], 'ambiguous' => false, 'conflict' => 'uuid_not_found_or_type_mismatch'];
        $packets = $this->subjects->resolveForType($type, $query);
        return ['resolved' => count($packets) === 1 ? $packets[0] : null, 'candidates' => $packets, 'ambiguous' => count($packets) > 1, 'conflict' => null];
    }

}
