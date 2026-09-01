<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Media\MediaAsset;
use Symfony\Component\Uid\Uuid;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, InMemoryAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Knowledge\WpdbKnowledgeRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\WpdbMediaRepository;
use NHK\Core\Infrastructure\Media\WpdbMediaAssetRepository;
use NHK\Core\Infrastructure\Migration\WpdbMigrationLedgerRepository;
use NHK\Core\Infrastructure\Migration\WpdbProjectionContextRepository;
use NHK\Core\Application\Graph\GraphService;

final class V2MigrationService
{
    private const MAPPER_VERSION = '6.14';
    private WpdbMigrationLedgerRepository $ledger;
    private WpdbProjectionContextRepository $projectionContexts;
    private WpdbAuthorityRepository $authority;
    private WpdbMediaRepository $media;
    private WpdbMediaAssetRepository $assets;
    private WpdbKnowledgeRepository $knowledge;
    private WpdbSourceRepository $sources;
    private WpdbEvidenceRepository $evidence;
    private \NHK\Core\Infrastructure\Video\WpdbVideoRepository $videos;
    private EntityTypeRegistry $types;
    private GraphService $graph;

    public function __construct(private object $database)
    {
        $this->ledger = new WpdbMigrationLedgerRepository($database);
        $this->projectionContexts = new WpdbProjectionContextRepository($database);
        $this->authority = new WpdbAuthorityRepository($database);
        $this->media = new WpdbMediaRepository($database);
        $this->assets = new WpdbMediaAssetRepository($database);
        $this->knowledge = new WpdbKnowledgeRepository($database);
        $this->sources = new WpdbSourceRepository($database);
        $this->evidence = new WpdbEvidenceRepository($database);
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
        $processed = 0; $migrated = 0; $skipped = 0; $conflict = 0; $reviewByAction = [];
        foreach ($records as $record) {
            if ($processed >= $limit) break;
            if (!is_array($record)) continue;
            $type = (string) ($record['type'] ?? '');
            $key = $this->sourceKey($record);
            if ($type === '' || $key === '') continue;
            $checksum = $this->checksum($record);
            $existing = $this->ledger->find($type, $key);
            $existingDetails = is_array(json_decode((string) ($existing['details_json'] ?? ''), true)) ? json_decode((string) ($existing['details_json'] ?? ''), true) : [];
            $reprocessProjection = $type === 'legacy_semantic_projection' && ((string) ($existing['reason_code'] ?? '') === 'UNSUPPORTED_LEGACY_TYPE' || ((string) ($existing['reason_code'] ?? '') === 'CONTEXT_SINK_READY' && (int) ($existingDetails['context_schema_version'] ?? 0) < 1));
            if ($existing && !$reprocessProjection && (string) ($existing['source_checksum'] ?? '') === $checksum && in_array((string) $existing['status'], ['migrated', 'skipped', 'conflict'], true)) { $processed++; if ($existing['status'] === 'migrated') $migrated++; elseif ($existing['status'] === 'conflict') $conflict++; else $skipped++; $reviewAction = $this->reviewAction((string) ($existing['reason_code'] ?? '')); if ($reviewAction !== null) $reviewByAction[$reviewAction] = ($reviewByAction[$reviewAction] ?? 0) + 1; continue; }
            $processed++;
            try {
                $result = $this->migrate($record, $batchNo);
                $this->ledger->record($type, $key, 'migrated', $result['reason'], $checksum, $result['target_type'] ?? null, $result['target_key'] ?? null, $result['target_id'] ?? null, $batchNo, $result['details'] ?? []);
                $migrated++;
            } catch (MigrationSkip $error) {
                $details = ['message' => $error->getMessage()];
                $review = $this->reviewDetails($record, $error->reason);
                if ($review !== []) $details['review'] = $review;
                $this->ledger->record($type, $key, $error->status, $error->reason, $checksum, null, null, null, $batchNo, $details);
                $reviewAction = $this->reviewAction($error->reason);
                if ($reviewAction !== null) $reviewByAction[$reviewAction] = ($reviewByAction[$reviewAction] ?? 0) + 1;
                if ($error->status === 'conflict') $conflict++; else $skipped++;
            } catch (\Throwable $error) {
                // A storage or domain failure is recorded as a conflict and is
                // never reported as a successful migration.
                $this->ledger->record($type, $key, 'conflict', 'MIGRATION_FAILED', $checksum, null, null, null, $batchNo, ['message' => $error->getMessage(), 'exception' => get_class($error)]);
                $conflict++;
            }
        }
        return ['processed' => $processed, 'migrated' => $migrated, 'skipped' => $skipped, 'conflict' => $conflict, 'review_by_action' => $reviewByAction, 'ledger' => $this->ledger->counts()];
    }

