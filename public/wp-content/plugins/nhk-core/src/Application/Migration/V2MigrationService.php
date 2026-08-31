<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, InMemoryAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Knowledge\WpdbKnowledgeRepository;
use NHK\Core\Infrastructure\Media\WpdbMediaRepository;
use NHK\Core\Infrastructure\Migration\WpdbMigrationLedgerRepository;
use NHK\Core\Application\Graph\GraphService;

final class V2MigrationService
{
    private const MAPPER_VERSION = '6.2';
    private WpdbMigrationLedgerRepository $ledger;
    private WpdbAuthorityRepository $authority;
    private WpdbMediaRepository $media;
    private WpdbKnowledgeRepository $knowledge;
    private \NHK\Core\Infrastructure\Video\WpdbVideoRepository $videos;
    private EntityTypeRegistry $types;
    private GraphService $graph;

    public function __construct(private object $database)
    {
        $this->ledger = new WpdbMigrationLedgerRepository($database);
        $this->authority = new WpdbAuthorityRepository($database);
        $this->media = new WpdbMediaRepository($database);
        $this->knowledge = new WpdbKnowledgeRepository($database);
        $this->videos = new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($database);
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
        $endpoints = new EndpointTypeRegistry();
        CoreEndpointResolverRegistrar::register($endpoints, $this->types, $this->authority, $this->media, $this->videos, $this->knowledge);
        $this->graph = new GraphService(new WpdbGraphRepository($database), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
    }

    /** @param list<array<string,mixed>> $records */
    public function apply(array $records, int $batchNo = 1, int $limit = 100): array
    {
        $processed = 0; $migrated = 0; $skipped = 0; $conflict = 0;
        foreach ($records as $record) {
            if ($processed >= $limit) break;
            if (!is_array($record)) continue;
            $type = (string) ($record['type'] ?? '');
            $key = $this->sourceKey($record);
            if ($type === '' || $key === '') continue;
            $checksum = $this->checksum($record);
            $existing = $this->ledger->find($type, $key);
            if ($existing && (string) ($existing['source_checksum'] ?? '') === $checksum && in_array((string) $existing['status'], ['migrated', 'skipped', 'conflict'], true)) { $processed++; if ($existing['status'] === 'migrated') $migrated++; elseif ($existing['status'] === 'conflict') $conflict++; else $skipped++; continue; }
            $processed++;
            try {
                $result = $this->migrate($record, $batchNo);
                $this->ledger->record($type, $key, 'migrated', $result['reason'], $checksum, $result['target_type'] ?? null, $result['target_key'] ?? null, $result['target_id'] ?? null, $batchNo, $result['details'] ?? []);
                $migrated++;
            } catch (MigrationSkip $error) {
                $this->ledger->record($type, $key, $error->status, $error->reason, $checksum, null, null, null, $batchNo, ['message' => $error->getMessage()]);
                if ($error->status === 'conflict') $conflict++; else $skipped++;
            } catch (\Throwable $error) {
                // A storage or domain failure is recorded as a conflict and is
                // never reported as a successful migration.
                $this->ledger->record($type, $key, 'conflict', 'MIGRATION_FAILED', $checksum, null, null, null, $batchNo, ['message' => $error->getMessage(), 'exception' => get_class($error)]);
                $conflict++;
            }
        }
        return ['processed' => $processed, 'migrated' => $migrated, 'skipped' => $skipped, 'conflict' => $conflict, 'ledger' => $this->ledger->counts()];
    }

    private function migrate(array $record, int $batchNo): array
    {
        if (!empty($record['conflict'])) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Source record is marked conflicted.');
        return match ((string) $record['type']) {
            'category' => $this->category($record),
            'wp_post' => $this->post($record),
            'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => $this->authorityEntity($record),
            'media' => $this->mediaEntity($record),
            'video' => $this->videoEntity($record),
            'knowledge' => $this->knowledgeClaim($record),
            'relation' => $this->relation($record),
            default => throw new MigrationSkip('skipped', 'UNSUPPORTED_LEGACY_TYPE', 'No governed V3 target for source type.'),
        };
    }

    private function category(array $record): array
    {
        if ((string) ($record['taxonomy'] ?? '') !== 'category') throw new MigrationSkip('skipped', 'UNSUPPORTED_LEGACY_TYPE', 'Only native post categories are migrated.');
        $slug = sanitize_title((string) $record['slug']); $name = trim((string) $record['name']);
        if ($slug === '' || $name === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Category name or slug is empty.');
        $existing = term_exists($slug, 'category');
        if (!$existing) { $existing = wp_insert_term($name, 'category', ['slug' => $slug]); if (is_wp_error($existing)) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', $existing->get_error_message()); }
        return ['reason' => 'READY', 'target_type' => 'category', 'target_key' => $slug, 'target_id' => (string) (is_array($existing) ? $existing['term_id'] : $existing)];
    }

    private function post(array $record): array
    {
        $legacyType = (string) ($record['legacy_type'] ?? '');
        if (!in_array($legacyType, ['nhk_article', 'post', 'page'], true)) throw new MigrationSkip('skipped', 'DOMAIN_TARGETED', 'Legacy custom post type is represented by its canonical domain.');
        $sourceKey = $this->sourceKey($record);
        $existing = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'meta_key' => '_nhk_v2_source_key', 'meta_value' => $sourceKey, 'numberposts' => 1]);
        $postArgs = ['post_type' => $legacyType === 'page' ? 'page' : 'post', 'post_status' => (string) ($record['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft', 'post_title' => (string) ($record['post_title'] ?? ''), 'post_content' => (string) ($record['post_content'] ?? ''), 'post_excerpt' => (string) ($record['post_excerpt'] ?? ''), 'post_name' => sanitize_title((string) ($record['post_name'] ?? '')), 'post_date' => (string) ($record['post_date'] ?? ''), 'post_date_gmt' => (string) ($record['post_date_gmt'] ?? '')];
        if ($existing) {
            $post = $existing[0];
            if ($post->post_type !== $postArgs['post_type'] || $post->post_status !== $postArgs['post_status'] || $post->post_title !== $postArgs['post_title'] || !$this->contentEquivalent((string) $post->post_content, (string) $postArgs['post_content']) || $post->post_excerpt !== $postArgs['post_excerpt'] || $post->post_name !== $postArgs['post_name']) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Native WordPress post differs from the V2 source record.');
        }
        $id = $existing ? (int) $existing[0]->ID : wp_insert_post($postArgs, true);
        if (is_wp_error($id)) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', $id->get_error_message());
        if (!$existing) add_post_meta((int) $id, '_nhk_v2_source_key', $sourceKey, true);
        return ['reason' => 'READY', 'target_type' => 'wp_post', 'target_key' => (string) $id, 'target_id' => (string) $id];
    }

    private function authorityEntity(array $record): array
    {
        $type = (string) $record['type']; $id = (string) ($record['canonical_uuid'] ?? ''); $key = (string) ($record['stable_key'] ?? ''); $name = trim((string) ($record['canonical_name'] ?? ''));
        if (!isset($record['canonical_uuid']) || !preg_match('/^[0-9a-f-]{36}$/i', $id) || $key === '' || $name === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Authority identity is incomplete.');
        if (!$this->types->has($type)) throw new MigrationSkip('skipped', 'UNSUPPORTED_LEGACY_TYPE', 'Authority type is not registered.');
        $payload = $this->authorityPayload($type, is_array($record['metadata'] ?? null) ? $record['metadata'] : []);
        $state = $this->isArchived($record) ? AuthorityState::RETIRED : AuthorityState::ACTIVE;
        $existing = $this->authority->findByCanonicalId($id);
        if ($existing) {
            if ($existing->entityType !== $type || $existing->stableKey !== $key || $existing->canonicalName !== $name || $existing->payload !== $payload) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Canonical UUID maps to a changed V3 identity or payload.');
            if ($existing->state !== $state) {
                $this->authority->update(new AuthorityEntity($id, $type, $key, $name, $existing->schemaVersion, $payload, $state, $existing->revision), $existing->revision);
                return ['reason' => 'STATE_RECONCILED', 'target_type' => $type, 'target_key' => $key, 'target_id' => $id];
            }
            return ['reason' => 'IDEMPOTENT', 'target_type' => $type, 'target_key' => $key, 'target_id' => $id];
        }
        $definition = $this->types->get($type);
        $entity = new AuthorityEntity($id, $type, $key, $name, $definition->schemaVersion, $payload, $state, 1);
        $this->authority->create($entity);
        return ['reason' => 'READY', 'target_type' => $type, 'target_key' => $key, 'target_id' => $id];
    }

    private function mediaEntity(array $record): array
    {
        $id = (string) ($record['canonical_uuid'] ?? ''); $key = (string) ($record['stable_key'] ?? ''); $name = trim((string) ($record['canonical_name'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $key === '' || $name === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Media identity is incomplete.');
        $provenance = ['source' => 'v2', 'legacy_type' => 'media', 'metadata' => $record['metadata'] ?? []];
        $active = !$this->isArchived($record);
        $existing = $this->media->findByCanonicalId($id);
        if (!$existing) $this->media->create(new Media($id, $key, $name, 'draft', $provenance, $active));
        elseif ($existing->stableKey !== $key || $existing->canonicalName !== $name || $existing->provenance !== $provenance) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Media UUID maps to a changed V3 identity or provenance.');
        elseif ($existing->active !== $active) { $this->media->update(new Media($id, $key, $name, $existing->readiness, $provenance, $active, $existing->revision), $existing->revision); return ['reason' => 'STATE_RECONCILED', 'target_type' => 'media', 'target_key' => $key, 'target_id' => $id]; }
        return ['reason' => $existing ? 'IDEMPOTENT' : 'READY', 'target_type' => 'media', 'target_key' => $key, 'target_id' => $id];
    }

    private function knowledgeClaim(array $record): array
    {
        $id = (string) ($record['canonical_uuid'] ?? ''); $key = (string) ($record['stable_key'] ?? ''); $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $text = trim((string) ($metadata['one_sentence_definition'] ?? $record['canonical_name'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $key === '' || $text === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Knowledge identity or claim text is incomplete.');
        $claimType = $this->claimType($metadata); $provenance = ['source' => 'v2', 'metadata' => $metadata];
        $active = !$this->isArchived($record);
        $existing = $this->knowledge->findByCanonicalId($id);
        if (!$existing) $this->knowledge->create(new KnowledgeClaim($id, $key, $text, $claimType, $provenance, $active));
        elseif ($existing->stableKey !== $key || $existing->claimText !== $text || $existing->claimType !== $claimType || $existing->provenance !== $provenance) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Knowledge UUID maps to a changed V3 claim.');
        elseif ($existing->active !== $active) { $this->knowledge->update(new KnowledgeClaim($id, $key, $text, $claimType, $provenance, $active, $existing->revision), $existing->revision); return ['reason' => 'STATE_RECONCILED', 'target_type' => 'knowledge', 'target_key' => $key, 'target_id' => $id]; }
        return ['reason' => $existing ? 'IDEMPOTENT' : 'READY', 'target_type' => 'knowledge', 'target_key' => $key, 'target_id' => $id];
    }

    private function videoEntity(array $record): array
    {
        $id = (string) ($record['canonical_uuid'] ?? '');
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $url = trim((string) ($metadata['canonical_url'] ?? $metadata['url'] ?? $metadata['source_url'] ?? ''));
        $platform = strtolower(trim((string) ($metadata['platform'] ?? 'youtube')));
        $externalId = trim((string) ($metadata['external_video_id'] ?? $metadata['video_id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Video identity is incomplete.');
        if ($url === '' && $externalId !== '' && $platform === 'youtube') $url = 'https://www.youtube.com/watch?v=' . $externalId;
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || $platform !== 'youtube') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Video has no supported external reference.');
        if ($externalId === '') {
            try { $externalId = Video::fromUrl($url)->externalVideoId; } catch (\Throwable) { throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Video URL has no supported external identifier.'); }
        }
        $existing = $this->videos->findByCanonicalId($id);
        if ($existing) {
            if ($existing->platform !== $platform || $existing->externalVideoId !== $externalId || $existing->canonicalUrl !== $url) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Video UUID maps to a different external reference.');
            if ($existing->active !== !$this->isArchived($record)) { $this->videos->update(new Video($id, $platform, $externalId, $url, $existing->title, $existing->metadata, $existing->thumbnailMediaId, !$this->isArchived($record), $existing->revision), $existing->revision); return ['reason' => 'STATE_RECONCILED', 'target_type' => 'video', 'target_key' => $id, 'target_id' => $id]; }
            return ['reason' => 'IDEMPOTENT', 'target_type' => 'video', 'target_key' => $id, 'target_id' => $id];
        }
        $byReference = $this->videos->findByExternalReference($platform, $externalId);
        if ($byReference && $byReference->canonicalId !== $id) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Video external reference maps to a different canonical UUID.');
        $this->videos->create(new Video($id, $platform, $externalId, $url, (string) ($record['canonical_name'] ?? ''), $metadata, null, !$this->isArchived($record)));
        return ['reason' => 'READY', 'target_type' => 'video', 'target_key' => $id, 'target_id' => $id];
    }

    private function relation(array $record): array
    {
        if (!empty($record['source_missing']) || !empty($record['target_missing'])) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Relation endpoint was not present in the source identity inventory.');
        $predicate = (string) ($record['predicate'] ?? $record['relation_type'] ?? '');
        if (!in_array($predicate, ['about', 'depicts'], true)) throw new MigrationSkip('skipped', 'UNSUPPORTED_LEGACY_TYPE', 'Legacy predicate requires explicit V3 mapping.');
        $source = $this->nodeReference((string) ($record['source_type'] ?? ''), (string) ($record['source_key'] ?? ''));
        $target = $this->nodeReference((string) ($record['target_type'] ?? ''), (string) ($record['target_key'] ?? ''));
        if (!$source || !$target) throw new MigrationSkip('skipped', 'INVALID_RELATION', 'Relation endpoint type cannot be normalized.');
        $edge = $this->graph->create($source, $predicate, $target);
        return ['reason' => 'READY', 'target_type' => 'graph_edge', 'target_key' => $edge->edge_uuid, 'target_id' => $edge->edge_uuid];
    }

    private function nodeReference(string $type, string $key): ?NodeReference
    {
        if ($type === '' || $key === '') return null;
        $map = ['article' => 'wp_post', 'wp_post' => 'wp_post', 'brand' => 'brand', 'model' => 'model', 'variant' => 'variant', 'movement' => 'movement', 'music' => 'music', 'component' => 'component', 'classification' => 'classification', 'specimen' => 'specimen', 'product' => 'product', 'media' => 'media', 'knowledge' => 'knowledge'];
        if (!isset($map[$type])) return null;
        if ($map[$type] === 'wp_post' && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $key) !== 1) return null;
        if ($map[$type] !== 'wp_post' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) !== 1) return null;
        return new NodeReference($map[$type], $key);
    }

    private function authorityPayload(string $type, array $metadata): array
    {
        unset($metadata['private_notes'], $metadata['token'], $metadata['password'], $metadata['secret']);
        $payload = [];
        $fields = (array) $this->types->get($type)->allowedFields;
        foreach ($fields as $field) {
            if (!array_key_exists($field, $metadata)) continue;
            $value = $metadata[$field];
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_array($value)) $payload[$field] = $value;
        }
        if (in_array('description', $fields, true) && !isset($payload['description'])) $payload['description'] = trim((string) ($metadata['summary'] ?? $metadata['description'] ?? ''));
        if (in_array('aliases', $fields, true) && !isset($payload['aliases'])) $payload['aliases'] = [];
        return $payload;
    }
    private function claimType(array $metadata): string { $kind = strtolower((string) ($metadata['claim_type'] ?? $metadata['semantic_type'] ?? '')); return str_contains($kind, 'history') ? 'history' : (str_contains($kind, 'technical') || str_contains($kind, 'spec') ? 'technical' : 'fact'); }
    private function sourceKey(array $record): string { return (string) ($record['stable_key'] ?? ($record['source_key'] ?? ($record['type'] ?? '') . ':' . ($record['legacy_id'] ?? ($record['canonical_uuid'] ?? '')))); }
    private function isArchived(array $record): bool { return in_array(strtoupper((string) ($record['review_state'] ?? '')), ['ARCHIVED', 'RETIRED'], true); }
    private function contentEquivalent(string $target, string $source): bool { return $target === $source || (function_exists('wp_specialchars_decode') && wp_specialchars_decode($target, ENT_QUOTES) === $source); }
    private function checksum(array $record): string { return hash('sha256', (string) wp_json_encode(['mapper' => self::MAPPER_VERSION, 'record' => $record], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
}

final class MigrationSkip extends \RuntimeException
{
    public function __construct(public string $status, public string $reason, string $message) { parent::__construct($message); }
}
