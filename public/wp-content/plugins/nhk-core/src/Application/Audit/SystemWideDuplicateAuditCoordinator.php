<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

use NHK\Core\Application\Knowledge\KnowledgeClaimIdentity;
use NHK\Core\Application\Knowledge\KnowledgeDuplicateReconciliationPlanner;
use NHK\Core\Contracts\Audit\DuplicateAuditPageReader;
use NHK\Core\Contracts\Video\VideoIdentityReader;

/**
 * Thin orchestration boundary for owner-specific, read-only duplicate audits.
 *
 * The coordinator does not compare records across owners and contains no
 * mutation or reconciliation execution path. Each owner has an explicit rule
 * method below so a semantic rule cannot accidentally become a global matcher.
 */
final class SystemWideDuplicateAuditCoordinator
{
    private const MAX_SCAN_ROWS = 5000;
    private const MAX_CARRY_ROWS = 128;
    private const MAX_CURSOR_LENGTH = 4096;
    private const CURSOR_STATE_TTL = 900;
    private const CURSOR_VERSION = 4;
    private const CURSOR_POLICY = 'system-wide-duplicate-audit:v4';
    /** @var array<string,array{carry:list<array<string,mixed>>,emitted:list<string>}> */
    private static array $cursorStates = [];
    /** @var list<string> */
    public const OWNERS = ['Dictionary', 'Authority', 'Knowledge', 'Source', 'Evidence', 'Graph', 'Article', 'Media', 'MediaAsset', 'MediaUsage', 'Video'];

    /** @param array<string,mixed> $readers @param DictionaryDuplicateAuditAdapter|null $dictionaryAudit */
    public function __construct(
        private array $readers = [],
        private ?DictionaryDuplicateAuditAdapter $dictionaryAudit = null,
        private ?string $cursorSigningSecret = null,
        private ?VideoIdentityReader $videoIdentityReader = null,
        private ?KnowledgeDuplicateReconciliationPlanner $knowledgeReconciliationPlanner = null,
    ) {}

    /** @return array<string,mixed> */
    public function audit(int $limit = 100, array $cursors = [], bool $includeRetired = true, ?string $owner = null): array
    {
        $limit = max(1, min(200, $limit));
        $ownerList = self::OWNERS;
        if ($owner !== null) {
            if (!in_array($owner, self::OWNERS, true)) return ['status' => 'BLOCKED', 'read_only' => true, 'mutated' => false, 'owners' => [], 'clusters' => [], 'reconciliation_candidates' => [], 'counts' => [], 'diagnostics' => ['code' => 'AUDIT_OWNER_UNSUPPORTED', 'owner_count' => 0]];
            $ownerList = [$owner];
        }
        $owners = [];
        foreach ($ownerList as $ownerName) {
            $cursor = isset($cursors[$ownerName]) ? (string) $cursors[$ownerName] : null;
            $owners[$ownerName] = $ownerName === 'Dictionary' && $this->dictionaryAudit !== null
                ? $this->dictionary($limit, $cursor)
                : $this->auditOwner($ownerName, $limit, $cursor, $includeRetired);
            $owners[$ownerName]['cursor_page_provenance'] = [
                'cursor' => $cursor,
                'next_cursor' => $owners[$ownerName]['next_cursor'] ?? null,
                'limit' => $limit,
                'include_retired' => $includeRetired,
                'scan_policy' => self::CURSOR_POLICY,
                'bounded' => true,
                'read_only' => true,
            ] + (array) ($owners[$ownerName]['cursor_page_provenance'] ?? []);
        }

        $clusters = [];
        foreach ($owners as $owner => $report) foreach ((array) ($report['clusters'] ?? []) as $cluster) {
            if (is_array($cluster)) $clusters[] = $cluster + ['owner' => $owner];
        }
        return [
            'status' => $this->overallStatus($owners),
            'read_only' => true,
            'mutated' => false,
            'owners' => $owners,
            'clusters' => $clusters,
            'reconciliation_candidates' => $this->reconciliationCandidates($clusters),
            'counts' => $this->counts($owners, $clusters),
            'diagnostics' => ['owner_count' => count($ownerList), 'cross_owner_matching' => false, 'automatic_apply' => false],
        ];
    }

    /** @return array<string,mixed> */
    private function dictionary(int $limit, ?string $cursor): array
    {
        return $this->dictionaryAudit?->run($limit, $cursor) ?? $this->blocked('DICTIONARY_AUDIT_UNAVAILABLE');
    }

