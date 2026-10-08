<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Audit;

use NHK\Core\Contracts\Audit\DuplicateAuditPageReader;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only, bounded page reader for the system-wide duplicate audit.
 *
 * The reader deliberately projects only the fields consumed by the existing
 * owner rules. It never hydrates through a mutable service and never falls
 * back to an unbounded repository list() call.
 */
final class WpdbDuplicateAuditPageReader implements DuplicateAuditPageReader
{
    public const MAX_PAGE_SIZE = 200;

    /** @var list<string> */
    private const OWNERS = [
        'Authority', 'Knowledge', 'Source', 'Evidence', 'Graph', 'Article',
        'Media', 'MediaAsset', 'MediaUsage', 'Video',
    ];

    private bool $includeRetired = true;

    public function __construct(private object $database, private string $owner)
    {
        if (!in_array($owner, self::OWNERS, true)) {
            throw new \InvalidArgumentException('AUDIT_OWNER_UNSUPPORTED');
        }
    }

    public function setIncludeRetired(bool $includeRetired): void
    {
        $this->includeRetired = $includeRetired;
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string,diagnostics:list<array<string,mixed>>} */
    public function page(?string $after, int $limit): array
    {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $afterId = $this->cursor($after);
        [$sql, $args] = $this->query($afterId, $limit + 1);
        $rows = $this->database->get_results($this->database->prepare($sql, ...$args), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows)) throw new \RuntimeException('AUDIT_READER_UNAVAILABLE');

        $hasMore = count($rows) > $limit;
        $pageRows = array_slice($rows, 0, $limit);
        $articleBindings = $this->owner === 'Article' ? $this->articleBindings($pageRows) : [];
        $items = [];
        $diagnostics = [];
        foreach ($pageRows as $row) {
            if (!is_array($row)) continue;
            try {
                if ($this->owner === 'Article') {
                    $endpointKey = (string) ($row['article_endpoint_key'] ?? '');
                    $row['_article_bindings'] = $articleBindings[$endpointKey] ?? [];
                }
                $item = $this->project($row);
                if ($item !== null) $items[] = $item;
            } catch (\Throwable $error) {
                $diagnostics[] = [
                    'code' => 'AUDIT_ROW_PROJECTION_FAILED',
                    'owner' => $this->owner,
                    'source_id' => (string) ($row['id'] ?? ''),
                    'error' => get_class($error),
                ];
            }
        }

