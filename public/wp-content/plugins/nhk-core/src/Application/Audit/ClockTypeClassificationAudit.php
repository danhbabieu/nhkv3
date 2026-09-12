<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Contracts\Audit\ClockTypeAuditEvidenceReader;
use NHK\Core\Contracts\Authority\{AuthorityRepository, CursorAuthorityInventoryReader};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Deterministic, read-only dry-run audit for legacy Clock-Type membership.
 *
 * This service deliberately has no writer dependency. It inventories the
 * current Authority and Graph read boundaries, and treats any unavailable
 * evidence surface as unavailable rather than as an empty semantic result.
 */
final class ClockTypeClassificationAudit
{
    public const ALREADY_CANONICAL = 'ALREADY_CANONICAL';
    public const READY_FOR_OWNER_REVIEW = 'READY_FOR_OWNER_REVIEW';
    public const LEGACY_TARGET_REQUIRES_REVIEW = 'LEGACY_TARGET_REQUIRES_REVIEW';
    public const RETIRED_RELATION_REVIEW_REQUIRED = 'RETIRED_RELATION_REVIEW_REQUIRED';
    public const AMBIGUOUS_CLOCK_TYPE = 'AMBIGUOUS_CLOCK_TYPE';
    public const INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    public const DISCOVERY_HINT_ONLY = 'DISCOVERY_HINT_ONLY';
    public const NO_CLOCK_TYPE_SIGNAL = 'NO_CLOCK_TYPE_SIGNAL';
    public const TARGET_UNRESOLVED = 'TARGET_UNRESOLVED';
    public const FAMILY_UNRESOLVED = 'FAMILY_UNRESOLVED';
    public const SOURCE_UNSUPPORTED = 'SOURCE_UNSUPPORTED';
    public const SOURCE_INACTIVE = 'SOURCE_INACTIVE';
    public const DEPENDENCY_UNAVAILABLE = 'DEPENDENCY_UNAVAILABLE';
    public const INVALID_CLOCK_TYPE_MEMBERSHIP_TARGET = 'INVALID_CLOCK_TYPE_MEMBERSHIP_TARGET';

    /** @var list<string> */
    private const SOURCE_TYPES = ['model', 'variant', 'specimen', 'product'];

    /** @var list<string> */
    private const KNOWN_OTHER_FAMILIES = ['case_form', 'origin', 'material', 'dial_form', 'recognition_feature', 'feature', 'configuration', 'music'];

    /** @var list<string> */
    private const SUPPORTED_EVIDENCE = ['SUPPORTED', 'VERIFIED', 'APPROVED'];

    public function __construct(
        private AuthorityRepository $authority,
        private GraphService $graph,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
        private ?ClockTypeAuditEvidenceReader $evidence = null,
        private ?CursorAuthorityInventoryReader $cursorInventory = null,
    ) {}

    /**
     * @param array{limit?:int,after?:string,discovery_hints?:array<string,list<string>>} $options
     */
    public function audit(array $options = []): ClockTypeClassificationAuditReport
    {
        $targets = $this->inventoryTargets();
        $sources = $this->sourceSnapshot($options);
        $results = [];
        $hints = is_array($options['discovery_hints'] ?? null) ? $options['discovery_hints'] : [];
        foreach ($sources['items'] as $source) $results[] = $this->auditSource($source, $targets, is_array($hints[$source->canonicalId] ?? null) ? $hints[$source->canonicalId] : []);

        $counts = array_fill_keys([self::ALREADY_CANONICAL, self::READY_FOR_OWNER_REVIEW, self::LEGACY_TARGET_REQUIRES_REVIEW, self::RETIRED_RELATION_REVIEW_REQUIRED, self::AMBIGUOUS_CLOCK_TYPE, self::INSUFFICIENT_EVIDENCE, self::DISCOVERY_HINT_ONLY, self::NO_CLOCK_TYPE_SIGNAL, self::TARGET_UNRESOLVED, self::FAMILY_UNRESOLVED, self::SOURCE_UNSUPPORTED, self::SOURCE_INACTIVE, self::DEPENDENCY_UNAVAILABLE, self::INVALID_CLOCK_TYPE_MEMBERSHIP_TARGET], 0);
        foreach ($results as $result) $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
        ksort($counts);
        $samples = [];
        foreach ([self::READY_FOR_OWNER_REVIEW, self::AMBIGUOUS_CLOCK_TYPE, self::DISCOVERY_HINT_ONLY, self::LEGACY_TARGET_REQUIRES_REVIEW] as $status) $samples[$status] = [];
        foreach ($results as $result) {
            $status = $result['status'];
            if (!array_key_exists($status, $samples) || count($samples[$status]) >= 10) continue;
            $samples[$status][] = array_intersect_key($result, array_flip(['source_type', 'source_uuid', 'source_revision', 'source_display_name', 'target_uuid', 'target_revision', 'target_name', 'target_family', 'targets', 'status', 'resolution_basis', 'provenance_class', 'support_summary', 'blockers', 'warnings']));
        }
        $pagination = [
            'limit' => $sources['limit'],
            'after' => $sources['after'],
            'next_cursor' => $sources['next_cursor'],
            'ordering' => 'source_type_order_then_canonical_uuid',
            'authority_cursor_surface' => 'UNAVAILABLE_CURRENT_LIST_BY_TYPE_API',
            'audit_state' => 'TRANSIENT_READ_ONLY',
        ];
        $payload = ['target_inventory' => $targets['inventory'], 'results' => $results, 'result_counts' => $counts, 'pagination' => $pagination, 'samples' => $samples];
        return new ClockTypeClassificationAuditReport($targets['inventory'], $results, $counts, $pagination, $this->fingerprint($payload), $samples);
    }