    private function reviewAction(string $reason): ?string
    {
        return match ($reason) {
            'DOMAIN_TARGETED' => 'EXPLICIT_MAPPING_REQUIRED',
            'UNSUPPORTED_MEDIA_REFERENCE' => 'SOURCE_RECOVERY_REQUIRED',
            'RETIRED_LEGACY_GARBAGE' => 'RETIRE_NO_EDITORIAL_IMPORT',
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function reviewDetails(array $record, string $reason): array
    {
        return match ($reason) {
            'DOMAIN_TARGETED' => [
                'target_domain' => [
                    'nhk_brand' => 'brand', 'nhk_model' => 'model', 'nhk_variant' => 'variant',
                    'nhk_movement' => 'movement', 'nhk_music' => 'music', 'nhk_component' => 'component',
                    'nhk_classification' => 'classification', 'nhk_specimen' => 'specimen',
                    'nhk_product' => 'product', 'nhk_knowledge' => 'knowledge',
                ][(string) ($record['legacy_type'] ?? '')] ?? ($record['target_type'] ?? null),
                'requires_explicit_mapping' => true,
                'name_only_match_forbidden' => true,
            ],
            'UNSUPPORTED_MEDIA_REFERENCE' => ['target_domain' => 'media_asset', 'requires_source_recovery' => true],
            'RETIRED_LEGACY_GARBAGE' => ['disposition' => 'retire', 'editorial_import_forbidden' => true],
            default => [],
        };
    }

    private function migrate(array $record, int $batchNo): array
    {
        if (!empty($record['conflict'])) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Source record is marked conflicted.');
        return match ((string) $record['type']) {
            'category' => $this->category($record),
            'wp_post' => $this->post($record),
            'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => $this->authorityEntity($record),
            'media' => $this->mediaEntity($record),
            'legacy_media_asset' => $this->mediaAsset($record),
            'video' => $this->videoEntity($record),
            'knowledge' => $this->knowledgeClaim($record),
            'source' => $this->source($record),
            'evidence' => $this->evidence($record),
            'legacy_semantic_projection' => $this->projectionContext($record),
            'url' => $this->url($record),
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
        if ($legacyType === 'attachment') throw new MigrationSkip('skipped', 'UNSUPPORTED_MEDIA_REFERENCE', 'Legacy attachment has no governed V3 MediaAsset target.');
        if ($legacyType === 'wp_global_styles') throw new MigrationSkip('skipped', 'RETIRED_LEGACY_GARBAGE', 'Legacy WordPress global styles are replaced by the V3 theme token system.');
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

    /**
     * Preserve only bounded legacy projection metadata. This is not a
     * canonical entity, public content, or editorial body.
     */
    private function projectionContext(array $record): array
    {
        if (array_key_exists('body', $record) || array_key_exists('content', $record) || array_key_exists('post_content', $record)) throw new MigrationSkip('skipped', 'PROJECTION_BODY_FORBIDDEN', 'Projection bodies cannot enter the V3 context sink.');
        $sourceKey = trim((string) ($record['stable_key'] ?? ''));
        $projectionId = trim((string) ($record['legacy_id'] ?? $sourceKey));
        $semanticId = trim((string) ($record['semantic_id'] ?? ''));
        if ($semanticId === '') $semanticId = preg_replace('/:projection:[^:]+$/', '', $sourceKey) ?: $sourceKey;
        $objectId = trim((string) ($record['canonical_object_id'] ?? ''));
        $objectType = trim((string) ($record['canonical_object_type'] ?? ''));
        $projectionType = trim((string) ($record['legacy_type'] ?? ''));
        if ($sourceKey === '' || $projectionId === '' || $semanticId === '' || $objectId === '' || $objectType === '' || $projectionType === '') throw new MigrationSkip('skipped', 'INVALID_PROJECTION_CONTEXT', 'Projection context is incomplete.');
        $provenance = [
            'context_schema_version' => 1,
            'source_system' => 'v2',
            'source_table' => 'nhk_semantic_projections',
            'source_projection_id' => $projectionId,
            'semantic_id' => $semanticId,
            'body_migrated' => false,
        ];
        $this->projectionContexts->upsert([
            'source_key' => $sourceKey,
            'projection_id' => $projectionId,
            'semantic_id' => $semanticId,
            'canonical_object_id' => $objectId,
            'canonical_object_type' => $objectType,
            'projection_type' => $projectionType,
            'visibility' => trim((string) ($record['visibility'] ?? 'UNKNOWN')),
            'quality_state' => trim((string) ($record['quality_state'] ?? 'UNKNOWN')),
            'seo_ready' => (int) !empty($record['seo_ready']),
            'ai_ready' => (int) !empty($record['ai_ready']),
            'stale' => (int) !empty($record['stale']),
            'provenance' => $provenance,
        ]);
        return ['reason' => 'CONTEXT_SINK_READY', 'target_type' => 'projection_context', 'target_key' => $sourceKey, 'target_id' => $projectionId, 'details' => $provenance];
    }

    private function source(array $record): array
    {
        $id = (string) ($record['canonical_uuid'] ?? '');
        $key = (string) ($record['stable_key'] ?? '');
        $title = trim((string) ($record['canonical_name'] ?? ''));
        $locator = trim((string) ($record['locator'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $key === '' || $title === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Source identity or title is incomplete.');
        if ($locator !== '' && filter_var($locator, FILTER_VALIDATE_URL) === false) $locator = '';
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        foreach (['visibility', 'verification_state', 'review_state', 'legacy_id'] as $field) if (array_key_exists($field, $record)) $metadata[$field] = $record[$field];
        $sourceTypeValue = trim((string) ($record['legacy_type'] ?? ''));
        if ($sourceTypeValue === '') $sourceTypeValue = trim((string) ($record['source_type'] ?? ''));
        if ($sourceTypeValue === '') $sourceTypeValue = trim((string) ($metadata['source_type'] ?? ''));
        $sourceType = $this->sourceType($sourceTypeValue);
        $active = !$this->isArchived($record) && !in_array(strtoupper((string) ($record['visibility'] ?? '')), ['PRIVATE', 'HIDDEN'], true);
        $source = new Source($id, $key, $title, $sourceType, $locator !== '' ? $locator : null, $metadata, $active);
        $existing = $this->sources->findByCanonicalId($id);
        if (!$existing) {
            $this->sources->create($source);
            return ['reason' => 'READY', 'target_type' => 'source', 'target_key' => $key, 'target_id' => $id];
        }
        if ($existing->stableKey !== $key || $existing->title !== $title || $existing->sourceType !== $sourceType || $existing->locator !== $source->locator || $existing->metadata !== $metadata) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Source UUID maps to changed source metadata.');
        if ($existing->active !== $active) {
            $this->sources->update(new Source($id, $key, $title, $sourceType, $source->locator, $metadata, $active, $existing->revision), $existing->revision);
            return ['reason' => 'STATE_RECONCILED', 'target_type' => 'source', 'target_key' => $key, 'target_id' => $id];
        }
        return ['reason' => 'IDEMPOTENT', 'target_type' => 'source', 'target_key' => $key, 'target_id' => $id];
    }

    private function evidence(array $record): array
    {
        $id = (string) ($record['canonical_uuid'] ?? '');
        $key = (string) ($record['stable_key'] ?? '');
        $claimId = (string) ($record['claim_id'] ?? '');
        $sourceId = (string) ($record['source_id'] ?? '');
        $excerpt = trim((string) ($record['excerpt'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $key === '' || !preg_match('/^[0-9a-f-]{36}$/i', $claimId) || !preg_match('/^[0-9a-f-]{36}$/i', $sourceId) || $excerpt === '') throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Evidence requires citation, claim, source and excerpt identities.');
        if (strtolower((string) ($record['target_type'] ?? 'knowledge')) !== 'knowledge') throw new MigrationSkip('skipped', 'UNSUPPORTED_LEGACY_TYPE', 'V3 Evidence targets Knowledge claims only.');
        if (!$this->knowledge->findByCanonicalId($claimId)) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Evidence claim endpoint was not imported.');
        if (!$this->sources->findByCanonicalId($sourceId)) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Evidence source endpoint was not imported.');
        $relation = $this->evidenceRelation((string) ($record['citation_role'] ?? $record['relation'] ?? ''));
        $locator = trim((string) ($record['locator'] ?? ''));
        if ($locator !== '' && filter_var($locator, FILTER_VALIDATE_URL) === false) $locator = '';
        $active = !$this->isArchived($record) && !in_array(strtoupper((string) ($record['visibility'] ?? '')), ['PRIVATE', 'HIDDEN'], true);
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        foreach (['verification_state', 'visibility', 'review_state', 'excerpt_metadata', 'legacy_id'] as $field) if (array_key_exists($field, $record)) $metadata[$field] = $record[$field];
        $evidence = new Evidence($id, $claimId, $sourceId, $relation, $excerpt, $locator !== '' ? $locator : null, $active, 1, $metadata);
        $existing = $this->evidence->findByCanonicalId($id);
        if ($existing) {
            if ($existing->claimId !== $evidence->claimId || $existing->sourceId !== $evidence->sourceId || $existing->relation !== $evidence->relation || $existing->excerpt !== $evidence->excerpt || $existing->locator !== $evidence->locator) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Evidence UUID maps to changed citation content.');
            if ($existing->metadata !== $evidence->metadata || $existing->active !== $evidence->active) {
                $this->evidence->update($evidence, $existing->revision);
                return ['reason' => 'STATE_RECONCILED', 'target_type' => 'evidence', 'target_key' => $key, 'target_id' => $id];
            }
            return ['reason' => 'IDEMPOTENT', 'target_type' => 'evidence', 'target_key' => $key, 'target_id' => $id];
        }
        $this->evidence->create($evidence);
        return ['reason' => 'READY', 'target_type' => 'evidence', 'target_key' => $key, 'target_id' => $id];
    }

    private function url(array $record): array
    {
        $sourcePath = trim((string) ($record['source_path'] ?? ''));
        $targetPath = trim((string) ($record['target_path'] ?? ''));
        if ($sourcePath === '') throw new MigrationSkip('skipped', 'INVALID_URL_MAPPING', 'URL has no source path.');
        $targetReason = strtoupper((string) ($record['target_reason'] ?? ''));
        if ($sourcePath === '/' && $targetPath === '') $targetPath = '/';
        if ($targetPath === '' && in_array($targetReason, ['DOMAIN_TARGETED', 'UNSUPPORTED_MEDIA_REFERENCE', 'RETIRED_LEGACY_GARBAGE'], true)) throw new MigrationSkip('skipped', $targetReason, match ($targetReason) { 'UNSUPPORTED_MEDIA_REFERENCE' => 'Legacy attachment has no governed V3 MediaAsset target.', 'RETIRED_LEGACY_GARBAGE' => 'Legacy URL belongs to a retired non-editorial record.', default => 'Legacy URL belongs to a domain without a public V3 route.' });
        if ($targetPath === '') throw new MigrationSkip('skipped', 'INVALID_URL_MAPPING', 'URL has no governed V3 target path.');
        if (!str_starts_with($sourcePath, '/') || !str_starts_with($targetPath, '/') || str_contains($sourcePath, '..') || str_contains($targetPath, '..')) throw new MigrationSkip('skipped', 'INVALID_URL_MAPPING', 'URL paths must be absolute local paths.');
        if ($sourcePath === $targetPath) return ['reason' => 'READY_NOOP', 'target_type' => 'wp_url', 'target_key' => $sourcePath, 'target_id' => $targetPath];
        $entityType = trim((string) ($record['target_entity_type'] ?? ''));
        $entityId = trim((string) ($record['target_entity_id'] ?? ''));
        $entityKey = trim((string) ($record['target_entity_key'] ?? ''));
        if ($entityType !== '' || $entityId !== '' || $entityKey !== '') {
            $authorityTypes = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];
            if ((!in_array($entityType, $authorityTypes, true) && $entityType !== 'knowledge') || !preg_match('/^[0-9a-f-]{36}$/i', $entityId) || $entityKey === '') throw new MigrationSkip('skipped', 'INVALID_URL_MAPPING', 'Entity URL target identity is incomplete.');
            if ($entityType === 'knowledge') {
                $claim = $this->knowledge->findByCanonicalId($entityId);
                if (!$claim || $claim->stableKey !== $entityKey || !$claim->active || !$claim->isPublic()) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Knowledge URL target is not an active public governed claim.');
            } else {
                $entity = $this->authority->findByCanonicalId($entityId);
                if (!$entity || $entity->entityType !== $entityType || $entity->stableKey !== $entityKey || !$entity->active()) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Entity URL target is not an active governed endpoint.');
            }
            $redirects = get_option('nhk_v2_entity_redirects', []);
            $redirects = is_array($redirects) ? $redirects : [];
            if (isset($redirects[$sourcePath]) && (string) $redirects[$sourcePath] !== $targetPath) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Legacy entity URL already points to a different target.');
            if (($redirects[$sourcePath] ?? null) !== $targetPath) {
                $redirects[$sourcePath] = $targetPath;
                $updated = update_option('nhk_v2_entity_redirects', $redirects, false);
                $stored = get_option('nhk_v2_entity_redirects', []);
                if (!$updated && (!is_array($stored) || (string) ($stored[$sourcePath] ?? '') !== $targetPath)) throw new MigrationSkip('conflict', 'MIGRATION_FAILED', 'Entity URL redirect registry could not be persisted.');
            }
            return ['reason' => 'READY', 'target_type' => 'entity_redirect', 'target_key' => $entityKey, 'target_id' => $entityId, 'details' => ['target_path' => $targetPath, 'entity_type' => $entityType]];
        }
        $legacyId = trim((string) ($record['legacy_id'] ?? ''));
        $posts = $legacyId === '' ? [] : get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'meta_key' => '_nhk_v2_source_key', 'meta_value' => 'wp_post:' . $legacyId, 'numberposts' => 1]);
        if (!$posts) throw new MigrationSkip('skipped', 'INVALID_URL_MAPPING', 'URL target has no governed native WordPress post.');
        $postId = (int) $posts[0]->ID;
        if (get_post_meta($postId, '_nhk_v2_redirect_path', true) !== $sourcePath) update_post_meta($postId, '_nhk_v2_redirect_path', $sourcePath);
        return ['reason' => 'READY', 'target_type' => 'wp_redirect', 'target_key' => $sourcePath, 'target_id' => (string) $postId, 'details' => ['target_path' => $targetPath]];
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

    private function mediaAsset(array $record): array
    {
        $mediaId = (string) ($record['media_id'] ?? ''); $publicId = trim((string) ($record['stable_key'] ?? ''));
        $storageKey = trim((string) ($record['storage_key'] ?? '')); $checksum = strtolower(trim((string) ($record['checksum'] ?? '')));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $mediaId) || !$this->media->findByCanonicalId($mediaId)) throw new MigrationSkip('skipped', 'MISSING_ENDPOINT', 'Media asset parent is not an imported Media identity.');
        if ($publicId === '' || $storageKey === '' || preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Media asset requires public id, storage path and checksum.');
        $assetId = Uuid::v5(Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'), 'nhk-v2-media-asset:' . $publicId)->toRfc4122();
        $existing = $this->assets->findByAssetId($assetId);
        $width = (int) ($record['width'] ?? 0); $height = (int) ($record['height'] ?? 0);
        $visibility = strtoupper((string) ($record['visibility'] ?? 'PRIVATE'));
        if (!in_array($visibility, ['PUBLIC', 'PRIVATE', 'HIDDEN'], true)) throw new MigrationSkip('skipped', 'INVALID_IDENTITY', 'Media asset visibility is invalid.');
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        foreach (['status', 'legacy_id', 'public_id'] as $field) if (array_key_exists($field, $record)) $metadata[$field] = $record[$field];
        $asset = new MediaAsset($assetId, $mediaId, 'original', $storageKey, $checksum, (string) ($record['mime_type'] ?? ''), max(0, (int) ($record['byte_size'] ?? 0)), $width > 0 ? $width : null, $height > 0 ? $height : null, $visibility, $metadata);
        if ($existing) {
            if ($existing->mediaId !== $asset->mediaId || $existing->storageKey !== $asset->storageKey || $existing->checksum !== $asset->checksum) throw new MigrationSkip('conflict', 'CONFLICT_REQUIRES_REVIEW', 'Media asset deterministic identity maps to changed storage.');
            if ($existing->visibility !== $asset->visibility || $existing->metadata !== $asset->metadata) {
                $this->assets->update($asset, 1);
                return ['reason' => 'STATE_RECONCILED', 'target_type' => 'media_asset', 'target_key' => $assetId, 'target_id' => $assetId];
            }
            return ['reason' => 'IDEMPOTENT', 'target_type' => 'media_asset', 'target_key' => $assetId, 'target_id' => $assetId];
        }
        $this->assets->create($asset);
        return ['reason' => 'READY', 'target_type' => 'media_asset', 'target_key' => $assetId, 'target_id' => $assetId];
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
    private function sourceType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (in_array($normalized, ['publication', 'website', 'archive', 'catalog', 'interview', 'other'], true)) return $normalized;
        $type = strtoupper($type);
        return str_contains($type, 'ARCHIVE') || str_contains($type, 'REGISTRY') || str_contains($type, 'HISTORICAL') ? 'archive' : (str_contains($type, 'PRESS') || str_contains($type, 'AD') ? 'publication' : (str_contains($type, 'TECHNICAL') || str_contains($type, 'PRODUCT') ? 'website' : 'other'));
    }
    private function evidenceRelation(string $role): string { $role = strtoupper($role); return str_contains($role, 'CONTRADICT') ? 'contradicts' : (str_contains($role, 'QUALIF') || str_contains($role, 'PARTIAL') || str_contains($role, 'CORRECTION') || str_contains($role, 'BOUND') ? 'qualifies' : 'supports'); }
    private function sourceKey(array $record): string { return (string) ($record['stable_key'] ?? ($record['source_key'] ?? ($record['type'] ?? '') . ':' . ($record['legacy_id'] ?? ($record['canonical_uuid'] ?? '')))); }
    private function isArchived(array $record): bool
    {
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $reviewState = $record['review_state'] ?? ($metadata['review_state'] ?? '');
        return in_array(strtoupper(trim((string) $reviewState)), ['ARCHIVED', 'RETIRED'], true);
    }
    private function contentEquivalent(string $target, string $source): bool { return $target === $source || (function_exists('wp_specialchars_decode') && wp_specialchars_decode($target, ENT_QUOTES) === $source); }
    private function checksum(array $record): string { return hash('sha256', (string) wp_json_encode(['mapper' => self::MAPPER_VERSION, 'record' => $record], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
}

final class MigrationSkip extends \RuntimeException
{
    public function __construct(public string $status, public string $reason, string $message) { parent::__construct($message); }
}