        // Advance by the database identity, including malformed rows. This
        // prevents an invalid row from making a bounded reader repeat forever.
        $next = $hasMore && $pageRows !== []
            ? (string) ((int) ($pageRows[count($pageRows) - 1]['_audit_id'] ?? 0))
            : null;
        return ['items' => $items, 'next_cursor' => $next, 'diagnostics' => $diagnostics];
    }

    /** @return array{0:string,1:list<int|string>} */
    private function query(int $after, int $limit): array
    {
        $p = (string) ($this->database->prefix ?? '');
        $state = $this->includeRetired ? '' : ' AND state=1';
        $args = [$after, $limit];

        return match ($this->owner) {
            'Authority' => [
                "SELECT id AS _audit_id,canonical_uuid,entity_type,stable_key,canonical_name,payload,state,revision FROM {$p}nhk_entities WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
            'Knowledge' => [
                "SELECT id AS _audit_id,canonical_uuid,stable_key,claim_text,claim_type,provenance_json,state,revision FROM {$p}nhk_knowledge_claims WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
            'Source' => [
                "SELECT id AS _audit_id,canonical_uuid,stable_key,title,source_type,locator,metadata_json,state,revision FROM {$p}nhk_sources WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
            'Evidence' => [
                "SELECT id AS _audit_id,evidence_uuid,claim_uuid,source_uuid,relation_type,excerpt,locator,metadata_json,state,revision FROM {$p}nhk_evidence WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
            'Graph' => [
                "SELECT e.id AS _audit_id,HEX(e.edge_uuid) AS edge_uuid,s.endpoint_type AS source_type,s.endpoint_key AS source_id,p.predicate_key AS predicate,t.endpoint_type AS target_type,t.endpoint_key AS target_id,c.scope_code AS scope,c.scope_subject_type,c.scope_subject_id,c.provenance_class,e.state,e.revision FROM {$p}nhk_graph_edges e INNER JOIN {$p}nhk_graph_nodes s ON s.id=e.source_node_id INNER JOIN {$p}nhk_graph_nodes t ON t.id=e.target_node_id INNER JOIN {$p}nhk_graph_predicates p ON p.id=e.predicate_id LEFT JOIN {$p}nhk_graph_relation_context c ON c.edge_uuid=e.edge_uuid WHERE e.id>%d" . ($this->includeRetired ? '' : ' AND e.state=1') . " ORDER BY e.id ASC LIMIT %d",
                $args,
            ],
            'Article' => $this->articleQuery($p, $after, $limit),
            'Media' => [
                "SELECT id AS _audit_id,canonical_uuid,stable_key,canonical_name,provenance_json,state,revision,readiness FROM {$p}nhk_media WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
            'MediaAsset' => [
                "SELECT a.id AS _audit_id,a.asset_uuid,a.asset_kind,a.storage_key,HEX(a.checksum) AS checksum,a.width,a.height,m.canonical_uuid AS media_uuid FROM {$p}nhk_media_assets a INNER JOIN {$p}nhk_media m ON m.id=a.media_id WHERE a.id>%d ORDER BY a.id ASC LIMIT %d",
                $args,
            ],
            'MediaUsage' => [
                "SELECT u.id AS _audit_id,u.usage_uuid,u.endpoint_type,u.endpoint_key,u.usage_role,u.placement_key,u.active_slot,u.revision,m.canonical_uuid AS media_uuid FROM {$p}nhk_media_usages u INNER JOIN {$p}nhk_media m ON m.id=u.media_id WHERE u.id>%d" . ($this->includeRetired ? '' : " AND (u.active_slot IS NULL OR u.active_slot<> 'retired')") . " ORDER BY u.id ASC LIMIT %d",
                $args,
            ],
            'Video' => [
                "SELECT id AS _audit_id,canonical_uuid,platform,external_video_id,canonical_url,metadata_json,state,revision FROM {$p}nhk_videos WHERE id>%d{$state} ORDER BY id ASC LIMIT %d",
                $args,
            ],
        };
    }

    /** @return array{0:string,1:list<int|string>} */
    private function articleQuery(string $prefix, int $after, int $limit): array
    {
        $retired = $this->includeRetired ? '' : " AND post_status NOT IN ('trash','auto-draft')";
        $blogId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        return [
            "SELECT ID AS _audit_id,ID,post_status,post_title,post_name,post_modified_gmt,(SELECT pm.meta_value FROM {$prefix}postmeta pm WHERE pm.post_id={$prefix}posts.ID AND pm.meta_key='_nhk_editorial_intent' ORDER BY pm.meta_id DESC LIMIT 1) AS editorial_intent,CONCAT(%s,':',ID) AS article_endpoint_key FROM {$prefix}posts WHERE ID>%d AND post_type='post'{$retired} ORDER BY ID ASC LIMIT %d",
            [(string) $blogId, $after, $limit],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,list<array<string,mixed>>> */
    private function articleBindings(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string) ($row['article_endpoint_key'] ?? ''));
            if ($key !== '') $keys[] = $key;
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) return [];
        $p = (string) ($this->database->prefix ?? '');
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = "SELECT e.id AS _binding_id,HEX(e.edge_uuid) AS edge_uuid,s.endpoint_key AS source_id,t.endpoint_type AS target_type,t.endpoint_key AS target_id,e.state,e.revision,c.scope_code,c.scope_subject_type,c.scope_subject_id,c.provenance_class,c.state AS context_state,c.revision AS context_revision FROM {$p}nhk_graph_edges e INNER JOIN {$p}nhk_graph_nodes s ON s.id=e.source_node_id INNER JOIN {$p}nhk_graph_nodes t ON t.id=e.target_node_id INNER JOIN {$p}nhk_graph_predicates p ON p.id=e.predicate_id LEFT JOIN {$p}nhk_graph_relation_context c ON c.edge_uuid=e.edge_uuid WHERE s.endpoint_type=%s AND s.endpoint_key IN ({$placeholders}) AND p.predicate_key=%s" . ($this->includeRetired ? '' : ' AND e.state=1') . " ORDER BY e.id ASC";
        $args = array_merge(['wp_post'], $keys, ['about']);
        $result = $this->database->get_results($this->database->prepare($sql, ...$args), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($result)) throw new \RuntimeException('AUDIT_READER_UNAVAILABLE');
        $mapped = [];
        foreach ($result as $binding) {
            if (!is_array($binding)) continue;
            $source = trim((string) ($binding['source_id'] ?? ''));
            if ($source === '') continue;
            $mapped[$source][] = $binding;
        }
        return $mapped;
    }

    /** @return array<string,mixed>|null */
    private function project(array $row): ?array
    {
        return match ($this->owner) {
            'Authority' => $this->authority($row),
            'Knowledge' => $this->knowledge($row),
            'Source' => $this->source($row),
            'Evidence' => $this->evidence($row),
            'Graph' => $this->graph($row),
            'Article' => $this->article($row),
            'Media' => $this->media($row),
            'MediaAsset' => $this->asset($row),
            'MediaUsage' => $this->usage($row),
            'Video' => $this->video($row),
        };
    }

    /** @return array<string,mixed>|null */
    private function authority(array $row): ?array
    {
        $payload = $this->json($row['payload'] ?? '');
        $id = $this->uuid($row['canonical_uuid'] ?? null);
        return $id === null ? null : [
            'canonical_id' => $id, 'entity_type' => (string) ($row['entity_type'] ?? ''),
            'stable_key' => (string) ($row['stable_key'] ?? ''), 'canonical_name' => (string) ($row['canonical_name'] ?? ''),
            'aliases' => is_array($payload['aliases'] ?? null) ? $payload['aliases'] : [],
            'family' => (string) ($payload['family'] ?? $payload['entity_family'] ?? ''), 'payload' => $payload,
            'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED', 'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function knowledge(array $row): ?array
    {
        $id = $this->uuid($row['canonical_uuid'] ?? null);
        if ($id === null) return null;
        $provenance = $this->json($row['provenance_json'] ?? '');
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        return [
            'canonical_id' => $id, 'stable_key' => (string) ($row['stable_key'] ?? ''),
            'claim_text' => (string) ($row['claim_text'] ?? ''), 'claim_type' => (string) ($row['claim_type'] ?? ''),
            'subject_id' => (string) ($metadata['subject_id'] ?? $provenance['subject_id'] ?? ''),
            'facet' => (string) ($metadata['facet'] ?? $provenance['facet'] ?? ''),
            'scope' => (string) ($metadata['scope'] ?? $provenance['scope'] ?? ''),
            'qualification' => (string) ($metadata['qualification'] ?? $metadata['qualifier'] ?? ''),
            'provenance' => $provenance, 'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED',
            'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function source(array $row): ?array
    {
        $id = $this->uuid($row['canonical_uuid'] ?? null);
        if ($id === null) return null;
        $metadata = $this->json($row['metadata_json'] ?? '');
        return [
            'canonical_id' => $id, 'stable_key' => (string) ($row['stable_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''), 'source_type' => (string) ($row['source_type'] ?? ''),
            'locator' => $row['locator'] === null ? null : (string) $row['locator'], 'metadata' => $metadata,
            'external_identity' => $metadata['external_identity'] ?? $metadata['external_id'] ?? null,
            'version' => $metadata['version'] ?? $metadata['snapshot'] ?? null,
            'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED', 'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function evidence(array $row): ?array
    {
        $id = $this->uuid($row['evidence_uuid'] ?? null);
        $claim = $this->uuid($row['claim_uuid'] ?? null);
        $source = $this->uuid($row['source_uuid'] ?? null);
        if ($id === null || $claim === null || $source === null) return null;
        $metadata = $this->json($row['metadata_json'] ?? '');
        return [
            'canonical_id' => $id, 'claim_id' => $claim, 'source_id' => $source,
            'relation' => (string) ($row['relation_type'] ?? ''), 'excerpt' => (string) ($row['excerpt'] ?? ''),
            'support_unit' => (string) ($metadata['support_unit'] ?? $metadata['supportUnit'] ?? ''),
            'locator' => $row['locator'] === null ? null : (string) $row['locator'], 'metadata' => $metadata,
            'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED', 'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function graph(array $row): ?array
    {
        $id = $this->uuid($row['edge_uuid'] ?? null);
        if ($id === null) return null;
        return [
            'canonical_id' => $id, 'edge_id' => $id,
            'source' => ['type' => (string) ($row['source_type'] ?? ''), 'id' => (string) ($row['source_id'] ?? '')],
            'source_type' => (string) ($row['source_type'] ?? ''), 'source_id' => (string) ($row['source_id'] ?? ''),
            'predicate' => (string) ($row['predicate'] ?? ''),
            'target' => ['type' => (string) ($row['target_type'] ?? ''), 'id' => (string) ($row['target_id'] ?? '')],
            'target_type' => (string) ($row['target_type'] ?? ''), 'target_id' => (string) ($row['target_id'] ?? ''),
            'scope' => (string) ($row['scope'] ?? ''), 'context' => [
                'scope_subject_type' => (string) ($row['scope_subject_type'] ?? ''),
                'scope_subject_id' => (string) ($row['scope_subject_id'] ?? ''),
                'provenance_class' => (string) ($row['provenance_class'] ?? ''),
            ],
            'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED', 'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function article(array $row): array
    {
        $status = (string) ($row['post_status'] ?? '');
        $bindings = [];
        foreach ((array) ($row['_article_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            $edgeId = $this->uuid($binding['edge_uuid'] ?? null);
            $bindings[] = [
                'edge_id' => $edgeId ?? (string) ($binding['edge_uuid'] ?? ''),
                'target_type' => (string) ($binding['target_type'] ?? ''),
                'target_id' => (string) ($binding['target_id'] ?? ''),
                'scope' => (string) ($binding['scope_code'] ?? ''),
                'context' => [
                    'scope_subject_type' => (string) ($binding['scope_subject_type'] ?? ''),
                    'scope_subject_id' => (string) ($binding['scope_subject_id'] ?? ''),
                    'provenance_class' => (string) ($binding['provenance_class'] ?? ''),
                    'revision' => (int) ($binding['context_revision'] ?? 0),
                ],
                'state' => (int) ($binding['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED',
                'context_state' => ($binding['context_state'] ?? null) === null || (int) $binding['context_state'] === 1 ? 'ACTIVE' : 'RETIRED',
                'revision' => (int) ($binding['revision'] ?? 0),
            ];
        }
        $active = array_values(array_filter($bindings, static fn (array $binding): bool => $binding['state'] === 'ACTIVE' && $binding['context_state'] === 'ACTIVE'));
        $subjectIds = array_values(array_unique(array_filter(array_map(static fn (array $binding): string => trim((string) ($binding['target_id'] ?? '')), $active))));
        $intent = trim((string) ($row['editorial_intent'] ?? ''));
        $scopes = array_values(array_unique(array_filter(array_map(static fn (array $binding): string => trim((string) ($binding['scope'] ?? '')), $active))));
        $missing = [];
        $classification = 'AUDITABLE';
        $reason = '';
        if (count($subjectIds) > 1) {
            $classification = 'LEGACY_UNRESOLVED';
            $reason = 'AMBIGUOUS_ACTIVE_SUBJECT';
            $missing[] = 'canonical_subject';
        } elseif ($subjectIds === []) {
            $classification = 'LEGACY_UNRESOLVED';
            $reason = $bindings === [] ? 'NO_CANONICAL_SUBJECT_BINDING' : 'NO_ACTIVE_CANONICAL_SUBJECT';
            $missing[] = 'canonical_subject';
        } else {
            if ($intent === '') $missing[] = 'editorial_intent';
            if (count($scopes) !== 1) $missing[] = 'scope';
            // Article continuation lineage is not an Article or Graph field in
            // the active contracts. Never infer it from title, body, Capture
            // reachability, or an Evidence/Source relationship.
            $missing[] = 'lineage';
            if ($missing !== []) {
                $classification = 'MODEL_GAP';
                $reason = 'ARTICLE_SEMANTIC_IDENTITY_NOT_FULLY_PERSISTED';
            }
        }
        $resolvedSubjectIds = count($subjectIds) === 1 ? $subjectIds : [];
        $scope = count($scopes) === 1 ? $scopes[0] : '';
        return [
            'canonical_id' => (string) ((int) ($row['ID'] ?? 0)), 'post_id' => (int) ($row['ID'] ?? 0),
            'post_status' => $status, 'state' => $status === 'trash' ? 'RETIRED' : strtoupper($status),
            'title' => (string) ($row['post_title'] ?? ''), 'topic' => (string) ($row['post_title'] ?? ''),
            'subject_ids' => $resolvedSubjectIds, 'canonical_subject_bindings' => $bindings, 'intent' => $intent, 'content_kind' => '',
            'semantic_identity_available' => $classification === 'AUDITABLE', 'identity_classification' => $classification,
            'identity_reason' => $reason, 'missing_identity_fields' => array_values(array_unique($missing)),
            'scope' => $scope, 'continuation_lineage' => [], 'revision' => $this->articleRevision($row),
        ];
    }

    /** @return array<string,mixed>|null */
    private function media(array $row): ?array
    {
        $id = $this->uuid($row['canonical_uuid'] ?? null);
        if ($id === null) return null;
        $provenance = $this->json($row['provenance_json'] ?? '');
        return [
            'canonical_id' => $id, 'stable_key' => (string) ($row['stable_key'] ?? ''),
            'canonical_name' => (string) ($row['canonical_name'] ?? ''), 'provenance' => $provenance,
            'canonical_subject_id' => (string) ($provenance['canonical_subject_id'] ?? $provenance['subject_id'] ?? ''),
            'provenance_key' => (string) ($provenance['provenance_key'] ?? $provenance['origin'] ?? ''),
            'readiness' => (string) ($row['readiness'] ?? ''), 'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED',
            'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function asset(array $row): ?array
    {
        $id = $this->uuid($row['asset_uuid'] ?? null);
        $media = $this->uuid($row['media_uuid'] ?? null);
        return $id === null || $media === null ? null : [
            'canonical_id' => $id, 'asset_id' => $id, 'media_id' => $media,
            'kind' => (string) ($row['asset_kind'] ?? ''), 'storage_key' => (string) ($row['storage_key'] ?? ''),
            'checksum' => strtolower((string) ($row['checksum'] ?? '')), 'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function usage(array $row): ?array
    {
        $id = $this->uuid($row['usage_uuid'] ?? null);
        $media = $this->uuid($row['media_uuid'] ?? null);
        return $id === null || $media === null ? null : [
            'canonical_id' => $id, 'usage_id' => $id, 'media_id' => $media,
            'endpoint_type' => (string) ($row['endpoint_type'] ?? ''), 'endpoint_key' => (string) ($row['endpoint_key'] ?? ''),
            'role' => (string) ($row['usage_role'] ?? ''), 'placement_key' => (string) ($row['placement_key'] ?? ''),
            'state' => ($row['active_slot'] ?? null) === 'retired' ? 'RETIRED' : 'ACTIVE',
            'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function video(array $row): ?array
    {
        $id = $this->uuid($row['canonical_uuid'] ?? null);
        return $id === null ? null : [
            'canonical_id' => $id, 'platform' => (string) ($row['platform'] ?? ''),
            'external_video_id' => (string) ($row['external_video_id'] ?? ''), 'canonical_url' => (string) ($row['canonical_url'] ?? ''),
            'metadata' => $this->json($row['metadata_json'] ?? ''), 'state' => (int) ($row['state'] ?? 0) === 1 ? 'ACTIVE' : 'RETIRED',
            'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    private function cursor(?string $cursor): int
    {
        $value = trim((string) ($cursor ?? ''));
        if ($value === '') return 0;
        if (!ctype_digit($value)) throw new \InvalidArgumentException('AUDIT_CURSOR_INVALID');
        return (int) $value;
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        $value = trim((string) $value);
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(mixed $value): ?string
    {
        if (is_string($value) && strlen($value) === 16) {
            try { return UuidCodec::fromBinary($value); } catch (\Throwable) { return null; }
        }
        $hex = trim((string) $value);
        if (!preg_match('/^[0-9a-fA-F]{32}$/', $hex)) return null;
        try { return UuidCodec::fromBinary(hex2bin($hex)); } catch (\Throwable) { return null; }
    }

    private function articleRevision(array $row): int
    {
        $modified = (string) ($row['post_modified_gmt'] ?? '');
        return $modified === '' ? 0 : (int) sprintf('%u', crc32($modified));
    }
}
