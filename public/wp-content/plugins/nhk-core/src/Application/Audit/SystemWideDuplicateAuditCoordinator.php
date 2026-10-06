<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

use NHK\Core\Contracts\Audit\DuplicateAuditPageReader;

/**
 * Thin orchestration boundary for owner-specific, read-only duplicate audits.
 *
 * The coordinator does not compare records across owners and contains no
 * mutation or reconciliation execution path. Each owner has an explicit rule
 * method below so a semantic rule cannot accidentally become a global matcher.
 */
final class SystemWideDuplicateAuditCoordinator
{
    /** @var list<string> */
    public const OWNERS = ['Dictionary', 'Authority', 'Knowledge', 'Source', 'Evidence', 'Graph', 'Article', 'Media', 'MediaAsset', 'MediaUsage', 'Video'];

    /** @param array<string,mixed> $readers @param DictionaryDuplicateAuditAdapter|null $dictionaryAudit */
    public function __construct(private array $readers = [], private ?DictionaryDuplicateAuditAdapter $dictionaryAudit = null) {}

    /** @return array<string,mixed> */
    public function audit(int $limit = 100, array $cursors = [], bool $includeRetired = true): array
    {
        $limit = max(1, min(200, $limit));
        $owners = [];
        foreach (self::OWNERS as $owner) {
            $cursor = isset($cursors[$owner]) ? (string) $cursors[$owner] : null;
            $owners[$owner] = $owner === 'Dictionary' && $this->dictionaryAudit !== null
                ? $this->dictionary($limit, $cursor)
                : $this->auditOwner($owner, $limit, $cursor, $includeRetired);
            $owners[$owner]['cursor_page_provenance'] = [
                'cursor' => $cursor,
                'next_cursor' => $owners[$owner]['next_cursor'] ?? null,
                'limit' => $limit,
                'include_retired' => $includeRetired,
                'bounded' => true,
                'read_only' => true,
            ] + (array) ($owners[$owner]['cursor_page_provenance'] ?? []);
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
            'diagnostics' => ['owner_count' => count(self::OWNERS), 'cross_owner_matching' => false, 'automatic_apply' => false],
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
            $page = $this->page($reader, $cursor, $limit, $includeRetired);
            $items = array_values(array_filter((array) ($page['items'] ?? []), static fn (mixed $item): bool => is_array($item) || is_object($item)));
            $clusters = match ($owner) {
                'Authority' => $this->authority($items),
                'Knowledge' => $this->knowledge($items),
                'Source' => $this->source($items),
                'Evidence' => $this->evidence($items),
                'Graph' => $this->graph($items),
                'Article' => $this->article($items),
                'Media' => $this->media($items),
                'MediaAsset' => $this->mediaAsset($items),
                'MediaUsage' => $this->mediaUsage($items),
                'Video' => $this->video($items),
                default => [],
            };
            $next = isset($page['next_cursor']) && $page['next_cursor'] !== null ? (string) $page['next_cursor'] : null;
            return ['status' => $next === null ? 'COMPLETE' : 'PARTIAL', 'clusters' => $this->withPage($clusters, $cursor, $next, $items), 'next_cursor' => $next, 'rows_read' => count($items), 'diagnostics' => (array) ($page['diagnostics'] ?? [])];
        } catch (\Throwable $error) {
            return $this->blocked('AUDIT_READER_UNAVAILABLE', ['message' => $error->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function page(mixed $reader, ?string $cursor, int $limit, bool $includeRetired): array
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
        $groups = $this->group($items, function (array $row): string {
            $metadata = $this->array($row, ['provenance', 'metadata']);
            return implode('|', [$this->text($row, ['subject_id', 'canonical_subject_id'], $metadata), $this->text($row, ['facet'], $metadata), $this->text($row, ['scope'], $metadata), $this->text($row, ['claim_type']), $this->normalized($this->text($row, ['claim_text', 'text']))]);
        });
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) $clusters[] = $this->cluster('Knowledge', $key, $rows, 'DEFINITE_DUPLICATE', 'HIGH', ['same_subject_facet_scope_type_normalized_proposition'], [], ['deterministic proposition identity matches'], 'REVIEW_KNOWLEDGE_REUSE_AND_EVIDENCE;DO_NOT_AUTO_MERGE');
        $propositionGroups = $this->group($items, function (array $row): string { $metadata = $this->array($row, ['provenance', 'metadata']); return implode('|', [$this->text($row, ['subject_id', 'canonical_subject_id'], $metadata), $this->text($row, ['facet'], $metadata), $this->text($row, ['scope'], $metadata)]); });
        foreach ($propositionGroups as $key => $rows) {
            $propositions = [];
            foreach ($rows as $row) $propositions[$this->normalized($this->text($row, ['claim_text', 'text']))] = true;
            if ($key === '' || count($this->ids($rows)) < 2 || count($propositions) < 2) continue;
            $qualifiers = array_values(array_unique(array_map(fn (array $row): string => $this->normalized($this->text($row, ['qualification', 'qualifier'], $this->array($row, ['provenance', 'metadata']))), $rows)));
            $classification = count(array_filter($qualifiers, static fn (string $value): bool => $value !== '')) > 1 ? 'CONTEXTUAL_OR_SCOPED_VARIANT' : 'POSSIBLE_DUPLICATE';
            $clusters[] = $this->cluster('Knowledge', $key, $rows, $classification, 'MEDIUM', ['same_subject_facet_scope_with_nonidentical_proposition'], $classification === 'CONTEXTUAL_OR_SCOPED_VARIANT' ? ['qualification_differs'] : [], ['wording differs; equivalence is not proven by lexical similarity'], 'REVIEW_KNOWLEDGE_SEMANTIC_EQUIVALENCE;NO_MUTATION');
        }
        return $clusters;
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
        $groups = $this->group($items, function (array $row): string { $subjects = (array) ($row['subject_ids'] ?? [$row['subject_id'] ?? '']); sort($subjects, SORT_STRING); return implode('|', [implode(',', array_filter(array_map('strval', $subjects))), $this->normalized($this->text($row, ['intent', 'title_intent', 'topic']))]); });
        $clusters = [];
        foreach ($groups as $key => $rows) if ($key !== '' && count($this->ids($rows)) > 1) {
            $continuation = count(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['content_kind', 'article_kind'])), $rows))) > 1 && count(array_filter($rows, fn (array $row): bool => strtoupper($this->text($row, ['content_kind', 'article_kind'])) === 'CONTINUATION')) > 0;
            $clusters[] = $this->cluster('Article', $key, $rows, $continuation ? 'LEGITIMATE_DISTINCT' : 'HIGH_CONFIDENCE_EQUIVALENT', $continuation ? 'HIGH' : 'MEDIUM', ['same_subject_and_intent'], $continuation ? ['continuation_article'] : [], ['title or subject alone is insufficient; overlap reader supplied same subject and intent'], $continuation ? 'KEEP_DISTINCT_CONTINUATION;REVIEW_SCOPE' : 'REVIEW_ARTICLE_RESEARCH_OVERLAP;DO_NOT_HIDE_OR_DELETE');
        }
        return $clusters;
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
        return ['owner' => $owner, 'cluster_id' => strtolower($owner) . ':' . hash('sha256', $key . '|' . implode(',', $ids)), 'classification' => $classification, 'confidence_class' => $confidence, 'canonical_ids' => $ids, 'active_states' => array_values(array_unique(array_map(fn (array $row): string => strtoupper($this->text($row, ['state', 'status'], [], 'ACTIVE')), $rows))), 'revisions' => array_values(array_map(fn (array $row): array => ['canonical_id' => $this->id($row), 'revision' => (int) ($row['revision'] ?? 0)], $rows)), 'identity_signals' => $signals + ['key' => $key], 'conflicting_signals' => array_values(array_unique($conflicts)), 'reasons' => array_values(array_unique($reasons)), 'recommended_review_action' => $action, 'cursor_page_provenance' => []];
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
        return array_values(array_map(static fn (array $cluster): array => ['owner' => $cluster['owner'], 'cluster_id' => $cluster['cluster_id'], 'classification' => $cluster['classification'], 'canonical_ids' => $cluster['canonical_ids'], 'action' => $cluster['recommended_review_action'], 'apply' => false], array_filter($clusters, static fn (array $cluster): bool => in_array($cluster['classification'] ?? '', ['DEFINITE_DUPLICATE', 'HIGH_CONFIDENCE_EQUIVALENT', 'POSSIBLE_DUPLICATE', 'REVIEW_REQUIRED'], true))));
    }

    /** @param array<string,mixed> $owners */
    private function overallStatus(array $owners): string
    {
        $statuses = array_map(static fn (array $report): string => (string) ($report['status'] ?? 'BLOCKED'), $owners);
        if (in_array('BLOCKED', $statuses, true)) return 'BLOCKED';
        return in_array('PARTIAL', $statuses, true) ? 'PARTIAL' : 'COMPLETE';
    }

    /** @return array<string,mixed> */
    private function blocked(string $code, array $diagnostics = []): array { return ['status' => 'BLOCKED', 'clusters' => [], 'next_cursor' => null, 'rows_read' => 0, 'diagnostics' => array_merge(['code' => $code, 'read_only' => true], $diagnostics)]; }
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
