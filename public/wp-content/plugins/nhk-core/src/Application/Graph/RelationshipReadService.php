<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Graph\GraphRepository;
use NHK\Core\Domain\Graph\{EdgeState, EndpointTypeRegistry, NodeReference, PredicateDefinition, PredicateRegistry};

/** Read-only control-plane projection. It never calls a Graph lifecycle writer. */
final class RelationshipReadService
{
    public const OPERATIONS = ['ADD', 'REPLACE', 'REMOVE', 'REACTIVATE'];

    public function __construct(
        private EndpointTypeRegistry $endpoints,
        private PredicateRegistry $predicates,
        private GraphRepository $graph,
        private $endpointState = null,
        private $evidenceState = null,
    ) {}

    public function registry(): array
    {
        $endpoints = [];
        foreach ($this->endpoints->all() as $type => $resolver) {
            $endpoints[] = ['type' => $type, 'resolver' => get_class($resolver), 'revision_reader' => $resolver instanceof \NHK\Core\Contracts\Graph\EndpointRevisionReader];
        }
        usort($endpoints, static fn (array $a, array $b): int => $a['type'] <=> $b['type']);
        $predicates = array_map(static fn (PredicateDefinition $rule): array => self::rule($rule), $this->predicates->all());
        usort($predicates, static fn (array $a, array $b): int => $a['predicate'] <=> $b['predicate']);
        $payload = ['version' => PredicateRegistry::VERSION, 'endpoints' => $endpoints, 'predicates' => $predicates];
        return ['status' => 'available', 'read_only' => true, 'endpoints' => $endpoints, 'predicates' => $predicates, 'registry_hash' => hash('sha256', self::json($payload)), 'registry_version' => PredicateRegistry::VERSION];
    }

    public function list(array $filters, int $limit = 50, ?string $after = null): array
    {
        $kind = (string) ($filters['relationship_kind'] ?? 'graph');
        if ($kind !== 'graph') return $this->ownerSpecific($kind);
        $limit = min(200, max(1, $limit));
        $rows = [];
        foreach ($this->graph->allEdges(true) as $edge) {
            $row = $this->edge($edge);
            if (($filters['state'] ?? null) !== null && $row['state'] !== $filters['state']) continue;
            if (($filters['predicate'] ?? null) !== null && $row['predicate'] !== $filters['predicate']) continue;
            foreach (['source_type' => ['source', 'type'], 'source_id' => ['source', 'id'], 'target_type' => ['target', 'type'], 'target_id' => ['target', 'id']] as $key => [$side, $field]) {
                if (isset($filters[$key]) && (string) $row[$side][$field] !== (string) $filters[$key]) continue 2;
            }
            if ($after !== null && $after !== '' && strcmp($row['edge_uuid'], $after) <= 0) continue;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => $a['edge_uuid'] <=> $b['edge_uuid']);
        $items = array_slice($rows, 0, $limit);
        return ['status' => 'available', 'relationship_kind' => 'graph', 'items' => $items, 'next_cursor' => count($rows) > $limit ? $items[array_key_last($items)]['edge_uuid'] : null];
    }

    public function get(string $id): array
    {
        $edge = $this->graph->findByUuid($id);
        return $edge === null ? ['status' => 'not_found', 'reason' => 'RELATION_NOT_FOUND', 'relationship_kind' => 'graph'] : ['status' => 'available', 'relationship_kind' => 'graph', 'relationship' => $this->edge($edge)];
    }