    /** @return array<string,mixed> */
    private function inventoryTargets(): array
    {
        $rows = [];
        $canonical = [];
        $legacy = [];
        try { $entities = $this->readAuthorityType('classification', true); } catch (\Throwable) {
            return ['inventory' => ['status' => 'UNAVAILABLE', 'records' => [], 'counts' => [], 'counterpart_counts' => []], 'canonical' => [], 'legacy' => [], 'all' => [], 'allById' => []];
        }
        usort($entities, static fn (AuthorityEntity $a, AuthorityEntity $b): int => strcmp($a->canonicalId, $b->canonicalId));
        foreach ($entities as $entity) {
            $profile = $this->profiles->resolveProfile($entity);
            $family = $entity->payload['family'] ?? null;
            $family = is_string($family) && trim($family) !== '' ? trim($family) : null;
            $bucket = $this->targetBucket($entity, $profile, $family);
            $counterpart = null;
            if ($bucket === 'LEGACY_CLOCK_TYPE') $counterpart = $this->counterpartStatus($entity, $entities);
            $row = [
                'canonical_uuid' => $entity->canonicalId,
                'revision' => $entity->revision,
                'canonical_name' => $entity->canonicalName,
                'stable_key' => $entity->stableKey,
                'state' => $entity->state->name,
                'family' => $family,
                'bucket' => $bucket,
                'profile_status' => $profile->status,
                'counterpart_status' => $counterpart,
            ];
            $rows[] = $row;
            if ($entity->active() && $bucket === 'CANONICAL_CLOCK_TYPE') $canonical[$entity->canonicalId] = $entity;
            if ($entity->active() && $bucket === 'LEGACY_CLOCK_TYPE') $legacy[$entity->canonicalId] = $entity;
        }
        $counts = [];
        $counterpartCounts = [];
        foreach ($rows as $row) {
            $counts[$row['bucket']] = ($counts[$row['bucket']] ?? 0) + 1;
            if ($row['counterpart_status'] !== null) $counterpartCounts[$row['counterpart_status']] = ($counterpartCounts[$row['counterpart_status']] ?? 0) + 1;
        }
        ksort($counts); ksort($counterpartCounts);
        $allById = [];
        foreach ($entities as $entity) $allById[$entity->canonicalId] = $entity;
        return ['inventory' => ['status' => 'AUDITED', 'records' => $rows, 'counts' => $counts, 'counterpart_counts' => $counterpartCounts], 'canonical' => $canonical, 'legacy' => $legacy, 'all' => $entities, 'allById' => $allById];
    }

    private function targetBucket(AuthorityEntity $entity, object $profile, ?string $family): string
    {
        if (!$entity->active()) return 'INACTIVE';
        if ($profile->status === 'RESOLVED' && $profile->profileKey === 'clock_type') return 'CANONICAL_CLOCK_TYPE';
        if ($profile->status === 'COMPATIBILITY_READ' && $profile->profileKey === 'clock_type') return 'LEGACY_CLOCK_TYPE';
        if ($family === null) return 'FAMILY_MISSING';
        return in_array($family, self::KNOWN_OTHER_FAMILIES, true) ? 'OTHER_CLASSIFICATION_FAMILY' : 'FAMILY_UNRESOLVED';
    }