    /** @return array<string,mixed> */
    private function auditOwner(string $owner, int $limit, ?string $cursor, bool $includeRetired): array
    {
        $reader = $this->readers[$owner] ?? null;
        if (!$reader instanceof DuplicateAuditPageReader && !is_callable($reader) && !(is_object($reader) && (method_exists($reader, 'page') || method_exists($reader, 'readPage')))) {
            return $this->blocked('AUDIT_MODEL_GAP');
        }
        try {
            $state = $this->decodeCursor($cursor, $owner, $includeRetired);
            if ($state['scanned'] >= self::MAX_SCAN_ROWS) return $this->partialBound($state['carry'], $cursor);
            if (method_exists($reader, 'setIncludeRetired')) $reader->setIncludeRetired($includeRetired);
            $pageLimit = min($limit, self::MAX_SCAN_ROWS - $state['scanned']);
            $page = $this->page($reader, $pageLimit, $state['after'], $includeRetired);
            $items = array_values(array_filter((array) ($page['items'] ?? []), static fn (mixed $item): bool => is_array($item) || is_object($item)));
            $combined = $this->dedupeRows(array_merge($state['carry'], array_map(fn (mixed $item): array => $this->row($item), $items)));
            $scanned = $state['scanned'] + count($items);
            if ($owner === 'Article') {
                $missing = $this->articleModelGap($combined);
                if ($missing !== []) return $this->blocked('AUDIT_MODEL_GAP', ['missing_identity_fields' => $missing]);
            }
            $ownerDiagnostics = [];
            $clusters = match ($owner) {
                'Authority' => $this->authority($combined),
                'Knowledge' => (function () use ($combined, &$ownerDiagnostics): array {
                    $result = $this->knowledge($combined);
                    $ownerDiagnostics = $result['diagnostics'];
                    return $result['clusters'];
                })(),
                'Source' => $this->source($combined),
                'Evidence' => $this->evidence($combined),
                'Graph' => $this->graph($combined),
                'Article' => $this->article($combined),
                'Media' => $this->media($combined),
                'MediaAsset' => $this->mediaAsset($combined),
                'MediaUsage' => $this->mediaUsage($combined),
                'Video' => $this->video($combined),
                default => [],
            };
            $clusters = $this->dedupeClusters($clusters);
            $clusters = array_values(array_filter($clusters, static fn (array $cluster): bool => !in_array((string) ($cluster['cluster_id'] ?? ''), $state['emitted'], true)));
            $emitted = array_values(array_unique(array_merge($state['emitted'], array_values(array_filter(array_map(static fn (array $cluster): string => (string) ($cluster['cluster_id'] ?? ''), $clusters))))));
            $next = isset($page['next_cursor']) && $page['next_cursor'] !== null ? (string) $page['next_cursor'] : null;
            $boundReached = $next !== null && $scanned >= self::MAX_SCAN_ROWS;
            $nextCursor = $boundReached ? null : ($next === null ? null : $this->encodeCursor($next, array_slice($combined, -self::MAX_CARRY_ROWS), $scanned, $owner, $includeRetired, $emitted));
            $diagnostics = array_merge((array) ($page['diagnostics'] ?? []), $ownerDiagnostics);
            if ($boundReached) $diagnostics[] = ['code' => 'AUDIT_MAX_SCAN_BOUND_REACHED', 'max_scan_rows' => self::MAX_SCAN_ROWS];
            $complete = $next === null;
            return ['status' => $complete ? 'COMPLETE' : 'PARTIAL', 'complete' => $complete, 'clusters' => $this->withPage($clusters, $cursor, $nextCursor, $items), 'next_cursor' => $nextCursor, 'rows_read' => count($items), 'diagnostics' => $diagnostics];
        } catch (\InvalidArgumentException $error) {
            if (str_starts_with($error->getMessage(), 'AUDIT_CURSOR_')) return $this->blocked('AUDIT_CURSOR_INVALID', ['reason' => 'AUDIT_CURSOR_INVALID']);
            return $this->blocked('AUDIT_READER_UNAVAILABLE');
        } catch (\Throwable $error) {
            return $this->blocked('AUDIT_READER_UNAVAILABLE', ['message' => $error->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function page(mixed $reader, int $limit, ?string $cursor, bool $includeRetired): array
    {
        if ($reader instanceof DuplicateAuditPageReader) return $reader->page($cursor, $limit);
        if (is_callable($reader)) {
            $page = $reader($cursor, $limit, $includeRetired);
            if (!is_array($page)) throw new \RuntimeException('AUDIT_PAGE_INVALID');
            return $page;
        }
        if (method_exists($reader, 'page')) return $reader->page($cursor, $limit, $includeRetired);
        return $reader->readPage($limit, $cursor, $includeRetired);
    }

    /** @return array{after:?string,carry:list<array<string,mixed>>,scanned:int,emitted:list<string>} */
    private function decodeCursor(?string $cursor, string $owner, bool $includeRetired): array
    {
        $raw = trim((string) ($cursor ?? ''));
        if ($raw === '') return ['after' => null, 'carry' => [], 'scanned' => 0, 'emitted' => []];
        if (strlen($raw) > self::MAX_CURSOR_LENGTH) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $encoded = strtr($raw, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes)) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        try { $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID'); }
        if (!is_array($decoded) || !is_array($decoded['payload'] ?? null) || !is_string($decoded['signature'] ?? null)) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $payload = $decoded['payload'];
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $canonical, $this->cursorSecret());
        if (!hash_equals($signature, $decoded['signature'])) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        if (($payload['v'] ?? null) !== self::CURSOR_VERSION || ($payload['policy'] ?? null) !== self::CURSOR_POLICY || ($payload['owner'] ?? null) !== $owner || !is_bool($payload['include_retired'] ?? null) || $payload['include_retired'] !== $includeRetired) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $after = $payload['after'] ?? null;
        if ($after !== null && (!is_string($after) || $after === '' || strlen($after) > 512)) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $scanned = $payload['scanned'] ?? null;
        if (!is_int($scanned) || $scanned < 0 || $scanned > self::MAX_SCAN_ROWS) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $stateKey = $payload['state_key'] ?? null;
        if (!is_string($stateKey) || !preg_match('/^[a-f0-9]{64}$/', $stateKey)) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        $state = $this->loadCursorState($stateKey);
        $carry = $state['carry'] ?? null;
        $emitted = $state['emitted'] ?? null;
        if (!is_array($carry) || count($carry) > self::MAX_CARRY_ROWS || array_filter($carry, static fn (mixed $row): bool => !is_array($row)) !== []) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        if (!is_array($emitted) || count($emitted) > self::MAX_SCAN_ROWS || array_filter($emitted, static fn (mixed $id): bool => !is_string($id) || $id === '') !== []) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        return ['after' => $after, 'carry' => array_values($carry), 'scanned' => $scanned, 'emitted' => array_values($emitted)];
    }

    /** @param list<array<string,mixed>> $carry */
    private function encodeCursor(string $after, array $carry, int $scanned, string $owner, bool $includeRetired, array $emitted = []): string
    {
        $carry = array_slice($carry, -self::MAX_CARRY_ROWS);
        $stateKey = $this->storeCursorState($carry, array_values(array_unique($emitted)));
        $payload = ['v' => self::CURSOR_VERSION, 'policy' => self::CURSOR_POLICY, 'owner' => $owner, 'include_retired' => $includeRetired, 'after' => $after, 'state_key' => $stateKey, 'scanned' => $scanned];
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $envelope = ['payload' => $payload, 'signature' => hash_hmac('sha256', $canonical, $this->cursorSecret())];
        $cursor = rtrim(strtr(base64_encode((string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        if (strlen($cursor) > self::MAX_CURSOR_LENGTH) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        return $cursor;
    }

    /** @param list<array<string,mixed>> $carry @param list<string> $emitted */
    private function storeCursorState(array $carry, array $emitted): string
    {
        $state = ['carry' => array_values($carry), 'emitted' => array_values(array_unique($emitted))];
        $stateKey = hash('sha256', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::$cursorStates[$stateKey] = $state;
        if (function_exists('wp_cache_set')) wp_cache_set($stateKey, $state, 'nhk_system_wide_duplicate_audit', self::CURSOR_STATE_TTL);
        if (function_exists('set_transient')) set_transient('nhk_swda_' . $stateKey, $state, self::CURSOR_STATE_TTL);
        return $stateKey;
    }

    /** @return array{carry:list<array<string,mixed>>,emitted:list<string>}|null */
    private function loadCursorState(string $stateKey): ?array
    {
        $state = self::$cursorStates[$stateKey] ?? null;
        if ($state === null && function_exists('wp_cache_get')) {
            $cached = wp_cache_get($stateKey, 'nhk_system_wide_duplicate_audit');
            $state = is_array($cached) ? $cached : null;
        }
        if ($state === null && function_exists('get_transient')) {
            $cached = get_transient('nhk_swda_' . $stateKey);
            $state = is_array($cached) ? $cached : null;
        }
        return is_array($state) && isset($state['carry'], $state['emitted']) ? $state : null;
    }

    private function cursorSecret(): string
    {
        $secret = trim((string) ($this->cursorSigningSecret ?? ''));
        if ($secret !== '') return $secret;
        if (function_exists('wp_salt')) {
            $secret = trim((string) wp_salt('auth'));
            if ($secret !== '') return $secret;
        }
        throw new \InvalidArgumentException('AUDIT_CURSOR_SIGNING_UNAVAILABLE');
    }

    /** @param list<array<string,mixed>> $rows */
    private function dedupeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = $this->id($row);
            $key = $id !== '' ? $id : hash('sha256', (string) json_encode($row));
            $out[$key] = $row;
        }
        return array_values($out);
    }

    /** @param list<array<string,mixed>> $clusters @return list<array<string,mixed>> */
    private function dedupeClusters(array $clusters): array
    {
        $unique = [];
        foreach ($clusters as $cluster) {
            $ids = array_values(array_map('strval', (array) ($cluster['canonical_ids'] ?? [])));
            sort($ids, SORT_STRING);
            $key = (string) ($cluster['owner'] ?? '') . ':' . implode(',', $ids);
            if ($ids === [] || isset($unique[$key])) continue;
            $unique[$key] = $cluster;
        }
        return array_values($unique);
    }

    /** @param list<array<string,mixed>> $carry @return array<string,mixed> */
    private function partialBound(array $carry, ?string $cursor): array
    {
        return ['status' => 'PARTIAL', 'complete' => false, 'clusters' => [], 'next_cursor' => null, 'rows_read' => 0, 'diagnostics' => ['code' => 'AUDIT_MAX_SCAN_BOUND_REACHED', 'max_scan_rows' => self::MAX_SCAN_ROWS, 'cursor' => $cursor]];
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function authority(array $items): array
    {
        $groups = $this->group($items, fn (array $row): string => $this->text($row, ['stable_key']));
        foreach ($groups as $key => $rows) if ($key === '') unset($groups[$key]);
        $clusters = $this->clusters('Authority', $groups, 'same_stable_key_structural_collision', 'REVIEW_REQUIRED', 'HIGH', 'REVIEW_AUTHORITY_IDENTITY;DO_NOT_MERGE');
        $names = $this->group($items, fn (array $row): string => strtolower($this->text($row, ['entity_type'])) . '|' . strtolower($this->text($row, ['family'], $this->array($row, ['payload']))) . '|' . $this->normalized($this->text($row, ['canonical_name', 'name'])));
        foreach ($names as $key => $rows) {
            if ($key === '|' || count($rows) < 2) continue;
            $ids = $this->ids($rows);
            if (count($ids) < 2) continue;
            $clusters[] = $this->cluster('Authority', $key, $rows, 'HIGH_CONFIDENCE_EQUIVALENT', 'HIGH', ['same_family_and_normalized_name'], [], ['same canonical name within family'], 'REVIEW_AUTHORITY_CANONICAL_NAME;DO_NOT_MERGE');
        }
        return $clusters;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function knowledge(array $items): array
    {
        $clusters = [];
        $resolved = [];
        $coverage = ['identity_resolved_rows' => 0, 'identity_unresolved_rows' => 0, 'identity_conflicting_rows' => 0, 'bounded_identity_review_samples' => []];
        foreach ($items as $row) {
            $identity = KnowledgeClaimIdentity::resolveAuditRow($row, $this->videoIdentityReader);
            if ($identity->status() !== 'RESOLVED') {
                $id = $this->id($row);
                $statusKey = strtolower($identity->status());
                $coverage['identity_' . ($statusKey === 'conflicting' ? 'conflicting' : 'unresolved') . '_rows']++;
                if (count($coverage['bounded_identity_review_samples']) < 16) {
                    $coverage['bounded_identity_review_samples'][] = $this->knowledgeIdentityDiagnostic($row, $identity);
                }
                continue;
            }
            $coverage['identity_resolved_rows']++;
            $resolved[$identity->fingerprint()][] = ['row' => $row, 'identity' => $identity];
        }
        foreach ($resolved as $key => $entries) {
            $rows = array_values(array_map(static fn (array $entry): array => $entry['row'], $entries));
            if (count($this->ids($rows)) > 1) $clusters[] = $this->cluster('Knowledge', $key, $rows, 'DEFINITE_DUPLICATE', 'HIGH', ['identity_status' => 'RESOLVED', 'identity_policy' => $entries[0]['identity']->policyVersion(), 'identity_fingerprint' => $key], [], ['canonical Knowledge identity matches'], 'REVIEW_KNOWLEDGE_REUSE_AND_EVIDENCE;DO_NOT_AUTO_MERGE');
        }
        $propositionGroups = [];
        foreach ($resolved as $entries) foreach ($entries as $entry) {
            $row = $entry['row'];
            $provenance = $this->array($row, ['provenance']);
            $metadata = $this->array($row, ['provenance', 'metadata']);
            $origin = strtoupper($this->text($row, ['origin'], $provenance));
            if ($origin === 'CAPTURE_VIDEO_SOURCE_PROVENANCE' || strtoupper($this->text($metadata, ['origin'])) === 'CAPTURE_VIDEO_SOURCE_PROVENANCE') continue;
            $packet = $entry['identity']->packet();
            unset($packet['proposition']);
            $propositionGroups[KnowledgeClaimIdentity::key($packet)][] = $row;
        }
        foreach ($propositionGroups as $key => $rows) {
            $propositions = [];
            foreach ($rows as $row) $propositions[$this->normalized($this->text($row, ['claim_text', 'text']))] = true;
            if ($key === '' || count($this->ids($rows)) < 2 || count($propositions) < 2) continue;
            $qualifiers = array_values(array_unique(array_map(fn (array $row): string => $this->normalized($this->text($row, ['qualification', 'qualifier'], $this->array($row, ['provenance', 'metadata']))), $rows)));
            $classification = count(array_filter($qualifiers, static fn (string $value): bool => $value !== '')) > 1 ? 'CONTEXTUAL_OR_SCOPED_VARIANT' : 'POSSIBLE_DUPLICATE';
            $clusters[] = $this->cluster('Knowledge', $key, $rows, $classification, 'MEDIUM', ['same_subject_facet_scope_with_nonidentical_proposition'], $classification === 'CONTEXTUAL_OR_SCOPED_VARIANT' ? ['qualification_differs'] : [], ['wording differs; equivalence is not proven by lexical similarity'], 'REVIEW_KNOWLEDGE_SEMANTIC_EQUIVALENCE;NO_MUTATION');
        }
        return ['clusters' => $clusters, 'diagnostics' => $coverage];
    }

    /** @param array<string,mixed> $row */
    private function knowledgeIdentityDiagnostic(array $row, \NHK\Core\Application\Knowledge\KnowledgeClaimIdentityResolution $identity): array
    {
        $provenance = $this->array($row, ['provenance']);
        $metadata = $this->array($provenance, ['metadata']);
        $sourceClass = strtoupper($this->text($row, ['source_class'], $metadata));
        $allowedSourceClasses = ['OBSERVED_FROM_MEDIA', 'EXPLICIT_USER_KNOWLEDGE', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH', 'SYSTEM_INFERENCE'];
        return [
            'canonical_id' => $this->id($row),
            'status' => $identity->status(),
            'reason_codes' => $identity->reasonCodes(),
            'missing_fields' => $identity->missingFields(),
            'identity_policy' => $identity->policyVersion(),
            'revision' => (int) ($row['revision'] ?? 0),
            'lifecycle_state' => strtoupper($this->text($row, ['state', 'status'], [], 'ACTIVE')),
            'source_class' => in_array($sourceClass, $allowedSourceClasses, true) ? $sourceClass : 'UNKNOWN',
            'coverage_impact' => 'DUPLICATE_GROUPING_EXCLUDED',
        ];
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function source(array $items): array
    {
        $groups = $this->group($items, fn (array $row): string => $this->normalized($this->text($row, ['locator', 'canonical_locator'])));
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) $clusters[] = $this->cluster('Source', $key, $rows, 'HIGH_CONFIDENCE_EQUIVALENT', 'HIGH', ['same_normalized_canonical_locator'], $this->versionConflicts($rows), ['same canonical locator'], 'REVIEW_SOURCE_VERSIONS_AND_PROVENANCE;DO_NOT_MERGE_VERSIONS');
        $stable = $this->group($items, fn (array $row): string => $this->text($row, ['stable_key']));
        foreach ($stable as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) $clusters[] = $this->cluster('Source', $key, $rows, 'DEFINITE_DUPLICATE', 'HIGH', ['same_stable_key'], [], ['stable key collision'], 'REVIEW_SOURCE_IDENTITY;DO_NOT_REKEY');
        return $clusters;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function evidence(array $items): array
    {
        $groups = $this->group($items, fn (array $row): string => implode('|', [$this->text($row, ['claim_id', 'claim_uuid']), $this->text($row, ['source_id', 'source_uuid']), strtolower($this->text($row, ['relation'])), $this->normalized($this->text($row, ['support_unit', 'excerpt'])), $this->normalized($this->text($row, ['locator']))]));
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) $clusters[] = $this->cluster('Evidence', $key, $rows, 'HIGH_CONFIDENCE_EQUIVALENT', 'HIGH', ['same_claim_source_relation_support_unit_locator'], [], ['claim+source alone is not used; support unit also matches'], 'REVIEW_EVIDENCE_PROVENANCE;PRESERVE_DISTINCT_CLAIM_SOURCE_LINKS');
        return $clusters;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function graph(array $items): array
    {
        $groups = $this->group($items, function (array $row): string {
            $source = $this->array($row, ['source']);
            $target = $this->array($row, ['target']);
            return implode('|', [$this->text($row, ['source_id'], ['source_id' => $source['id'] ?? '']), $this->text($row, ['source_type'], ['source_type' => $source['type'] ?? '']), $this->text($row, ['predicate']), $this->text($row, ['target_id'], ['target_id' => $target['id'] ?? '']), $this->text($row, ['target_type'], ['target_type' => $target['type'] ?? '']), $this->normalized($this->text($row, ['scope', 'context']))]);
        });
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) {
            $states = array_values(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['state'], [], 'ACTIVE')), $rows)));
            $mixed = count($states) > 1;
            $clusters[] = $this->cluster('Graph', $key, $rows, $mixed ? 'REVIEW_REQUIRED' : 'DEFINITE_DUPLICATE', $mixed ? 'MEDIUM' : 'HIGH', ['same_source_predicate_target_scope_context'], $mixed ? ['active_and_retired_history_present'] : [], ['active relation equivalents are duplicates; retired history is never silently collapsed'], 'REVIEW_GRAPH_RELATION_HISTORY_AND_CARDINALITY;DO_NOT_DELETE_HISTORY');
        }
        return $clusters;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function article(array $items): array
    {
        $groups = $this->group($items, function (array $row): string { $subjects = (array) ($row['subject_ids'] ?? [$row['subject_id'] ?? '']); sort($subjects, SORT_STRING); return implode('|', [implode(',', array_filter(array_map('strval', $subjects))), $this->normalized($this->text($row, ['intent', 'title_intent'])), $this->normalized($this->text($row, ['scope']))]); });
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) {
            $continuation = count(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['content_kind', 'article_kind'])), $rows))) > 1 && count(array_filter($rows, fn (array $row): bool => strtoupper($this->text($row, ['content_kind', 'article_kind'])) === 'CONTINUATION')) > 0;
            $clusters[] = $this->cluster('Article', $key, $rows, $continuation ? 'LEGITIMATE_DISTINCT' : 'HIGH_CONFIDENCE_EQUIVALENT', $continuation ? 'HIGH' : 'MEDIUM', ['same_subject_and_intent'], $continuation ? ['continuation_article'] : [], ['title or subject alone is insufficient; overlap reader supplied same subject and intent'], $continuation ? 'KEEP_DISTINCT_CONTINUATION;REVIEW_SCOPE' : 'REVIEW_ARTICLE_RESEARCH_OVERLAP;DO_NOT_HIDE_OR_DELETE');
        }
        return $clusters;
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function articleModelGap(array $rows): array
    {
        $missing = [];
        foreach ($rows as $row) {
            if (($row['semantic_identity_available'] ?? false) !== true) $missing[] = 'semantic_identity';
            $subjects = $row['subject_ids'] ?? null;
            if (!is_array($subjects) || array_values(array_filter($subjects, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')) === []) $missing[] = 'canonical_subject';
            if ($this->text($row, ['intent', 'title_intent']) === '') $missing[] = 'editorial_intent';
            if ($this->text($row, ['scope']) === '') $missing[] = 'scope';
            if (!array_key_exists('continuation_lineage', $row) && !array_key_exists('lineage', $row)) $missing[] = 'lineage';
        }
        return array_values(array_unique($missing));
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function media(array $items): array
    {
        $stableGroups = $this->group($items, fn (array $row): string => $this->normalized($this->text($row, ['stable_key'])));
        $subjectGroups = $this->group($items, function (array $row): string {
            $provenance = $this->array($row, ['provenance']);
            return implode('|', [$this->text($row, ['canonical_subject_id', 'subject_id'], $provenance), $this->normalized($this->text($row, ['provenance_key', 'origin'], $provenance))]);
        });
        return array_merge(
            $this->clusters('Media', $stableGroups, 'same_media_stable_key', 'DEFINITE_DUPLICATE', 'HIGH', 'REVIEW_MEDIA_IDENTITY_AND_PROVENANCE'),
            $this->clusters('Media', $subjectGroups, 'same_canonical_subject_and_provenance', 'POSSIBLE_DUPLICATE', 'MEDIUM', 'REVIEW_MEDIA_IDENTITY_AND_PROVENANCE'),
        );
    }
    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function mediaAsset(array $items): array
    {
        $groups = $this->group($items, fn (array $row): string => implode('|', [$this->text($row, ['media_id', 'parent_media_id']), strtolower($this->text($row, ['checksum'])) , strtolower($this->text($row, ['kind', 'asset_kind'])), $this->text($row, ['width']), $this->text($row, ['height'])]));
        return $this->clusters('MediaAsset', $groups, 'same_parent_checksum_kind_dimensions', 'HIGH_CONFIDENCE_EQUIVALENT', 'HIGH', 'REVIEW_MEDIA_ASSET_BINARY_IDENTITY;PRESERVE_DERIVATIVE_BOUNDARY');
    }
    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function mediaUsage(array $items): array
    {
        $groups = $this->group($items, fn (array $row): string => implode('|', [$this->text($row, ['media_id']), $this->text($row, ['endpoint_type']), $this->text($row, ['endpoint_key']), $this->text($row, ['role']), $this->text($row, ['placement_key'])]));
        return $this->clusters('MediaUsage', $groups, 'same_media_endpoint_role_placement', 'DEFINITE_DUPLICATE', 'HIGH', 'REVIEW_MEDIA_USAGE_IDENTITY;PRESERVE_DISTINCT_CONTEXTUAL_USAGES');
    }
    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function video(array $items): array { return $this->simpleOwner('Video', $items, ['platform', 'external_video_id'], 'same_platform_external_video_id', 'REVIEW_VIDEO_EXTERNAL_IDENTITY;DO_NOT_DUPLICATE_BINARY_MEDIA'); }

    /** @param list<mixed> $items @param list<string> $keys @return list<array<string,mixed>> */
    private function simpleOwner(string $owner, array $items, array $keys, string $reason, string $action): array
    {
        $groups = $this->group($items, fn (array $row): string => implode('|', array_map(fn (string $key): string => $this->normalized($this->text($row, [$key])), $keys)));
        return $this->clusters($owner, $groups, $reason, 'HIGH_CONFIDENCE_EQUIVALENT', 'HIGH', $action);
    }

    /** @param list<mixed> $items @return array<string,list<array<string,mixed>>> */
    private function group(array $items, callable $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $row = $this->row($item);
            if ($row === []) continue;
            $groups[(string) $key($row)][] = $row;
        }
        return $groups;
    }

    /** @param array<string,list<array<string,mixed>>> $groups @return list<array<string,mixed>> */
    private function clusters(string $owner, array $groups, string $reason, string $classification, string $confidence, string $action): array
    {
        $out = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) $out[] = $this->cluster($owner, $key, $rows, $classification, $confidence, [$reason], $this->stateConflicts($rows), [$reason], $action);
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function cluster(string $owner, string $key, array $rows, string $classification, string $confidence, array $signals, array $conflicts, array $reasons, string $action): array
    {
        $ids = $this->ids($rows);
        sort($ids, SORT_STRING);
        return ['owner' => $owner, 'cluster_id' => strtolower($owner) . ':' . hash('sha256', $key), 'classification' => $classification, 'confidence_class' => $confidence, 'canonical_ids' => $ids, 'active_states' => array_values(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['state', 'status'], [], 'ACTIVE')), $rows))), 'revisions' => array_values(array_map(fn (array $row): array => ['canonical_id' => $this->id($row), 'revision' => (int) ($row['revision'] ?? 0)], $rows)), 'identity_signals' => $signals + ['key' => $key], 'conflicting_signals' => array_values(array_unique($conflicts)), 'reasons' => array_values(array_unique($reasons)), 'recommended_review_action' => $action, 'cursor_page_provenance' => []];
    }

    /** @param list<array<string,mixed>> $clusters @param list<mixed> $items @return list<array<string,mixed>> */
    private function withPage(array $clusters, ?string $cursor, ?string $next, array $items): array
    {
        foreach ($clusters as &$cluster) $cluster['cursor_page_provenance'] = ['cursor' => $cursor, 'next_cursor' => $next, 'rows_read' => count($items), 'read_only' => true];
        unset($cluster);
        return $clusters;
    }

    /** @param array<string,mixed> $owners @param list<array<string,mixed>> $clusters @return array<string,mixed> */
    private function counts(array $owners, array $clusters): array
    {
        $counts = ['clusters' => count($clusters), 'by_owner' => [], 'by_classification' => []];
        foreach ($owners as $owner => $report) $counts['by_owner'][$owner] = count((array) ($report['clusters'] ?? []));
        foreach ($clusters as $cluster) { $class = (string) ($cluster['classification'] ?? 'REVIEW_REQUIRED'); $counts['by_classification'][$class] = ($counts['by_classification'][$class] ?? 0) + 1; }
        return $counts;
    }

    /** @param list<array<string,mixed>> $clusters @return list<array<string,mixed>> */
    private function reconciliationCandidates(array $clusters): array
    {
        return array_values(array_map(function (array $cluster): array {
            $candidate = ['owner' => $cluster['owner'], 'cluster_id' => $cluster['cluster_id'], 'classification' => $cluster['classification'], 'canonical_ids' => $cluster['canonical_ids'], 'action' => $cluster['recommended_review_action'], 'apply' => false];
            if ($cluster['owner'] === 'Knowledge' && $this->knowledgeReconciliationPlanner !== null) {
                $signals = is_array($cluster['identity_signals'] ?? null) ? $cluster['identity_signals'] : [];
                $candidate['plan'] = $this->knowledgeReconciliationPlanner->plan([
                    'classification' => $cluster['classification'],
                    'canonical_ids' => $cluster['canonical_ids'],
                    'identity_policy' => $signals['identity_policy'] ?? '',
                    'identity_fingerprint' => $signals['identity_fingerprint'] ?? '',
                    'record_revisions' => array_reduce((array) ($cluster['revisions'] ?? []), static function (array $carry, array $row): array { $carry[(string) ($row['canonical_id'] ?? '')] = (int) ($row['revision'] ?? 0); return $carry; }, []),
                ]);
                $candidate['executable'] = ($candidate['plan']['status'] ?? '') === 'SAFE_TO_RECONCILE' && ($candidate['plan']['apply'] ?? true) === false;
            }
            return $candidate;
        }, array_filter($clusters, static fn (array $cluster): bool => in_array($cluster['classification'] ?? '', ['DEFINITE_DUPLICATE', 'HIGH_CONFIDENCE_EQUIVALENT', 'POSSIBLE_DUPLICATE', 'REVIEW_REQUIRED'], true))));
    }

    /** @param array<string,mixed> $owners */
    private function overallStatus(array $owners): string
    {
        $statuses = array_map(static fn (array $report): string => (string) ($report['status'] ?? 'BLOCKED'), $owners);
        if (in_array('BLOCKED', $statuses, true)) return 'BLOCKED';
        return in_array('PARTIAL', $statuses, true) ? 'PARTIAL' : 'COMPLETE';
    }

    /** @return array<string,mixed> */
    private function blocked(string $code, array $diagnostics = []): array { return ['status' => 'BLOCKED', 'complete' => false, 'clusters' => [], 'next_cursor' => null, 'rows_read' => 0, 'diagnostics' => array_merge(['code' => $code, 'reason' => $code, 'read_only' => true], $diagnostics)]; }
    /** @param list<array<string,mixed>> $rows */
    private function ids(array $rows): array { return array_values(array_unique(array_filter(array_map(fn (array $row): string => $this->id($row), $rows), static fn (string $id): bool => $id !== ''))); }
    /** @param array<string,mixed> $row */
    private function id(array $row): string { return trim((string) ($row['canonical_id'] ?? $row['canonical_uuid'] ?? $row['id'] ?? $row['edge_uuid'] ?? $row['usage_id'] ?? $row['asset_id'] ?? '')); }
    /** @param mixed $item @return array<string,mixed> */
    private function row(mixed $item): array
    {
        if (is_array($item)) return $item;
        if (!is_object($item)) return [];
        $raw = get_object_vars($item);
        if (is_a($item, 'NHK\\Core\\Domain\\Authority\\AuthorityEntity')) return ['canonical_id' => $item->canonicalId, 'entity_type' => $item->entityType, 'stable_key' => $item->stableKey, 'canonical_name' => $item->canonicalName, 'family' => $item->payload['family'] ?? $item->payload['entity_family'] ?? '', 'payload' => $item->payload, 'state' => $item->state->name, 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Knowledge\\KnowledgeClaim')) return ['canonical_id' => $item->canonicalId, 'stable_key' => $item->stableKey, 'claim_text' => $item->claimText, 'claim_type' => $item->claimType, 'provenance' => $item->provenance, 'state' => $item->active ? 'ACTIVE' : 'RETIRED', 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Knowledge\\Source')) return ['canonical_id' => $item->canonicalId, 'stable_key' => $item->stableKey, 'title' => $item->title, 'source_type' => $item->sourceType, 'locator' => $item->locator, 'metadata' => $item->metadata, 'state' => $item->active ? 'ACTIVE' : 'RETIRED', 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Knowledge\\Evidence')) return ['canonical_id' => $item->canonicalId, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'metadata' => $item->metadata, 'state' => $item->active ? 'ACTIVE' : 'RETIRED', 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Graph\\GraphEdge')) return ['canonical_id' => $item->edge_uuid, 'edge_uuid' => $item->edge_uuid, 'source' => ['type' => $item->source->reference->endpoint_type, 'id' => $item->source->reference->endpoint_key], 'predicate' => $item->predicate, 'target' => ['type' => $item->target->reference->endpoint_type, 'id' => $item->target->reference->endpoint_key], 'state' => $item->state->name, 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Media\\Media')) return ['canonical_id' => $item->canonicalId, 'stable_key' => $item->stableKey, 'canonical_name' => $item->canonicalName, 'provenance' => $item->provenance, 'state' => $item->active ? 'ACTIVE' : 'RETIRED', 'revision' => $item->revision];
        if (is_a($item, 'NHK\\Core\\Domain\\Media\\MediaAsset')) return ['canonical_id' => $item->assetId, 'asset_id' => $item->assetId, 'media_id' => $item->mediaId, 'kind' => $item->kind, 'storage_key' => $item->storageKey, 'checksum' => $item->checksum, 'mime_type' => $item->mimeType, 'width' => $item->width, 'height' => $item->height, 'revision' => 1];
        if (is_a($item, 'NHK\\Core\\Domain\\Media\\MediaUsage')) return ['canonical_id' => $item->usageId, 'usage_id' => $item->usageId, 'media_id' => $item->mediaId, 'endpoint_type' => $item->endpointType, 'endpoint_key' => $item->endpointKey, 'role' => $item->role, 'placement_key' => $item->placementKey, 'revision' => $item->revision, 'state' => $item->activeSlot === 'retired' ? 'RETIRED' : 'ACTIVE'];
        if (is_a($item, 'NHK\\Core\\Domain\\Video\\Video')) return ['canonical_id' => $item->canonicalId, 'platform' => $item->platform, 'external_video_id' => $item->externalVideoId, 'canonical_url' => $item->canonicalUrl, 'metadata' => $item->metadata, 'state' => $item->active ? 'ACTIVE' : 'RETIRED', 'revision' => $item->revision];
        return is_array($raw) ? $raw : [];
    }
    /** @param array<string,mixed> $row @param list<string> $keys @param array<string,mixed> $fallback */
    private function text(array $row, array $keys, array $fallback = [], string $default = ''): string { foreach ($keys as $key) { $value = $row[$key] ?? $fallback[$key] ?? null; if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value); } return $default; }
    /** @param array<string,mixed> $row @param list<string> $keys @return array<string,mixed> */
    private function array(array $row, array $keys): array { foreach ($keys as $key) if (is_array($row[$key] ?? null)) return $row[$key]; return []; }
    private function normalized(string $value): string { $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? ''; return trim(preg_replace('/\s+/u', ' ', $value) ?? ''); }
    /** @param list<array<string,mixed>> $rows */
    private function stateConflicts(array $rows): array { $states = array_values(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['state', 'status'], [], 'ACTIVE')), $rows))); return count($states) > 1 ? ['active_and_retired_history_present'] : []; }
    /** @param list<array<string,mixed>> $rows */
    private function versionConflicts(array $rows): array { $versions = array_values(array_unique(array_map(fn (array $row): string => $this->text($row, ['version', 'edition', 'revision']), $rows))); return count(array_filter($versions, static fn (string $value): bool => $value !== '')) > 1 ? ['source_versions_differ'] : []; }
}