    public function preview(array $input): array
    {
        $operation = strtoupper(trim((string) ($input['operation'] ?? '')));
        $source = $this->locator($input['source'] ?? null, $input['source_type'] ?? null, $input['source_id'] ?? null);
        $target = $this->locator($input['target'] ?? null, $input['target_type'] ?? null, $input['target_id'] ?? null);
        $kind = (string) ($input['relationship_kind'] ?? 'graph');
        $base = ['relationship_kind' => $kind, 'operation' => $operation, 'normalized_source' => $source, 'normalized_target' => $target, 'current_state' => 'UNKNOWN', 'current_relationships' => [], 'registry_rule' => null, 'cardinality_state' => ['status' => 'NOT_EVALUATED'], 'scope_state' => ['status' => 'NOT_EVALUATED'], 'evidence_state' => ['status' => 'NOT_EVALUATED'], 'provenance_state' => ['status' => 'NOT_EVALUATED'], 'revision_state' => ['status' => 'NOT_EVALUATED'], 'dependency_state' => ['status' => 'NOT_EVALUATED'], 'planned_transition' => [], 'blockers' => [], 'warnings' => [], 'safe_to_apply' => false, 'required_owner' => $kind === 'graph' ? 'Graph' : ucfirst($kind), 'required_governance_path' => 'READ_ONLY_PREVIEW_ONLY'];
        if ($kind !== 'graph') return $this->blocked($base, 'OWNER_SPECIFIC_SURFACE_REQUIRED');
        if (!in_array($operation, self::OPERATIONS, true)) return $this->blocked($base, 'INVALID_OPERATION');
        if ($source === null || $target === null) return $this->blocked($base, 'ENDPOINT_UNSUPPORTED');
        try { $source = $this->endpoints->normalize(new NodeReference($source['type'], $source['id'])); $target = $this->endpoints->normalize(new NodeReference($target['type'], $target['id'])); }
        catch (\Throwable) { return $this->blocked($base, 'ENDPOINT_UNSUPPORTED'); }
        $base['normalized_source'] = ['type' => $source->endpoint_type, 'id' => $source->endpoint_key];
        $base['normalized_target'] = ['type' => $target->endpoint_type, 'id' => $target->endpoint_key];
        $sourceState = $this->state($source); $targetState = $this->state($target);
        foreach ([['state' => $sourceState, 'code' => 'ENDPOINT_NOT_FOUND'], ['state' => $targetState, 'code' => 'ENDPOINT_NOT_FOUND']] as $item) if (!$item['state']['exists']) return $this->blocked($base, $item['code']);
        foreach ([['state' => $sourceState, 'code' => 'ENDPOINT_INACTIVE'], ['state' => $targetState, 'code' => 'ENDPOINT_INACTIVE']] as $item) if (!$item['state']['active']) return $this->blocked($base, $item['code']);
        $predicateKey = trim((string) ($input['predicate'] ?? $input['predicate_or_role'] ?? ''));
        try { $rule = $this->predicates->get($predicateKey); } catch (\Throwable) { return $this->blocked($base, 'PREDICATE_UNREGISTERED'); }
        $base['registry_rule'] = self::rule($rule);
        if (!in_array($source->endpoint_type, $rule->allowed_source_types, true)) return $this->blocked($base, 'SOURCE_TYPE_NOT_ALLOWED');
        if (!in_array($target->endpoint_type, $rule->allowed_target_types, true)) return $this->blocked($base, 'TARGET_TYPE_NOT_ALLOWED');
        if (!$rule->allow_self_relation && $source->key() === $target->key()) return $this->blocked($base, 'SELF_RELATION_FORBIDDEN');
        $base['scope_state'] = $this->scope($rule, $source, $target, $sourceState, $targetState);
        if ($base['scope_state']['status'] !== 'PASS') return $this->blocked($base, (string) $base['scope_state']['code']);
        if ($predicateKey === 'subtype_of' && $this->hasSubtypePath($target, $source)) return $this->blocked($base, 'HIERARCHY_CYCLE');
        $current = array_values(array_filter($this->graph->allEdges(true), static fn ($edge): bool => $edge->predicate === $predicateKey && $edge->source->reference->key() === $source->key() && $edge->target->reference->key() === $target->key()));
        $requestedRelation = trim((string) ($input['current_relation_id'] ?? ''));
        if ($requestedRelation !== '') {
            $candidate = $this->graph->findByUuid($requestedRelation);
            if ($candidate === null) return $this->blocked($base, 'RELATION_NOT_FOUND');
            if ($candidate->predicate !== $predicateKey) return $this->blocked($base, 'PREDICATE_UNREGISTERED');
            $current = [$candidate];
        }
        $base['current_relationships'] = array_map($this->edge(...), $current);
        $base['current_state'] = $current === [] ? 'ABSENT' : ($current[0]->isActive() ? 'ACTIVE' : 'RETIRED');
        if ($operation === 'ADD' && $current !== [] && !$current[0]->isActive()) return $this->blocked($base, 'RELATION_RETIRED_REACTIVATION_REQUIRED');
        if ($operation === 'REACTIVATE' && ($current === [] || $current[0]->isActive())) return $this->blocked($base, $current === [] ? 'RELATION_NOT_FOUND' : 'RELATION_ALREADY_ACTIVE');
        if ($operation === 'REMOVE' && ($current === [] || !$current[0]->isActive())) return $this->blocked($base, 'RELATION_NOT_FOUND');
        $base['cardinality_state'] = $this->cardinality($rule, $source, $target, $current, $operation);
        if ($base['cardinality_state']['status'] !== 'PASS') return $this->blocked($base, 'CARDINALITY_CONFLICT');
        $base['evidence_state'] = $this->evidence($rule, (array) ($input['evidence_refs'] ?? []));
        if ($base['evidence_state']['status'] !== 'PASS') return $this->blocked($base, (string) $base['evidence_state']['code']);
        $base['provenance_state'] = trim((string) ($input['provenance'] ?? '')) !== '' || $rule->provenance_requirement === 'OPTIONAL' ? ['status' => 'PASS'] : ['status' => 'BLOCKED', 'code' => 'PROVENANCE_REQUIRED'];
        if ($base['provenance_state']['status'] !== 'PASS') return $this->blocked($base, 'PROVENANCE_REQUIRED');
        if ($sourceState['revision'] === null || $targetState['revision'] === null) return $this->blocked($base, 'REVISION_UNAVAILABLE');
        $base['revision_state'] = ['status' => 'PASS', 'source_revision' => $sourceState['revision'], 'target_revision' => $targetState['revision'], 'expected_revision' => $input['expected_revision'] ?? null];
        $base['dependency_state'] = ['status' => 'PASS', 'closure' => [['type' => $source->endpoint_type, 'id' => $source->endpoint_key, 'revision' => $sourceState['revision']], ['type' => $target->endpoint_type, 'id' => $target->endpoint_key, 'revision' => $targetState['revision']]]];
        $base['planned_transition'] = $this->plan($operation, $current, $source, $predicateKey, $target);
        $base['safe_to_apply'] = true;
        return $base;
    }