    /** @param list<AuthorityEntity> $entities */
    private function counterpartStatus(AuthorityEntity $legacy, array $entities): string
    {
        $name = $this->normalize($legacy->canonicalName);
        $matches = array_values(array_filter($entities, fn (AuthorityEntity $entity): bool => $entity->active() && $entity->canonicalId !== $legacy->canonicalId && $this->profiles->resolveProfile($entity)->status === 'RESOLVED' && $this->profiles->resolveProfile($entity)->profileKey === 'clock_type' && $this->normalize($entity->canonicalName) === $name));
        if (count($matches) > 1) return 'AMBIGUOUS_COUNTERPART';
        if ($matches !== []) return 'POSSIBLE_CANONICAL_COUNTERPART';
        return 'NO_COUNTERPART_FOUND';
    }

    /** @param array{canonical:array<string,AuthorityEntity>,legacy:array<string,AuthorityEntity>,all:list<AuthorityEntity>} $targets @param list<string> $hints @return array<string,mixed> */
    private function auditSource(AuthorityEntity $source, array $targets, array $hints): array
    {
        $base = ['source_type' => $source->entityType, 'source_uuid' => $source->canonicalId, 'source_revision' => $source->revision, 'source_display_name' => $source->canonicalName, 'predicate' => 'classified_as'];
        if (!in_array($source->entityType, self::SOURCE_TYPES, true)) return $this->result(self::SOURCE_UNSUPPORTED, $base, ['SOURCE_TYPE_NOT_REGISTERED']);
        if (!$source->active()) return $this->result(self::SOURCE_INACTIVE, $base, ['SOURCE_INACTIVE']);

        try {
            $graph = $this->readClassifiedEdges($source);
        } catch (\Throwable) {
            return $this->result(self::DEPENDENCY_UNAVAILABLE, $base, ['GRAPH_READ_SURFACE_UNAVAILABLE']);
        }
        $activeCanonical = [];
        $activeLegacy = [];
        $retired = [];
        $edgeDiagnostics = [];
        foreach ((array) ($graph['items'] ?? []) as $edge) {
            if (!$edge instanceof GraphEdge) { $edgeDiagnostics[] = 'INVALID_GRAPH_EDGE_ROW'; continue; }
            $targetId = $edge->target->reference->endpoint_key;
            $target = $targets['allById'][$targetId] ?? null;
            if (!$target instanceof AuthorityEntity) { $edgeDiagnostics[] = 'DANGLING_CLASSIFIED_AS_TARGET'; continue; }
            $profile = $this->profiles->resolveProfile($target);
            if (!$target->active()) { $edgeDiagnostics[] = 'INACTIVE_CLASSIFIED_AS_TARGET'; continue; }
            if ($profile->status === 'RESOLVED' && $profile->profileKey === 'clock_type') {
                if ($edge->isActive()) $activeCanonical[$target->canonicalId] = [$target, $edge]; else $retired[$target->canonicalId] = [$target, $edge];
            } elseif ($profile->status === 'COMPATIBILITY_READ' && $profile->profileKey === 'clock_type') {
                if ($edge->isActive()) $activeLegacy[$target->canonicalId] = [$target, $edge]; else $retired[$target->canonicalId] = [$target, $edge];
            } else {
                $edgeDiagnostics[] = $profile->diagnostic === 'FAMILY_NOT_CLOCK_TYPE' ? 'CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE' : 'CLASSIFICATION_FAMILY_UNRESOLVED';
            }
        }
        if ($activeCanonical !== []) {
            $targetRows = array_values(array_map(fn (array $pair): array => $this->targetReference($pair[0], $pair[1]), $activeCanonical));
            usort($targetRows, static fn (array $a, array $b): int => strcmp($a['target_uuid'], $b['target_uuid']));
            $warnings = $edgeDiagnostics;
            if (count($targetRows) > 1) $warnings[] = 'MULTIPLE_ACTIVE_CANONICAL_MEMBERSHIPS';
            return $this->result(self::ALREADY_CANONICAL, array_merge($base, ['targets' => $targetRows]), [], $warnings);
        }
        if ($activeLegacy !== []) {
            $targetRows = array_values(array_map(fn (array $pair): array => $this->targetReference($pair[0], $pair[1]), $activeLegacy));
            return $this->result(self::LEGACY_TARGET_REQUIRES_REVIEW, array_merge($base, ['targets' => $targetRows]), ['LEGACY_FAMILY_NOT_WRITE_ELIGIBLE'], $edgeDiagnostics);
        }
        if ($retired !== []) {
            $targetRows = array_values(array_map(fn (array $pair): array => $this->targetReference($pair[0], $pair[1]), $retired));
            return $this->result(self::RETIRED_RELATION_REVIEW_REQUIRED, array_merge($base, ['targets' => $targetRows]), ['RETIRED_RELATION_REQUIRES_EXPLICIT_REVIEW'], $edgeDiagnostics);
        }
        if (in_array('DANGLING_CLASSIFIED_AS_TARGET', $edgeDiagnostics, true) || in_array('INACTIVE_CLASSIFIED_AS_TARGET', $edgeDiagnostics, true)) {
            return $this->result(self::TARGET_UNRESOLVED, $base, ['INVALID_OR_INACTIVE_CLASSIFIED_AS_TARGET'], $edgeDiagnostics);
        }
        if (in_array('CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE', $edgeDiagnostics, true) || in_array('CLASSIFICATION_FAMILY_UNRESOLVED', $edgeDiagnostics, true)) {
            return $this->result(self::INVALID_CLOCK_TYPE_MEMBERSHIP_TARGET, $base, ['EXISTING_EDGE_TARGET_IS_NOT_CANONICAL_CLOCK_TYPE'], $edgeDiagnostics);
        }

        if ($this->evidence === null) {
            return $this->result(self::DEPENDENCY_UNAVAILABLE, $base, ['EVIDENCE_READ_SURFACE_GAP'], $edgeDiagnostics);
        }
        try { $records = $this->evidence->findForSubject($source->entityType, $source->canonicalId); } catch (\Throwable) { return $this->result(self::DEPENDENCY_UNAVAILABLE, $base, ['EVIDENCE_READ_SURFACE_UNAVAILABLE'], $edgeDiagnostics); }
        $exact = [];
        $legacyEvidence = [];
        $hint = false;
        $blockers = $edgeDiagnostics;
        foreach ($records as $record) {
            $tier = strtoupper(trim((string) ($record['tier'] ?? '')));
            $targetId = trim((string) ($record['target_uuid'] ?? ''));
            if ($tier === 'E' || ($record['kind'] ?? '') === 'lexical' || ($record['kind'] ?? '') === 'media_inference') { $hint = true; continue; }
            if (!UuidCodec::isValid($targetId)) { $blockers[] = 'TARGET_UNRESOLVED'; continue; }
            $target = $targets['allById'][$targetId] ?? null;
            if (!$target instanceof AuthorityEntity || !$target->active()) { $blockers[] = 'TARGET_UNRESOLVED'; continue; }
            $profile = $this->profiles->resolveProfile($target);
            if ($profile->status === 'COMPATIBILITY_READ' && $profile->profileKey === 'clock_type') { $legacyEvidence[$targetId] = $target; continue; }
            if ($profile->status !== 'RESOLVED' || $profile->profileKey !== 'clock_type') { $blockers[] = $profile->diagnostic === 'FAMILY_NOT_CLOCK_TYPE' ? 'CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE' : 'FAMILY_UNRESOLVED'; continue; }
            if (($record['source_uuid'] ?? $source->canonicalId) !== $source->canonicalId || ($record['source_type'] ?? $source->entityType) !== $source->entityType) { $blockers[] = 'SCOPE_MISMATCH'; continue; }
            if (($record['scope_source_uuid'] ?? $source->canonicalId) !== $source->canonicalId) { $blockers[] = 'SCOPE_MISMATCH'; continue; }
            if (($record['scope'] ?? '') !== $source->entityType) { $blockers[] = 'SCOPE_MISMATCH'; continue; }
            if (array_key_exists('source_revision', $record) && (int) $record['source_revision'] !== $source->revision) { $blockers[] = 'SOURCE_REVISION_MISMATCH'; continue; }
            if (array_key_exists('target_revision', $record) && (int) $record['target_revision'] !== $target->revision) { $blockers[] = 'TARGET_REVISION_MISMATCH'; continue; }
            $exact[$targetId] = ['target' => $target, 'record' => $record];
        }
        if ($legacyEvidence !== []) return $this->result(self::LEGACY_TARGET_REQUIRES_REVIEW, $base, ['LEGACY_FAMILY_NOT_WRITE_ELIGIBLE'], $blockers);
        if (count($exact) > 1) return $this->result(self::AMBIGUOUS_CLOCK_TYPE, $base, ['MULTIPLE_EXACT_CLOCK_TYPE_TARGETS'], $blockers, ['targets' => $this->evidenceTargets($exact)]);
        if ($exact !== []) {
            $item = array_values($exact)[0]; $record = $item['record'];
            $evidenceStatus = strtoupper(trim((string) ($record['evidence_status'] ?? $record['support_status'] ?? '')));
            $provenance = trim((string) ($record['provenance_class'] ?? ''));
            $tier = strtoupper(trim((string) ($record['tier'] ?? '')));
            if (!in_array($evidenceStatus, self::SUPPORTED_EVIDENCE, true)) $blockers[] = 'EVIDENCE_NOT_SUPPORTED';
            if ($provenance === '' || $provenance === 'SYSTEM_INFERENCE') $blockers[] = 'PROVENANCE_NOT_ACCEPTABLE';
            if (!in_array($tier, ['A', 'B', 'C'], true)) $blockers[] = 'EVIDENCE_TIER_NOT_REVIEW_ELIGIBLE';
            if ($blockers !== []) return $this->result(self::INSUFFICIENT_EVIDENCE, $base, array_values(array_unique($blockers)));
            $target = $item['target'];
            $packet = array_merge($base, ['target_uuid' => $target->canonicalId, 'target_revision' => $target->revision, 'target_name' => $target->canonicalName, 'target_family' => 'clock_type', 'resolution_basis' => (string) ($record['basis'] ?? 'EXACT_CANONICAL_EVIDENCE'), 'provenance_class' => $provenance, 'supporting_canonical_ids' => $this->safeIds($record['supporting_canonical_ids'] ?? []), 'claim_uuid' => $record['claim_uuid'] ?? null, 'claim_revision' => $record['claim_revision'] ?? null, 'support_summary' => is_array($record['support_summary'] ?? null) ? $record['support_summary'] : [], 'status' => self::READY_FOR_OWNER_REVIEW]);
            $packet['candidate_fingerprint'] = $this->fingerprint($packet);
            return $this->result(self::READY_FOR_OWNER_REVIEW, $packet, [], $blockers);
        }
        if ($hint || $this->matchingHints($source, $hints, $targets['canonical'])) return $this->result(self::DISCOVERY_HINT_ONLY, $base, ['WEAK_SIGNAL_NOT_REVIEW_ELIGIBLE'], $blockers);
        if ($records !== []) return $this->result(self::INSUFFICIENT_EVIDENCE, $base, array_values(array_unique([...$blockers, 'NO_EXACT_CLOCK_TYPE_EVIDENCE'])));
        return $this->result(self::NO_CLOCK_TYPE_SIGNAL, $base, [], $blockers);
    }

