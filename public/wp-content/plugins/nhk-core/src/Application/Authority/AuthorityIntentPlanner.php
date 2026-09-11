<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Planning-only interpreter and canonical inventory resolver. */
final class AuthorityIntentPlanner
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private CanonicalAuthorityStableKeyPolicy $stableKeys = new CanonicalAuthorityStableKeyPolicy()) {}

    /** @param array<string,mixed> $input @param array<string,mixed> $captureContext @return array<string,mixed> */
    public function plan(array $input, array $captureContext = []): array
    {
        $text = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $requests = $this->requests($text, $input);
        $plan = ['reuse' => [], 'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [], 'rejected_or_composed_facets' => [], 'ambiguities' => [], 'blockers' => []];
        foreach ($requests as $request) $this->resolveRequest($request, $plan);
        $this->relationRequests($text, $plan);
        if ($this->contains($text, 'đồng hồ để bàn') && $this->contains($text, 'pháp')) $plan['rejected_or_composed_facets'][] = ['reason' => 'COMPOSED_FACETS_NOT_NEW_IDENTITY', 'requested' => 'Đồng hồ để bàn Pháp', 'components' => ['table-clock', 'origin.france']];
        if ($this->contains($text, 'ly úp') || $this->contains($text, 'glass dome')) $plan['ambiguities'][] = ['code' => 'GLASS_DOME_SEMANTIC_REVIEW_REQUIRED', 'text' => 'Ly úp / glass dome phải được phân loại theo vocabulary/evidence; không tự tạo type, model hoặc subtype.'];
        $plan['create_authorities'] = array_map(static function (array $candidate): array {
            $candidate['name'] = (string) ($candidate['proposed_canonical_name'] ?? '');
            return $candidate;
        }, $plan['create_candidates']);
        $plan['create_relations'] = array_values($plan['relation_candidates']);
        $hasArticle = preg_match('/\b(?:bài|bài viết|bài giới thiệu|article)\b/iu', $text) === 1;
        $plan['article'] = $hasArticle ? ['requested' => true, 'mode' => ($plan['reuse'] !== [] || $plan['create_candidates'] !== []) ? 'MIXED' : 'EDITORIAL'] : null;
        $plan['plan_fingerprint'] = AuthorityPlanFingerprint::compute((string) ($captureContext['capture_id'] ?? ''), max(1, (int) ($captureContext['capture_revision'] ?? 1)), $plan, is_array($captureContext['contract'] ?? null) ? $captureContext['contract'] : (is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : []));
        return $plan;
    }

    /** @return list<array<string,mixed>> */
    private function requests(string $text, array $input): array
    {
        $queryOnly = preg_match('/(?:đã\s+có\s+chưa|có\s+cần\s+tạo|có\s+phải\s+tạo|đã\s+tồn\s+tại|có\s+không)/iu', $text) === 1;
        $allowCreate = !$queryOnly && preg_match('/\b(?:tạo|thêm|create|new)\b/iu', $text) === 1;
        $requests = [];
        if (preg_match('/tạo\s+thương hiệu\s+(.+?)(?:\s+(?:và|rồi)\s+(?:một\s+)?bài\b|[.!?]|$)/iu', $text, $match) === 1) $requests[] = ['type' => 'brand', 'name' => trim($match[1]), 'allow_create' => true];
        if (preg_match('/tạo\s+loại\s+(.+?)(?:\s+(?:thuộc|nằm\s+dưới)\s+|[.!?]|$)/iu', $text, $match) === 1) $requests[] = ['type' => 'classification', 'name' => trim($match[1]), 'family' => 'clock-type', 'allow_create' => true];
        if ($this->contains($text, 'đồng hồ để bàn')) $requests[] = ['type' => 'classification', 'name' => 'Đồng hồ để bàn', 'family' => 'clock-type', 'allow_create' => $allowCreate];
        if ($this->contains($text, 'đồng hồ cúc cu')) $requests[] = ['type' => 'classification', 'name' => 'Đồng hồ cúc cu', 'family' => 'clock-type', 'allow_create' => $allowCreate];
        if ($this->contains($text, 'mantel clock')) $requests[] = ['type' => 'classification', 'name' => 'Mantel Clock', 'family' => 'clock-type', 'allow_create' => $allowCreate];
        if ($this->contains($text, 'pháp') || $this->contains($text, 'france')) $requests[] = ['type' => 'classification', 'name' => 'Pháp', 'family' => 'origin', 'allow_create' => $allowCreate];
        if ($this->contains($text, 'hermle') && !array_filter($requests, static fn (array $item): bool => $item['type'] === 'brand' && strtolower($item['name']) === 'hermle')) $requests[] = ['type' => 'brand', 'name' => 'Hermle', 'allow_create' => $allowCreate];
        foreach ((array) ($input['subject_hints'] ?? []) as $hint) if (is_string($hint) && trim($hint) !== '') $requests[] = ['type' => 'classification', 'name' => trim($hint), 'family' => 'clock-type', 'allow_create' => $allowCreate];
        return $this->uniqueRequests($requests);
    }

    /** @param list<array<string,mixed>> $requests @return list<array<string,mixed>> */
    private function uniqueRequests(array $requests): array
    {
        $seen = []; $out = [];
        foreach ($requests as $request) { $key = $request['type'] . '|' . strtolower((string) ($request['stable_key'] ?? $request['name'])); if (isset($seen[$key])) continue; $seen[$key] = true; $out[] = $request; }
        return $out;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $plan */
    private function resolveRequest(array $request, array &$plan): void
    {
        $type = (string) $request['type'];
        if (!$this->types->has($type)) { $plan['blockers'][] = ['code' => 'UNSUPPORTED_ENTITY_TYPE', 'entity_type' => $type]; return; }
        $name = trim((string) $request['name']);
        $family = (string) ($request['family'] ?? '');
        // Stable keys for CREATE are always derived by the server policy. A
        // caller can identify an existing record by UUID/name/alias, but can
        // never supply the identity that a new canonical record will use.
        $stableKey = $this->stableKeys->preview($type, $name, ['family' => $family]);
        $matches = [];
        if (UuidCodec::isValid((string) ($request['canonical_uuid'] ?? ''))) { $entity = $this->authority->findByCanonicalId((string) $request['canonical_uuid']); if ($entity instanceof AuthorityEntity && $entity->entityType === $type && $entity->active()) $matches[$entity->canonicalId] = ['entity' => $entity, 'match' => 'uuid_exact']; }
        $entity = $this->authority->findByStableKey($type, $stableKey); if ($entity instanceof AuthorityEntity && $entity->active()) $matches[$entity->canonicalId] = ['entity' => $entity, 'match' => 'stable_key_exact'];
        foreach ($this->authority->listByType($type) as $candidate) {
            if ($this->normalize($candidate->canonicalName) === $this->normalize($name)) $matches[$candidate->canonicalId] = ['entity' => $candidate, 'match' => 'exact_canonical_name'];
            foreach ((array) ($candidate->payload['aliases'] ?? []) as $alias) if (is_string($alias) && $this->normalize($alias) === $this->normalize($name)) $matches[$candidate->canonicalId] = ['entity' => $candidate, 'match' => 'exact_alias'];
        }
        if (count($matches) > 1) { $plan['ambiguities'][] = ['code' => 'IDENTITY_CONFLICT', 'entity_type' => $type, 'name' => $name, 'candidate_ids' => array_keys($matches)]; return; }
        if (count($matches) === 1) { $match = array_values($matches)[0]; $this->reuse($plan, $match['entity'], $match['match'], $family); return; }
        $retired = $this->retiredExact($type, $stableKey, $name);
        if ($retired !== null) { $plan['ambiguities'][] = ['code' => 'RETIRED_MATCH_REQUIRES_EXPLICIT_REACTIVATION', 'entity_type' => $type, 'canonical_uuid' => $retired->canonicalId]; return; }
        $lexical = $this->boundedLexical($type, $name);
        if ($lexical !== []) { $plan['ambiguities'][] = ['code' => 'IDENTITY_CONFLICT', 'entity_type' => $type, 'name' => $name, 'review_only' => true, 'candidates' => $lexical]; return; }
        if (($request['allow_create'] ?? false) !== true) { $plan['ambiguities'][] = ['code' => 'CANONICAL_NOT_FOUND', 'entity_type' => $type, 'name' => $name, 'review_only' => true]; return; }
        $plan['create_candidates'][] = ['candidate_id' => $this->candidateId('CREATE', $type, $stableKey), 'action' => 'CREATE', 'entity_type' => $type, 'family' => $family !== '' ? $family : null, 'proposed_canonical_name' => $name, 'stable_key_preview' => $stableKey, 'scope' => 'capture', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'dependencies' => [], 'review_diagnostics' => []];
    }

    /** @param array<string,mixed> $plan */
    private function reuse(array &$plan, AuthorityEntity $entity, string $match, string $requestedFamily): void { $family = (string) ($entity->payload['family'] ?? ''); $plan['reuse'][] = ['candidate_id' => $this->candidateId('REUSE', $entity->entityType, $entity->canonicalId), 'action' => 'REUSE', 'entity_type' => $entity->entityType, 'canonical_uuid' => $entity->canonicalId, 'canonical_revision' => $entity->revision, 'canonical_name' => $entity->canonicalName, 'stable_key' => $entity->stableKey, 'family' => $family !== '' ? $family : ($requestedFamily !== '' ? $requestedFamily : null), 'match' => $match, 'scope' => 'capture']; }
    private function retiredExact(string $type, string $key, string $name): ?AuthorityEntity { foreach ($this->authority->listByType($type, true) as $entity) if (!$entity->active() && ($entity->stableKey === $key || $this->normalize($entity->canonicalName) === $this->normalize($name))) return $entity; return null; }
    /** @return list<array<string,mixed>> */
    private function boundedLexical(string $type, string $name): array { $needle = $this->normalize($name); $out = []; foreach ($this->authority->listByType($type) as $entity) if ($needle !== '' && (str_contains($this->normalize($entity->canonicalName), $needle) || str_contains($needle, $this->normalize($entity->canonicalName)))) $out[] = ['canonical_uuid' => $entity->canonicalId, 'canonical_name' => $entity->canonicalName, 'match' => 'bounded_lexical_review']; return array_slice($out, 0, 5); }
    private function candidateId(string $action, string $type, string $value): string { return 'candidate-' . substr(hash('sha256', $action . '|' . $type . '|' . $value), 0, 20); }
    /** @param array<string,mixed> $plan */
    private function relationRequests(string $text, array &$plan): void
    {
        if (preg_match('/(.+?)\s+(?:thuộc|nằm\s+dưới)\s+(.+?)(?:[.!?]|$)/iu', $text, $match) !== 1) return;
        $sourceName = trim((string) preg_replace('/^(?:tạo|thêm|create)\s+(?:loại\s+)?/iu', '', trim($match[1])));
        $targetName = trim((string) preg_replace('/^(?:loại)\s+/iu', '', trim($match[2])));
        $source = $this->plannedEntity($plan, $sourceName); $target = $this->plannedEntity($plan, $targetName);
        if ($source === null || $target === null) {
            $plan['blockers'][] = ['code' => 'CLASSIFICATION_HIERARCHY_ENDPOINT_UNRESOLVED', 'source' => $sourceName, 'target' => $targetName];
            return;
        }
        if (($source['entity_type'] ?? '') !== 'classification' || ($target['entity_type'] ?? '') !== 'classification') {
            $plan['blockers'][] = ['code' => 'CLASSIFICATION_HIERARCHY_ENDPOINT_INVALID', 'source' => $sourceName, 'target' => $targetName];
            return;
        }
        $dependencies = [];
        foreach ([$source, $target] as $endpoint) if (($endpoint['action'] ?? '') === 'CREATE') $dependencies[] = (string) $endpoint['candidate_id'];
        $sourceReference = (string) ($source['canonical_uuid'] ?? $source['candidate_id'] ?? '');
        $targetReference = (string) ($target['canonical_uuid'] ?? $target['candidate_id'] ?? '');
        $plan['relation_candidates'][] = [
            'candidate_id' => $this->candidateId('RELATION', 'subtype_of', $sourceReference . '|' . $targetReference),
            'action' => 'CREATE', 'predicate' => 'subtype_of',
            'source_type' => 'classification', 'source_uuid' => $source['canonical_uuid'] ?? null,
            'target_type' => 'classification', 'target_uuid' => $target['canonical_uuid'] ?? null,
            'source_candidate_id' => ($source['action'] ?? '') === 'CREATE' ? (string) $source['candidate_id'] : null,
            'target_candidate_id' => ($target['action'] ?? '') === 'CREATE' ? (string) $target['candidate_id'] : null,
            'source_revision' => (int) ($source['canonical_revision'] ?? 0), 'target_revision' => (int) ($target['canonical_revision'] ?? 0),
            'scope' => 'classification', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'dependencies' => $dependencies,
            'review_diagnostics' => [],
        ];
    }
    /** @return array<string,mixed>|null */
    private function plannedEntity(array $plan, string $name): ?array
    {
        $normalized = $this->normalize($name);
        foreach (['reuse', 'create_candidates'] as $bucket) foreach ((array) ($plan[$bucket] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateName = (string) ($candidate['canonical_name'] ?? $candidate['proposed_canonical_name'] ?? '');
            if ($candidateName !== '' && $this->normalize($candidateName) === $normalized) {
                if (($candidate['action'] ?? '') === 'REUSE') $candidate['canonical_uuid'] = $candidate['canonical_uuid'] ?? null;
                return $candidate;
            }
        }
        return null;
    }
    private function contains(string $text, string $needle): bool { return str_contains($this->normalize($text), $this->normalize($needle)); }
    private function normalize(string $value): string { return CanonicalPublicSlugPolicy::normalize($value); }
}