    private function state(NodeReference $ref): array
    {
        if (is_callable($this->endpointState)) { $state = ($this->endpointState)($ref); if (is_array($state)) return ['exists' => (bool) ($state['exists'] ?? false), 'active' => (bool) ($state['active'] ?? false), 'revision' => isset($state['revision']) ? (int) $state['revision'] : null, 'family' => $state['family'] ?? null]; }
        $resolver = $this->endpoints->resolver($ref->endpoint_type);
        if (method_exists($resolver, 'state')) { $state = $resolver->state($ref); if (is_array($state)) return ['exists' => (bool) ($state['exists'] ?? false), 'active' => (bool) ($state['active'] ?? false), 'revision' => isset($state['revision']) ? (int) $state['revision'] : null, 'family' => $state['family'] ?? null]; }
        return ['exists' => $resolver->exists($ref), 'active' => true, 'revision' => $resolver instanceof \NHK\Core\Contracts\Graph\EndpointRevisionReader ? $resolver->revision($ref) : null, 'family' => null];
    }
    private function scope(PredicateDefinition $rule, NodeReference $source, NodeReference $target, array $sourceState, array $targetState): array
    { if (($rule->constraints['same_family'] ?? false) === true && ($sourceState['family'] === null || $targetState['family'] === null)) return ['status' => 'BLOCKED', 'code' => 'CLASSIFICATION_FAMILY_UNRESOLVED']; if (($rule->constraints['same_family'] ?? false) === true && $sourceState['family'] !== $targetState['family']) return ['status' => 'BLOCKED', 'code' => 'CLASSIFICATION_FAMILY_MISMATCH']; return ['status' => 'PASS']; }
    private function cardinality(PredicateDefinition $rule, NodeReference $source, NodeReference $target, array $current, string $operation): array
    { if ($operation === 'REMOVE' || $operation === 'REACTIVATE') return ['status' => 'PASS']; foreach ($this->graph->allEdges(false) as $edge) { if ($edge->predicate !== $rule->key) continue; if ($rule->outbound_cardinality === 'ONE' && $edge->source->reference->key() === $source->key() && ($current === [] || $edge->edge_uuid !== $current[0]->edge_uuid)) return ['status' => 'BLOCKED']; if ($rule->inbound_cardinality === 'ONE' && $edge->target->reference->key() === $target->key() && ($current === [] || $edge->edge_uuid !== $current[0]->edge_uuid)) return ['status' => 'BLOCKED']; } return ['status' => 'PASS']; }
    private function evidence(PredicateDefinition $rule, array $refs): array
    { if ($rule->evidence_requirement !== 'REQUIRED') return ['status' => 'PASS']; if ($refs === []) return ['status' => 'BLOCKED', 'code' => 'EVIDENCE_REQUIRED']; foreach ($refs as $ref) { $id = is_array($ref) ? (string) ($ref['evidence_id'] ?? '') : ''; if ($id === '' || (is_callable($this->evidenceState) && !($this->evidenceState)($id))) return ['status' => 'BLOCKED', 'code' => 'EVIDENCE_INVALID']; } return ['status' => 'PASS']; }
    private function plan(string $operation, array $current, NodeReference $source, string $predicate, NodeReference $target): array
    { $desired = ['action' => 'CREATE_OR_REACTIVATE', 'source' => ['type' => $source->endpoint_type, 'id' => $source->endpoint_key], 'predicate' => $predicate, 'target' => ['type' => $target->endpoint_type, 'id' => $target->endpoint_key]]; return match ($operation) { 'REMOVE' => [['action' => 'RELATION_RETIRE', 'relation_id' => $current[0]->edge_uuid, 'expected_edge_revision' => $current[0]->revision]], 'REPLACE' => array_merge($current === [] ? [] : [['action' => 'RELATION_RETIRE', 'relation_id' => $current[0]->edge_uuid, 'expected_edge_revision' => $current[0]->revision]], [$desired]), 'REACTIVATE' => [['action' => 'RELATION_REACTIVATE', 'relation_id' => $current[0]->edge_uuid, 'expected_edge_revision' => $current[0]->revision]], 'ADD' => $current !== [] && $current[0]->isActive() ? [['action' => 'RELATION_NO_OP', 'reason' => 'ALREADY_ACTIVE', 'idempotent' => true, 'relation_id' => $current[0]->edge_uuid, 'current_revision' => $current[0]->revision]] : [$desired], default => [$desired] }; }
    private function edge($edge): array { return ['edge_uuid' => $edge->edge_uuid, 'source' => ['type' => $edge->source->reference->endpoint_type, 'id' => $edge->source->reference->endpoint_key], 'predicate' => $edge->predicate, 'target' => ['type' => $edge->target->reference->endpoint_type, 'id' => $edge->target->reference->endpoint_key], 'state' => $edge->state === EdgeState::ACTIVE ? 'ACTIVE' : 'RETIRED', 'revision' => $edge->revision]; }
    private function locator(mixed $value, mixed $type, mixed $id): ?array { if (is_array($value)) { $type = $value['type'] ?? $value['endpoint_type'] ?? $type; $id = $value['id'] ?? $value['uuid'] ?? $value['endpoint_key'] ?? $id; } return is_string($type) && is_string($id) && trim($type) !== '' && trim($id) !== '' ? ['type' => trim($type), 'id' => trim($id)] : null; }
    private function blocked(array $base, string $code): array { $base['blockers'][] = $code; $base['safe_to_apply'] = false; return $base; }
    private function ownerSpecific(string $kind): array { return ['status' => 'available', 'relationship_kind' => $kind, 'items' => [], 'owner' => $kind === 'media_usage' ? 'MediaUsage' : 'Evidence', 'diagnostics' => ['code' => 'OWNER_SPECIFIC_SURFACE_REQUIRED', 'read_only' => true]]; }
    private static function rule(PredicateDefinition $rule): array { return ['predicate' => $rule->key, 'active' => $rule->active, 'source_types' => $rule->allowed_source_types, 'target_types' => $rule->allowed_target_types, 'outbound_cardinality' => $rule->outbound_cardinality, 'inbound_cardinality' => $rule->inbound_cardinality, 'self_relation_allowed' => $rule->allow_self_relation, 'scope_notes' => $rule->scope_notes, 'evidence_requirement' => $rule->evidence_requirement, 'provenance_requirement' => $rule->provenance_requirement, 'public_projection_notes' => $rule->public_projection_notes, 'constraints' => $rule->constraints]; }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private function hasSubtypePath(NodeReference $from, NodeReference $to, array $seen = []): bool
    { $key = $from->key(); if (isset($seen[$key])) return false; $seen[$key] = true; foreach ($this->graph->allEdges(false) as $edge) { if ($edge->predicate !== 'subtype_of' || $edge->source->reference->key() !== $from->key()) continue; if ($edge->target->reference->key() === $to->key()) return true; if ($this->hasSubtypePath($edge->target->reference, $to, $seen)) return true; } return false; }
}