    /** @param array<string,mixed> $targets */
    private function targetReference(AuthorityEntity $target, GraphEdge $edge): array { return ['target_uuid' => $target->canonicalId, 'target_revision' => $target->revision, 'target_name' => $target->canonicalName, 'target_family' => $target->payload['family'] ?? null, 'edge_uuid' => $edge->edge_uuid, 'edge_revision' => $edge->revision, 'edge_state' => $edge->state->name]; }

    /** @param array<string,array{target:AuthorityEntity,record:array<string,mixed>}> $exact */
    private function evidenceTargets(array $exact): array { $out = []; foreach ($exact as $item) $out[] = ['target_uuid' => $item['target']->canonicalId, 'target_revision' => $item['target']->revision, 'target_name' => $item['target']->canonicalName, 'target_family' => $item['target']->payload['family'] ?? null]; usort($out, static fn (array $a, array $b): int => strcmp($a['target_uuid'], $b['target_uuid'])); return $out; }

    /** @param array<string,mixed> $base @param list<string> $blockers @param list<string> $warnings @param array<string,mixed> $extra */
    private function result(string $status, array $base, array $blockers = [], array $warnings = [], array $extra = []): array { $result = array_merge($base, ['status' => $status, 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))], $extra); $result['candidate_fingerprint'] = $this->fingerprint($result); return $result; }

    /** @param array<string,mixed> $options @return array{items:list<AuthorityEntity>,limit:int,after:?string,next_cursor:?string} */
    private function sourceSnapshot(array $options): array
    {
        $all = [];
        foreach (self::SOURCE_TYPES as $type) {
            try { foreach ($this->readAuthorityType($type, true) as $entity) if ($entity instanceof AuthorityEntity) $all[] = $entity; } catch (\Throwable) { return ['items' => [], 'limit' => 0, 'after' => null, 'next_cursor' => null]; }
        }
        $order = array_flip(self::SOURCE_TYPES);
        usort($all, static fn (AuthorityEntity $a, AuthorityEntity $b): int => ($order[$a->entityType] <=> $order[$b->entityType]) ?: strcmp($a->canonicalId, $b->canonicalId));
        $after = is_string($options['after'] ?? null) && trim((string) $options['after']) !== '' ? trim((string) $options['after']) : null;
        if ($after !== null) $all = array_values(array_filter($all, fn (AuthorityEntity $entity): bool => $this->sourceCursor($entity) > $after));
        $limit = max(1, min(500, (int) ($options['limit'] ?? 500)));
        $page = array_slice($all, 0, $limit);
        $next = count($all) > $limit && $page !== [] ? $this->sourceCursor($page[count($page) - 1]) : null;
        return ['items' => $page, 'limit' => $limit, 'after' => $after, 'next_cursor' => $next];
    }

    private function sourceCursor(AuthorityEntity $entity): string { return $entity->entityType . '|' . $entity->canonicalId; }
    /** @return list<AuthorityEntity> */
    private function readAuthorityType(string $type, bool $includeRetired): array
    {
        $cursorInventory = $this->cursorInventory instanceof CursorAuthorityInventoryReader
            ? $this->cursorInventory
            : ($this->authority instanceof CursorAuthorityInventoryReader ? $this->authority : null);
        if (!$cursorInventory instanceof CursorAuthorityInventoryReader) return $this->authority->listByType($type, $includeRetired);
        $items = []; $after = null;
        for ($page = 0; $page < 10000; $page++) {
            $result = $cursorInventory->pageByType($type, 200, $after, $includeRetired);
            foreach ((array) ($result['items'] ?? []) as $entity) if ($entity instanceof AuthorityEntity) $items[] = $entity;
            $next = $result['next_cursor'] ?? null;
            if ($next === null || !is_string($next) || $next === $after) return $items;
            $after = $next;
        }
        throw new \RuntimeException('AUTHORITY_AUDIT_PAGE_BOUND_EXCEEDED');
    }
    /** @return array{items:list<GraphEdge>,next_cursor:null} */
    private function readClassifiedEdges(AuthorityEntity $source): array
    {
        $items = [];
        $after = 0;
        $nextCursor = null;
        for ($page = 0; $page < 100; $page++) {
            $result = $this->graph->findOutgoing(new NodeReference($source->entityType, $source->canonicalId), 'classified_as', $after, 200, true, 'classification');
            foreach ((array) ($result['items'] ?? []) as $edge) $items[] = $edge;
            $next = $result['next_cursor'] ?? null;
            if (!is_int($next) || $next <= $after) { $nextCursor = null; break; }
            $after = $next;
            $nextCursor = $next;
        }
        if ($nextCursor !== null) throw new \RuntimeException('GRAPH_AUDIT_PAGE_BOUND_EXCEEDED');
        return ['items' => $items, 'next_cursor' => null];
    }
    private function normalize(string $value): string { $value = trim(mb_strtolower($value)); $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value; return preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value; }
    /** @param list<string> $hints @param array<string,AuthorityEntity> $canonical */
    private function matchingHints(AuthorityEntity $source, array $hints, array $canonical): bool { foreach ($hints as $hint) { $needle = $this->normalize((string) $hint); foreach ($canonical as $target) if ($needle !== '' && str_contains($needle, $this->normalize($target->canonicalName))) return true; } return false; }
    /** @param mixed $ids @return list<string> */
    private function safeIds(mixed $ids): array { $ids = is_array($ids) ? $ids : []; return array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => UuidCodec::isValid($id))); }
    /** @param mixed $value */
    private function fingerprint(mixed $value): string { return hash('sha256', $this->stableJson($value)); }
    private function stableJson(mixed $value): string { if (is_array($value)) { if (array_is_list($value)) return '[' . implode(',', array_map(fn (mixed $item): string => $this->stableJson($item), $value)) . ']'; ksort($value); $parts = []; foreach ($value as $key => $item) $parts[] = $this->stableJson((string) $key) . ':' . $this->stableJson($item); return '{' . implode(',', $parts) . '}'; } return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
