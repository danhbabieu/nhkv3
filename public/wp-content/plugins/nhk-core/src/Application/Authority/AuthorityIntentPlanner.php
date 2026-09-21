<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\ExplicitRelationIntentPlanner;
use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Planning-only interpreter and canonical inventory resolver. */
final class AuthorityIntentPlanner
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private CanonicalAuthorityStableKeyPolicy $stableKeys = new CanonicalAuthorityStableKeyPolicy(), private EntityProfileResolver $profiles = new EntityProfileResolver(), private ?ExplicitRelationIntentPlanner $relationIntents = null) {}

    /** @param array<string,mixed> $input @param array<string,mixed> $captureContext @return array<string,mixed> */
    public function plan(array $input, array $captureContext = []): array
    {
        $text = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $blockers = [];
        $requests = $this->requests($text, $input, $blockers);
        $plan = ['reuse' => [], 'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [], 'relation_reuse' => [], 'rejected_or_composed_facets' => [], 'ambiguities' => [], 'blockers' => $blockers];
        foreach ($requests as $request) $this->resolveRequest($request, $plan);
        $this->resolveSubjectHints((array) ($input['subject_hints'] ?? []), $plan);
        $authorityIntent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        $relationIntents = $authorityIntent['relation_intents'] ?? [];
        if (array_key_exists('relations', $authorityIntent)) {
            if (!is_array($authorityIntent['relations']) || !array_is_list($authorityIntent['relations'])) {
                $plan['blockers'][] = ['code' => 'RELATIONS_MALFORMED'];
            } else {
                $relationIntents = array_merge((array) $relationIntents, array_map(static function (mixed $relation): mixed {
                    if (!is_array($relation)) return $relation;
                    return [
                        'source_uuid' => $relation['source_uuid'] ?? null,
                        'predicate' => $relation['predicate'] ?? null,
                        'target_uuid' => $relation['target_uuid'] ?? null,
                    ];
                }, $authorityIntent['relations']));
            }
        }
        if (array_key_exists('relation_intents', $authorityIntent) || array_key_exists('relations', $authorityIntent)) {
            if ($this->relationIntents === null) {
                $plan['blockers'][] = ['code' => 'RELATION_INTENT_PLANNER_UNAVAILABLE'];
            } elseif (!is_array($relationIntents) || !array_is_list($relationIntents)) {
                $plan['blockers'][] = ['code' => 'RELATION_INTENTS_MALFORMED'];
            } else {
                $relationPlan = $this->relationIntents->plan($relationIntents);
                $plan['relation_candidates'] = array_merge($plan['relation_candidates'], (array) ($relationPlan['relation_candidates'] ?? []));
                $plan['relation_reuse'] = array_merge($plan['relation_reuse'], (array) ($relationPlan['relation_reuse'] ?? []));
                $plan['blockers'] = array_merge($plan['blockers'], (array) ($relationPlan['blockers'] ?? []));
                $plan['ambiguities'] = array_merge($plan['ambiguities'], (array) ($relationPlan['ambiguities'] ?? []));
            }
        } else {
            $this->relationRequests($text, $plan);
        }
        if ($this->contains($text, 'đồng hồ để bàn') && $this->contains($text, 'pháp')) $plan['rejected_or_composed_facets'][] = ['reason' => 'COMPOSED_FACETS_NOT_NEW_IDENTITY', 'requested' => 'Đồng hồ để bàn Pháp', 'components' => ['table-clock', 'origin.france']];
        if ($this->contains($text, 'ly úp') || $this->contains($text, 'glass dome')) $plan['ambiguities'][] = ['code' => 'GLASS_DOME_SEMANTIC_REVIEW_REQUIRED', 'text' => 'Ly úp / glass dome phải được phân loại theo vocabulary/evidence; không tự tạo type, model hoặc subtype.'];
        $plan['create_authorities'] = array_map(static function (array $candidate): array {
            $candidate['name'] = (string) ($candidate['proposed_canonical_name'] ?? '');
            return $candidate;
        }, $plan['create_candidates']);
        $plan['create_relations'] = array_values($plan['relation_candidates']);
        $purpose = strtoupper(trim((string) ($input['purpose'] ?? '')));
        $hasArticle = $purpose !== 'AUTHORITY' && preg_match('/\b(?:bài|bài viết|bài giới thiệu|article)\b/iu', $text) === 1;
        $plan['article'] = $hasArticle ? ['requested' => true, 'mode' => ($plan['reuse'] !== [] || $plan['create_candidates'] !== []) ? 'MIXED' : 'EDITORIAL'] : null;
        $plan['plan_fingerprint'] = AuthorityPlanFingerprint::compute((string) ($captureContext['capture_id'] ?? ''), max(1, (int) ($captureContext['capture_revision'] ?? 1)), $plan, is_array($captureContext['contract'] ?? null) ? $captureContext['contract'] : (is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : []));
        return $plan;
    }

    /** @return list<array<string,mixed>> */
    private function requests(string $text, array $input, array &$blockers): array
    {
        $hasStructuredRequest = $this->hasStructuredRequest($input);
        $requests = $this->structuredRequests($input, $blockers);
        if ($hasStructuredRequest) return $this->uniqueRequests($requests);

        return $this->textRequests($text);
    }

    /** @return list<array<string,mixed>> */
    private function textRequests(string $text): array
    {
        $requests = [];
        $queryOnly = preg_match('/(?:đã\s+có\s+chưa|có\s+cần\s+tạo|có\s+phải\s+tạo|đã\s+tồn\s+tại|có\s+không)/iu', $text) === 1;
        $allowCreate = !$queryOnly && preg_match('/\b(?:tạo|thêm|create|new)\b/iu', $text) === 1;
        if (preg_match('/tạo\s+thương hiệu\s+(.+?)(?:\s+(?:và|rồi)\s+(?:một\s+)?bài\b|[.!?]|$)/iu', $text, $match) === 1) $requests[] = ['type' => 'brand', 'name' => trim($match[1]), 'allow_create' => true];
        if (preg_match('/(?:tạo|thêm)\s+loại\s+(.+?)(?:\s+(?:thuộc|nằm\s+dưới)\s+|[.!?]|$)/iu', $text, $match) === 1) $requests[] = ['type' => 'classification', 'name' => trim($match[1]), 'family' => 'clock_type', 'allow_create' => true];
        foreach ($this->existingNamedRequests($text, $allowCreate) as $request) $requests[] = $request;
        foreach ($this->relationEndpointRequests($text, $allowCreate) as $request) $requests[] = $request;
        if ($queryOnly && preg_match('/(?:^|\b)đồng hồ\s+(.+?)\s+(?:đã\s+có\s+chưa|đã\s+tồn\s+tại|có\s+không)\b/iu', $text, $match) === 1) {
            $requests[] = ['type' => 'classification', 'name' => trim($match[1]), 'family' => 'clock_type', 'allow_create' => false];
        }
        foreach ($this->genericTypedRequests($text, $allowCreate) as $request) $requests[] = $request;
        return $this->uniqueRequests($requests);
    }

    /** @return list<array<string,mixed>> */
    private function existingNamedRequests(string $text, bool $allowCreate): array
    {
        $normalizedText = $this->normalize($text);
        $requests = [];
        foreach ($this->types->all() as $definition) {
            foreach ($this->authority->listByType($definition->type) as $entity) {
                $name = trim($entity->canonicalName);
                if ($name === '' || mb_strlen($name) < 3 || !str_contains($normalizedText, $this->normalize($name))) continue;
                $requests[] = [
                    'type' => $entity->entityType,
                    'name' => $name,
                    'family' => (string) ($entity->payload['family'] ?? ''),
                    'allow_create' => $allowCreate,
                ];
            }
        }
        return $requests;
    }

    /** @return list<array<string,mixed>> */
    private function relationEndpointRequests(string $text, bool $allowCreate): array
    {
        if (preg_match('/(.+?)\s+(?:thuộc|nằm\s+dưới)\s+(.+?)(?:[.!?]|$)/iu', $text, $match) !== 1) return [];
        $sourceName = trim((string) preg_replace('/^(?:tạo|thêm|create)\s+(?:loại\s+)?/iu', '', trim($match[1])));
        $targetName = trim((string) preg_replace('/^(?:loại)\s+/iu', '', trim($match[2])));
        if ($sourceName === '' || $targetName === '') return [];
        return [
            ['type' => 'classification', 'name' => $sourceName, 'family' => 'clock_type', 'allow_create' => $allowCreate],
            ['type' => 'classification', 'name' => $targetName, 'family' => 'clock_type', 'allow_create' => $allowCreate],
        ];
    }

    private function hasStructuredRequest(array $input): bool
    {
        $intent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        foreach (['requests', 'entity_type', 'type', 'canonical_uuid', 'uuid', 'name', 'canonical_name'] as $key) {
            if (array_key_exists($key, $intent) || array_key_exists($key, $input)) return true;
        }
        return array_key_exists('authority_requests', $input) || array_key_exists('relation_intents', $intent) || array_key_exists('relations', $intent);
    }

    /**
     * Structured requests are the explicit field-delta boundary for Authority
     * updates. Natural-language text may identify a subject, but it cannot
     * smuggle arbitrary payload fields into a governed plan.
     *
     * @return list<array<string,mixed>>
     */
    private function structuredRequests(array $input, array &$blockers): array
    {
        $intent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        $hasRaw = array_key_exists('requests', $intent) || array_key_exists('authority_requests', $input);
        $raw = $intent['requests'] ?? $input['authority_requests'] ?? [];
        if ($hasRaw && (!is_array($raw) || !array_is_list($raw))) {
            $blockers[] = ['code' => 'MALFORMED_AUTHORITY_REQUESTS'];
            $raw = [];
        }
        if ($raw === [] && (isset($intent['entity_type']) || isset($intent['type']) || isset($intent['canonical_uuid']) || isset($intent['name']))) $raw = [$intent];
        if ($raw === [] && (isset($input['entity_type']) || isset($input['type']) || isset($input['canonical_uuid']) || isset($input['name']))) $raw = [$input];

        $requests = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                $blockers[] = ['code' => 'MALFORMED_AUTHORITY_REQUEST'];
                continue;
            }
            $type = trim((string) ($item['entity_type'] ?? $item['type'] ?? ''));
            $name = trim((string) ($item['name'] ?? $item['canonical_name'] ?? ''));
            if ($type === '' && $name === '') {
                $blockers[] = ['code' => 'MALFORMED_AUTHORITY_REQUEST'];
                continue;
            }
            $deltaKey = null;
            foreach (['payload_delta', 'fields', 'desired_payload', 'payload'] as $key) if (array_key_exists($key, $item)) { $deltaKey = $key; break; }
            $delta = $item['payload_delta'] ?? $item['fields'] ?? $item['desired_payload'] ?? $item['payload'] ?? [];
            if ($deltaKey !== null && (!is_array($delta) || ($delta !== [] && array_is_list($delta)))) {
                $blockers[] = ['code' => 'MALFORMED_AUTHORITY_PAYLOAD_DELTA', 'entity_type' => $type];
                continue;
            }
            if ($delta === []) {
                $controlKeys = array_flip(['mode', 'requests', 'entity_type', 'type', 'name', 'canonical_name', 'canonical_uuid', 'uuid', 'stable_key', 'family', 'allow_create', 'payload_delta', 'fields', 'desired_payload', 'payload']);
                $explicitFields = array_diff_key($item, $controlKeys);
                if ($explicitFields !== []) $delta = $explicitFields;
            }
            if ($delta === [] && isset($item['description']) && is_string($item['description'])) $delta['description'] = trim($item['description']);
            if ($delta === [] && isset($intent['description']) && is_string($intent['description'])) $delta['description'] = trim($intent['description']);
            if ($delta === [] && isset($input['description']) && is_string($input['description'])) $delta['description'] = trim($input['description']);
            $requests[] = [
                'type' => $type,
                'entity_type' => $type,
                'name' => $name,
                'canonical_uuid' => trim((string) ($item['canonical_uuid'] ?? $item['uuid'] ?? '')),
                'stable_key' => trim((string) ($item['stable_key'] ?? '')),
                'family' => trim((string) ($item['family'] ?? '')),
                'payload_delta' => $delta,
                'allow_create' => ($item['allow_create'] ?? false) === true,
            ];
        }
        return $requests;
    }

    /** @return list<array<string,mixed>> */
    private function genericTypedRequests(string $text, bool $allowCreate): array
    {
        $labels = [
            'brand' => 'brand|thương hiệu',
            'model' => 'model|mẫu',
            'variant' => 'variant|phiên bản',
            'movement' => 'movement|bộ máy',
            'music' => 'music|bản nhạc',
            'component' => 'component|linh kiện',
            'classification' => 'classification|phân loại',
            'specimen' => 'specimen|mẫu vật',
            'product' => 'product|sản phẩm',
        ];
        $requests = [];
        foreach ($labels as $type => $labelPattern) {
            if (preg_match('/(?:tạo|thêm|create|new)\s+(?:một\s+)?(?:' . $labelPattern . ')\s+(.+?)(?:[.!?]|$)/iu', $text, $match) !== 1) continue;
            $name = trim((string) $match[1]);
            $family = null;
            if ($type === 'classification' && preg_match('/^(.*?)\s+family\s*=\s*([a-z_-]+)$/iu', $name, $familyMatch) === 1) {
                $name = trim((string) $familyMatch[1]);
                $family = strtolower(trim((string) $familyMatch[2]));
            }
            $requests[] = ['type' => $type, 'name' => $name, 'allow_create' => $allowCreate] + ($family === null ? [] : ['family' => $family]);
        }
        return $requests;
    }

    /** @param list<array<string,mixed>> $requests @return list<array<string,mixed>> */
    private function uniqueRequests(array $requests): array
    {
        $seen = []; $out = [];
        foreach ($requests as $request) {
            $identity = trim((string) ($request['stable_key'] ?? ''));
            if ($identity === '') $identity = trim((string) ($request['canonical_uuid'] ?? ''));
            if ($identity === '') $identity = trim((string) ($request['name'] ?? ''));
            $key = (string) ($request['type'] ?? '') . '|' . strtolower($identity);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $request;
        }
        return $out;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $plan */
    private function resolveRequest(array $request, array &$plan): void
    {
        $type = (string) $request['type'];
        if (!$this->types->has($type)) { $plan['blockers'][] = ['code' => 'UNSUPPORTED_ENTITY_TYPE', 'entity_type' => $type]; return; }
        $name = trim((string) $request['name']);
        $family = (string) ($request['family'] ?? '');
        // An existing Classification can be resolved by UUID or exact name
        // before family is required. Family remains mandatory for a new
        // Classification candidate.
        $definition = $this->types->get($type);
        $payloadDelta = $this->payloadDelta($request);
        $unsupported = array_values(array_diff(array_keys($payloadDelta), $definition->allowedFields));
        if ($unsupported !== []) {
            $plan['blockers'][] = ['code' => 'UNSUPPORTED_AUTHORITY_FIELD', 'entity_type' => $type, 'fields' => $unsupported];
            return;
        }
        $structuralParent = $this->structuralParent($type, $payloadDelta, $plan);
        if ($structuralParent === false) return;
        $canonicalUuid = trim((string) ($request['canonical_uuid'] ?? ''));
        if ($canonicalUuid !== '') {
            if (!UuidCodec::isValid($canonicalUuid)) {
                $plan['blockers'][] = ['code' => 'INVALID_AUTHORITY_UUID', 'entity_type' => $type, 'canonical_uuid' => $canonicalUuid];
                return;
            }
            $entityById = $this->authority->findByCanonicalId($canonicalUuid);
            if (!$entityById instanceof AuthorityEntity) {
                $plan['blockers'][] = ['code' => 'AUTHORITY_UUID_NOT_FOUND', 'entity_type' => $type, 'canonical_uuid' => $canonicalUuid];
                return;
            }
            if ($entityById->entityType !== $type) {
                $plan['blockers'][] = ['code' => 'AUTHORITY_UUID_TYPE_MISMATCH', 'entity_type' => $type, 'canonical_uuid' => $canonicalUuid, 'actual_entity_type' => $entityById->entityType];
                return;
            }
            if (!$entityById->active()) {
                $plan['blockers'][] = ['code' => 'RETIRED_TARGET_REQUIRES_EXPLICIT_REACTIVATION', 'entity_type' => $type, 'canonical_uuid' => $canonicalUuid];
                return;
            }
            if (!$this->eligibleMatch($entityById, $type, $family)) {
                $plan['blockers'][] = ['code' => 'AUTHORITY_UUID_SCOPE_MISMATCH', 'entity_type' => $type, 'canonical_uuid' => $canonicalUuid, 'family' => $family];
                return;
            }

            // UUID is the highest-priority identity locator. Hydrate the
            // server-owned identity before any create-only name/stable-key
            // policy is evaluated, and reject conflicting redundant locators.
            if ($name !== '' && !$this->matchesCanonicalNameOrAlias($entityById, $name)) {
                $plan['ambiguities'][] = [
                    'code' => 'IDENTITY_CONFLICT',
                    'entity_type' => $type,
                    'canonical_uuid' => $canonicalUuid,
                    'requested_name' => $name,
                    'canonical_name' => $entityById->canonicalName,
                    'review_only' => true,
                ];
                return;
            }
            if (($request['stable_key'] ?? '') !== '' && (string) $request['stable_key'] !== $entityById->stableKey) {
                $plan['ambiguities'][] = [
                    'code' => 'IDENTITY_CONFLICT',
                    'entity_type' => $type,
                    'canonical_uuid' => $canonicalUuid,
                    'requested_stable_key' => (string) $request['stable_key'],
                    'stable_key' => $entityById->stableKey,
                    'review_only' => true,
                ];
                return;
            }
            $updated = $this->updateCandidate($plan, $entityById, $payloadDelta);
            $this->reuse($plan, $entityById, 'uuid_exact', $family, !$updated && $payloadDelta !== [] ? 'NOOP_VALUES_MATCH' : null);
            if (is_array($structuralParent)) $this->planStructuralRelation($plan, $entityById, $structuralParent);
            return;
        }

        // Stable keys for CREATE are always derived by the server policy. A
        // caller can identify an existing record by name/alias, but can never
        // supply the identity that a new canonical record will use.
        $stableKeyFamily = $family === 'clock_type' ? 'clock-type' : $family;
        $stableKey = $family === '' && $type === 'classification'
            ? ''
            : $this->stableKeys->preview($type, $name, ['family' => $stableKeyFamily]);
        $matches = [];
        $entity = $stableKey !== '' ? $this->authority->findByStableKey($type, $stableKey) : null; if ($this->eligibleMatch($entity, $type, $family)) $matches[$entity->canonicalId] = ['entity' => $entity, 'match' => 'stable_key_exact'];
        foreach ($this->authority->listByType($type) as $candidate) {
            if (!$this->eligibleMatch($candidate, $type, $family)) continue;
            if ($this->normalize($candidate->canonicalName) === $this->normalize($name)) $matches[$candidate->canonicalId] = ['entity' => $candidate, 'match' => 'exact_canonical_name'];
            foreach ((array) ($candidate->payload['aliases'] ?? []) as $alias) if (is_string($alias) && $this->normalize($alias) === $this->normalize($name)) $matches[$candidate->canonicalId] = ['entity' => $candidate, 'match' => 'exact_alias'];
        }
        if (count($matches) > 1) { $plan['ambiguities'][] = ['code' => 'IDENTITY_CONFLICT', 'entity_type' => $type, 'name' => $name, 'candidate_ids' => array_keys($matches)]; return; }
        if (count($matches) === 1) {
            $match = array_values($matches)[0];
            $updated = $this->updateCandidate($plan, $match['entity'], $payloadDelta);
            $this->reuse($plan, $match['entity'], $match['match'], $family, !$updated && $payloadDelta !== [] ? 'NOOP_VALUES_MATCH' : null);
            if (is_array($structuralParent)) $this->planStructuralRelation($plan, $match['entity'], $structuralParent);
            return;
        }
        $retired = $this->retiredExact($type, $stableKey, $name);
        if ($retired !== null) { $plan['ambiguities'][] = ['code' => 'RETIRED_MATCH_REQUIRES_EXPLICIT_REACTIVATION', 'entity_type' => $type, 'canonical_uuid' => $retired->canonicalId]; return; }
        $lexical = $this->boundedLexical($type, $name);
        if ($lexical !== []) { $plan['ambiguities'][] = ['code' => 'IDENTITY_CONFLICT', 'entity_type' => $type, 'name' => $name, 'review_only' => true, 'candidates' => $lexical]; return; }
        if (($request['allow_create'] ?? false) !== true) { $plan['ambiguities'][] = ['code' => 'CANONICAL_NOT_FOUND', 'entity_type' => $type, 'name' => $name, 'review_only' => true]; return; }
        if ($type === 'classification' && $family === '') { $plan['blockers'][] = ['code' => 'CLASSIFICATION_FAMILY_REQUIRED', 'entity_type' => $type, 'name' => $name]; return; }
        if ($type === 'classification' && $family === 'clock-type') { $plan['blockers'][] = ['code' => 'LEGACY_CLOCK_TYPE_FAMILY_WRITE_REJECTED', 'entity_type' => $type, 'family' => $family, 'name' => $name]; return; }
        $entityPayload = array_replace($family !== '' ? ['family' => $family] : [], $payloadDelta);
        $candidate = ['candidate_id' => $this->candidateId('CREATE', $type, $stableKey), 'action' => 'CREATE', 'entity_type' => $type, 'family' => $family !== '' ? $family : null, 'proposed_canonical_name' => $name, 'name' => $name, 'aliases' => [], 'description' => (string) ($entityPayload['description'] ?? ''), 'entity_payload' => $entityPayload, 'stable_key_preview' => $stableKey, 'proposed_stable_key' => $stableKey, 'scope' => 'capture', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'ambiguities' => [], 'blockers' => [], 'dependencies' => [], 'review_diagnostics' => []];
        $plan['create_candidates'][] = $candidate;
        if (is_array($structuralParent)) $this->planStructuralRelation($plan, $candidate, $structuralParent);
    }

    /** @param array<string,mixed> $payloadDelta @param array<string,mixed> $plan @return array{predicate:string,target_type:string,target:AuthorityEntity}|null|false */
    private function structuralParent(string $type, array $payloadDelta, array &$plan): array|false|null
    {
        $field = match ($type) {
            'model' => ['brand_uuid', 'model_of', 'brand'],
            'variant' => ['model_uuid', 'variant_of', 'model'],
            default => null,
        };
        if ($field === null || !array_key_exists($field[0], $payloadDelta)) return null;
        $parentUuid = $payloadDelta[$field[0]];
        if (!is_string($parentUuid) || !UuidCodec::isValid(trim($parentUuid))) {
            $plan['blockers'][] = ['code' => 'AUTHORITY_STRUCTURAL_PARENT_UUID_INVALID', 'entity_type' => $type, 'field' => $field[0]];
            return false;
        }
        $parent = $this->authority->findByCanonicalId(trim($parentUuid));
        if (!$parent instanceof AuthorityEntity) {
            $plan['blockers'][] = ['code' => 'AUTHORITY_STRUCTURAL_PARENT_NOT_FOUND', 'entity_type' => $type, 'field' => $field[0], 'parent_uuid' => trim($parentUuid)];
            return false;
        }
        if ($parent->entityType !== $field[2]) {
            $plan['blockers'][] = ['code' => 'AUTHORITY_STRUCTURAL_PARENT_TYPE_MISMATCH', 'entity_type' => $type, 'field' => $field[0], 'parent_uuid' => $parent->canonicalId, 'expected_type' => $field[2], 'actual_type' => $parent->entityType];
            return false;
        }
        if (!$parent->active()) {
            $plan['blockers'][] = ['code' => 'AUTHORITY_STRUCTURAL_PARENT_INACTIVE', 'entity_type' => $type, 'field' => $field[0], 'parent_uuid' => $parent->canonicalId];
            return false;
        }
        return ['predicate' => $field[1], 'target_type' => $field[2], 'target' => $parent];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed>|AuthorityEntity $source @param array{predicate:string,target_type:string,target:AuthorityEntity} $parent */
    private function planStructuralRelation(array &$plan, array|AuthorityEntity $source, array $parent): void
    {
        $sourceType = $source instanceof AuthorityEntity ? $source->entityType : (string) ($source['entity_type'] ?? '');
        $sourceUuid = $source instanceof AuthorityEntity ? $source->canonicalId : trim((string) ($source['canonical_uuid'] ?? ''));
        $sourceCandidateId = !($source instanceof AuthorityEntity) && strtoupper((string) ($source['action'] ?? '')) === 'CREATE'
            ? trim((string) ($source['candidate_id'] ?? ''))
            : '';
        $target = $parent['target'];
        $packet = [
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'predicate' => $parent['predicate'],
            'target_type' => $parent['target_type'],
            'target_uuid' => $target->canonicalId,
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'reason' => 'Exact structural parent binding from registered Authority payload field.',
        ];
        if ($sourceCandidateId !== '') {
            $plan['relation_candidates'][] = [
                'candidate_id' => $this->candidateId('RELATION', $parent['predicate'], $sourceCandidateId . '|' . $target->canonicalId),
                'action' => 'CREATE', 'entity_type' => 'relation', 'predicate' => $parent['predicate'],
                'source_type' => $sourceType, 'source_uuid' => null, 'source_candidate_id' => $sourceCandidateId,
                'source_revision' => 0, 'target_type' => $parent['target_type'], 'target_uuid' => $target->canonicalId,
                'target_revision' => $target->revision, 'provenance' => $packet['provenance'], 'reason' => $packet['reason'],
                'scope' => 'capture', 'dependencies' => [$sourceCandidateId], 'review_diagnostics' => [],
            ];
            return;
        }
        if ($this->relationIntents !== null) {
            $relationPlan = $this->relationIntents->plan([$packet]);
            $plan['relation_candidates'] = array_merge($plan['relation_candidates'], (array) ($relationPlan['relation_candidates'] ?? []));
            $plan['relation_reuse'] = array_merge($plan['relation_reuse'], (array) ($relationPlan['relation_reuse'] ?? []));
            $plan['blockers'] = array_merge($plan['blockers'], (array) ($relationPlan['blockers'] ?? []));
            $plan['ambiguities'] = array_merge($plan['ambiguities'], (array) ($relationPlan['ambiguities'] ?? []));
            return;
        }
        $plan['relation_candidates'][] = [
            'candidate_id' => $this->candidateId('RELATION', $parent['predicate'], $sourceUuid . '|' . $target->canonicalId),
            'action' => 'CREATE', 'entity_type' => 'relation', 'predicate' => $parent['predicate'],
            'source_type' => $sourceType, 'source_uuid' => $sourceUuid, 'source_revision' => $source instanceof AuthorityEntity ? $source->revision : (int) ($source['canonical_revision'] ?? 0),
            'target_type' => $parent['target_type'], 'target_uuid' => $target->canonicalId, 'target_revision' => $target->revision,
            'provenance' => $packet['provenance'], 'reason' => $packet['reason'], 'scope' => 'capture', 'dependencies' => [], 'review_diagnostics' => [],
        ];
    }

    private function eligibleMatch(?AuthorityEntity $entity, string $type, string $requestedFamily): bool
    {
        if (!$entity instanceof AuthorityEntity || $entity->entityType !== $type || !$entity->active()) return false;
        if ($type !== 'classification' || $requestedFamily !== 'clock_type') return true;
        $resolution = $this->profiles->resolveProfile($entity);
        return $resolution->resolved() && $resolution->profileKey === 'clock_type';
    }

    private function matchesCanonicalNameOrAlias(AuthorityEntity $entity, string $name): bool
    {
        $normalized = $this->normalize($name);
        if ($normalized === '' || $normalized === $this->normalize($entity->canonicalName)) return true;
        foreach ((array) ($entity->payload['aliases'] ?? []) as $alias) {
            if (is_string($alias) && $normalized === $this->normalize($alias)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $plan */
    private function reuse(array &$plan, AuthorityEntity $entity, string $match, string $requestedFamily, ?string $reason = null): void { $family = (string) ($entity->payload['family'] ?? ''); $candidate = ['candidate_id' => $this->candidateId('REUSE', $entity->entityType, $entity->canonicalId), 'action' => 'REUSE', 'entity_type' => $entity->entityType, 'canonical_uuid' => $entity->canonicalId, 'canonical_revision' => $entity->revision, 'canonical_name' => $entity->canonicalName, 'stable_key' => $entity->stableKey, 'family' => $family !== '' ? $family : ($requestedFamily !== '' ? $requestedFamily : null), 'match' => $match, 'scope' => 'capture']; if ($reason !== null) $candidate['reason'] = $reason; $plan['reuse'][] = $candidate; }

    /** @param array<string,mixed> $plan @param array<string,mixed> $delta */
    private function updateCandidate(array &$plan, AuthorityEntity $entity, array $delta): bool
    {
        if ($delta === []) return false;
        $patch = [];
        foreach ($delta as $field => $value) {
            if (!array_key_exists($field, $entity->payload) || $entity->payload[$field] !== $value) $patch[$field] = $value;
        }
        if ($patch === []) return false;
        $payload = array_replace($entity->payload, $patch);
        $plan['update_candidates'][] = [
            'candidate_id' => $this->candidateId('UPDATE', $entity->entityType, $entity->canonicalId . '|' . $entity->revision . '|' . json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'action' => 'UPDATE',
            'entity_type' => $entity->entityType,
            'canonical_uuid' => $entity->canonicalId,
            'canonical_revision' => $entity->revision,
            'expected_revision' => $entity->revision,
            'canonical_name' => $entity->canonicalName,
            'stable_key' => $entity->stableKey,
            'family' => $payload['family'] ?? null,
            'payload_patch' => $patch,
            'entity_payload' => $payload,
            'before' => $entity->payload,
            'after' => $payload,
            'delta' => $delta,
            'scope' => 'capture',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'dependencies' => [],
            'review_diagnostics' => [],
        ];
        return true;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function payloadDelta(array $request): array
    {
        $delta = $request['payload_delta'] ?? [];
        return is_array($delta) && !array_is_list($delta) ? $delta : [];
    }

    /** @param list<mixed> $hints @param array<string,mixed> $plan */
    private function resolveSubjectHints(array $hints, array &$plan): void
    {
        foreach ($hints as $rawHint) {
            if (!is_string($rawHint)) continue;
            $hint = trim($rawHint);
            if ($hint === '') continue;
            $matches = [];
            if (UuidCodec::isValid($hint)) {
                $entity = $this->authority->findByCanonicalId($hint);
                if ($entity instanceof AuthorityEntity) $matches[$entity->canonicalId] = $entity;
            } else {
                foreach ($this->types->all() as $definition) {
                    foreach ($this->authority->listByType($definition->type) as $entity) {
                        $exactName = $this->normalize($entity->canonicalName) === $this->normalize($hint);
                        $exactAlias = in_array($this->normalize($hint), array_map(fn (mixed $alias): string => is_string($alias) ? $this->normalize($alias) : '', (array) ($entity->payload['aliases'] ?? [])), true);
                        if ($exactName || $exactAlias) $matches[$entity->canonicalId] = $entity;
                    }
                }
            }
            if (count($matches) > 1) {
                $plan['ambiguities'][] = ['code' => 'AMBIGUOUS_SUBJECT_HINT', 'hint' => $hint, 'candidate_ids' => array_keys($matches), 'review_only' => true];
                continue;
            }
            if (count($matches) === 1) {
                $entity = array_values($matches)[0];
                if ($entity->active()) $this->reuseIfAbsent($plan, $entity, 'subject_hint_exact');
                else $plan['ambiguities'][] = ['code' => 'RETIRED_SUBJECT_HINT', 'hint' => $hint, 'canonical_uuid' => $entity->canonicalId, 'review_only' => true];
                continue;
            }
            $plan['ambiguities'][] = ['code' => 'SUBJECT_HINT_NOT_FOUND', 'hint' => $hint, 'review_only' => true];
        }
    }

    /** @param array<string,mixed> $plan */
    private function reuseIfAbsent(array &$plan, AuthorityEntity $entity, string $match): void
    {
        foreach ((array) ($plan['reuse'] ?? []) as $candidate) if (($candidate['canonical_uuid'] ?? '') === $entity->canonicalId) return;
        $this->reuse($plan, $entity, $match, (string) ($entity->payload['family'] ?? ''));
    }
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
