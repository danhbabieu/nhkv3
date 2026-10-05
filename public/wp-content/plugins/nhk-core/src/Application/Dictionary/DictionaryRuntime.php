<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Entity\{EntityMediaProjection, PublicRouteResolver};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Dictionary\{WpdbDictionaryCandidateRepository, WpdbDictionaryConceptRepository, WpdbDictionaryMentionRepository, WpdbDictionaryEntryRepository, WpdbDictionaryLexicalRelationRepository};
use NHK\Core\Infrastructure\Knowledge\WpdbKnowledgeRepository;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Migration\{DictionaryMigration015, DictionaryEntrySenseMigration024};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Governance\WpdbAuditSink;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Application\Graph\SemanticRelationGovernanceAdapter;
use NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};

final class DictionaryRuntime
{
    private DictionaryTermNormalizer $normalizer;
    private EntityTypeRegistry $types;
    private WpdbAuthorityRepository $authority;
    private WpdbKnowledgeRepository $knowledge;
    private PublicRouteResolver $routes;
    private WpdbDictionaryConceptRepository $concepts;
    private WpdbDictionaryCandidateRepository $candidates;
    private WpdbDictionaryMentionRepository $mentions;
    private WpdbDictionaryEntryRepository $entries;
    private DictionaryEntryMaterializationPlanner $materializationPlanner;
    private DictionaryPlanningService $planning;
    private DictionarySeedPlanner $seedPlanner;
    private DictionaryCurationService $curation;
    private DictionaryPublicQuery $publicQuery;
    private DictionaryEnrichmentCoverage $enrichmentCoverage;
    private ?array $detectionLabels = null;
    private ?SemanticRelationGovernanceAdapter $semanticRelationGovernance = null;
    private ?DictionaryLexicalRelationGovernanceAdapter $lexicalRelationGovernance = null;
    private ?DictionaryRelationFacetRegistry $relationFacetRegistry = null;

    public function __construct(private object $database)
    {
        $this->enrichmentCoverage = new DictionaryEnrichmentCoverage();
        $this->normalizer = new DictionaryTermNormalizer();
        $this->concepts = new WpdbDictionaryConceptRepository($database);
        $this->candidates = new WpdbDictionaryCandidateRepository($database);
        $this->mentions = new WpdbDictionaryMentionRepository($database);
        $this->entries = new WpdbDictionaryEntryRepository($database, $this->concepts);
        $this->materializationPlanner = new DictionaryEntryMaterializationPlanner(
            $this->concepts,
            fn (string $conceptId): ?\NHK\Core\Domain\Dictionary\LexicalEntry => $this->entries->findDurableForConcept($conceptId),
            fn (string $conceptId): array => $this->concepts->listLabels($conceptId, true),
            function (string $type, string $id, string $url): bool {
                if ($type === 'dictionary') return true;
                return $this->revalidateDelegatedDestination($type, $id, $url) !== null;
            },
            $this->normalizer,
            fn (): bool => $this->entrySenseAvailable(),
        );
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
        $this->authority = new WpdbAuthorityRepository($database);
        $this->knowledge = new WpdbKnowledgeRepository($database);
        $this->routes = new PublicRouteResolver($this->authority, $this->types);

        $contextHash = fn (array $context): string => $this->contextHash($context);
        $canonicalAuthorityResolver = new CanonicalAuthoritySubjectResolver($this->authority, $this->types);
        $resolver = new DictionaryResolver(
            approvedLabelLookup: fn (string $term, array $context): array => $this->approvedLabelRows($term, $context),
            entityLookup: function (string $term, array $context) use ($canonicalAuthorityResolver): array {
                $out = [];
                foreach ($canonicalAuthorityResolver->resolve($term) as $match) {
                    $id = trim((string) ($match['id'] ?? ''));
                    $type = trim((string) ($match['type'] ?? ''));
                    if ($id === '' || $type === '') continue;
                    $entity = $this->authority->findByCanonicalId($id);
                    if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== $type) continue;
                    $out[$id] = [
                        'preferred_label' => $entity->canonicalName,
                        'destination_type' => $entity->entityType,
                        'destination_id' => $entity->canonicalId,
                        'destination_url' => $this->routes->path($entity),
                        'match' => (string) ($match['match'] ?? ''),
                    ];
                }
                return array_values($out);
            },
            knowledgeLookup: function (string $term, array $context): array {
                $out = [];
                foreach ($this->knowledge->list() as $claim) {
                    if (!$claim instanceof KnowledgeClaim || !$claim->active) continue;
                    if ($this->normalizer->normalize($claim->claimText) !== $term) continue;
                    $out[$claim->canonicalId] = [
                        'preferred_label' => $claim->claimText,
                        'destination_type' => 'knowledge',
                        'destination_id' => $claim->canonicalId,
                        'destination_url' => null,
                    ];
                }
                return array_values($out);
            },
            articleLookup: function (string $term, array $context): array {
                if (!function_exists('get_posts')) return [];
                $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => 20, 'no_found_rows' => true]);
                $out = [];
                foreach ($posts as $post) {
                    if (!$post instanceof \WP_Post || $this->normalizer->normalize((string) $post->post_title) !== $term) continue;
                    $url = function_exists('get_permalink') ? get_permalink($post) : false;
                    $out[] = ['preferred_label' => (string) $post->post_title, 'destination_type' => 'article', 'destination_id' => (string) $post->ID, 'destination_url' => is_string($url) ? $url : null];
                }
                return $out;
            },
            suppressionLookup: fn (string $term, array $context): bool => $this->candidates->suppressed($term, $contextHash($context)),
            normalizer: $this->normalizer,
        );

        $this->planning = new DictionaryPlanningService(new DictionaryTermDetector($this->normalizer), $resolver, $this->candidates, $this->mentions, new DictionaryLinkPlanner());
        $this->seedPlanner = new DictionarySeedPlanner($resolver);
        $this->curation = new DictionaryCurationService(
            $this->candidates,
            $this->concepts,
            null,
            $this->normalizer,
            function (string $type, string $id): bool {
                if (!$this->types->has($type)) return false;
                $entity = $this->authority->findByCanonicalId($id);
                return $entity instanceof AuthorityEntity && $entity->entityType === $type && $entity->active();
            },
        );
        $mediaProjection = new EntityMediaProjection(new WpdbMediaRepository($database), new WpdbMediaAssetRepository($database), new WpdbMediaUsageRepository($database));
        $mentionGallery = new PublicMediaGalleryQuery(new WpdbMediaRepository($database), new WpdbMediaAssetRepository($database));
        $videoRepository = new WpdbVideoRepository($database);
        $mentionProjection = new DictionaryMentionPublicProjection($this->mentions, function (string $kind, string $id) use ($mentionGallery, $videoRepository): ?array {
            $kind = strtoupper(trim($kind));
            if ($kind === 'ARTICLE' && function_exists('get_post')) {
                $post = get_post((int) $id);
                if ($post instanceof \WP_Post && $post->post_status === 'publish') return ['id' => (string) $post->ID, 'title' => (string) $post->post_title, 'url' => (string) get_permalink($post)];
            }
            if ($kind === 'KNOWLEDGE') foreach ($this->knowledge->list() as $claim) if ($claim instanceof KnowledgeClaim && $claim->active && $claim->canonicalId === $id) return ['id' => $id, 'title' => $claim->claimText, 'url' => null];
            if ($kind === 'MEDIA') {
                $media = $mentionGallery->forMedia($id);
                if (is_array($media) && trim((string) ($media['title'] ?? '')) !== '') return ['id' => $id, 'title' => (string) $media['title'], 'url' => (string) ($media['image_url'] ?? ''), 'thumbnail_url' => $media['thumbnail_url'] ?? null];
            }
            if ($kind === 'VIDEO') {
                $video = $videoRepository->findByCanonicalId($id);
                if ($video !== null && $video->active && $video->hasValidPublicReference()) {
                    $metadata = is_array($video->metadata) ? $video->metadata : [];
                    $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                    $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title;
                    $route = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
                    $url = ($route['eligible'] ?? false) === true ? (string) ($route['path'] ?? '') : '';
                    if ($url !== '') return ['id' => $id, 'title' => $title, 'url' => $url];
                }
            }
            return null;
        });
        $detailQuery = new DictionaryDetailQuery(
            $this->concepts,
            $this->entries,
            fn (?string $type, ?string $id, ?string $url): ?string => $this->revalidateDelegatedDestination($type, $id, $url),
            fn (string $type, string $id): array => $this->canonicalOwnerDossier($type, $id),
            fn (string $conceptId): array => $mentionProjection->forConcept($conceptId, 50),
            new DictionaryRelatedTermProjection($this->entries, function (string $type, string $id): array {
                if (!$this->types->has($type)) return [];
                $entity = $this->authority->findByCanonicalId($id);
                if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== $type) return [];
                $value = ['dossier' => null];
                if (function_exists('apply_filters') && has_filter('nhk_v3_entity_detail_projection')) $value = apply_filters('nhk_v3_entity_detail_projection', $value, $entity);
                $sections = is_array($value['dossier']['relation_sections'] ?? null) ? $value['dossier']['relation_sections'] : [];
                $candidates = [];
                foreach ($sections as $group => $items) foreach ((array) $items as $item) {
                    if (!is_array($item)) continue;
                    $candidateId = trim((string) ($item['canonical_id'] ?? $item['id'] ?? ''));
                    $candidateType = trim((string) ($item['type'] ?? rtrim((string) $group, 's')));
                    if ($candidateId !== '' && $candidateType !== '') $candidates[] = ['type' => $candidateType, 'id' => $candidateId, 'origin' => $item['origin'] ?? ['kind' => 'DERIVED', 'hop_count' => 2]];
                }
                return $candidates;
            }, new WpdbDictionaryLexicalRelationRepository($database)),
        );
        $this->enrichmentCoverage = new DictionaryEnrichmentCoverage($this->coverageProviders());
        $this->publicQuery = new DictionaryPublicQuery(
            $this->concepts,
            static function (string $conceptId) use ($mediaProjection): ?array {
                $projection = $mediaProjection->forEntity('dictionary_concept', $conceptId);
                return is_array($projection['representative'] ?? null) ? $projection['representative'] : null;
            },
            fn (?string $type, ?string $id, ?string $url): ?string => $this->revalidateDelegatedDestination($type, $id, $url),
            $this->entries,
            fn (): bool => $this->entrySenseAvailable(),
            fn (string $type, string $id): array => $this->canonicalOwnerDossier($type, $id),
            $detailQuery,
        );
    }

    private function canonicalOwnerDossier(string $type, string $id): array
    {
        if (!$this->types->has($type)) return [];
        $entity = $this->authority->findByCanonicalId($id);
        if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== $type) return [];
        $value = ['dossier' => null];
        if (function_exists('apply_filters') && has_filter('nhk_v3_entity_detail_projection')) $value = apply_filters('nhk_v3_entity_detail_projection', $value, $entity);
        if (is_array($value['dossier'] ?? null)) return $value['dossier'];
        return ['identity' => ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'url' => $this->routes->path($entity)]];
    }

    /** @return array<string,callable> */
    private function coverageProviders(): array
    {
        $packet = fn (string $type, string $id): array => $this->canonicalOwnerDossier($type, $id);
        $items = static function (array $value, array $keys): array {
            $items = [];
            foreach ($keys as $key) foreach ((array) ($value[$key] ?? []) as $item) if (is_array($item)) $items[] = $item;
            return $items;
        };
        return [
            'knowledge' => function (string $type, string $id) use ($packet): array { return DictionaryEnrichmentCoverage::knowledgeFromOwnerDossier($packet($type, $id)); },
            'media' => function (string $type, string $id) use ($packet, $items): array { $value = $packet($type, $id); $rows = []; if (is_array($value['primary_media'] ?? null)) $rows[] = $value['primary_media']; $rows = array_merge($rows, $items((array) ($value['relation_sections'] ?? []), ['media'])); return ['status' => $rows === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'count' => count($rows), 'items' => $rows]; },
            'video' => function (string $type, string $id) use ($packet, $items): array { $rows = $items($packet($type, $id)['relation_sections'] ?? [], ['videos']); return ['status' => $rows === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'count' => count($rows), 'items' => $rows]; },
            'articles' => function (string $type, string $id) use ($packet, $items): array { $rows = $items($packet($type, $id)['relation_sections'] ?? [], ['articles', 'wp_posts']); return ['status' => $rows === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'count' => count($rows), 'items' => $rows]; },
        ];
    }

    public function available(): bool
    {
        try { return DictionaryMigration015::schemaReady($this->database); }
        catch (\Throwable) { return false; }
    }

    public function baseAvailable(): bool { return $this->available(); }

    public function entrySenseAvailable(): bool
    {
        if (!$this->baseAvailable()) return false;
        try { return DictionaryEntrySenseMigration024::schemaReady($this->database); }
        catch (\Throwable) { return false; }
    }

    public function readiness(): array
    {
        $base = $this->baseAvailable();
        $entrySense = $this->entrySenseAvailable();
        $state = ['base_dictionary_storage_ready' => $base, 'entry_sense_schema_ready' => $entrySense, 'runtime_mode' => $entrySense ? 'ENTRY_SENSE_MODE' : 'COMPATIBILITY_CONCEPT_MODE'];
        if (!$entrySense) $state['reason'] = 'ENTRY_SENSE_SCHEMA_UNAVAILABLE';
        return $state;
    }

    public function preview(string $text, string $sourceKind, string $sourceId = '', array $context = [], array $hints = []): array
    {
        if (!$this->available()) throw new \RuntimeException('DICTIONARY_STORAGE_UNAVAILABLE');
        return $this->planning->preview($text, $sourceKind, $sourceId, $context, $hints, $this->detectionLabels());
    }

    public function plan(string $text, string $sourceKind, string $sourceId, array $context = [], array $hints = []): array
    {
        if (!$this->available()) throw new \RuntimeException('DICTIONARY_STORAGE_UNAVAILABLE');
        return $this->planning->plan($text, $sourceKind, $sourceId, $context, $hints, $this->detectionLabels());
    }

    public function backfillDryRun(int $limitPerKind = 500): array
    {
        if (!$this->available()) throw new \RuntimeException('DICTIONARY_STORAGE_UNAVAILABLE');
        $limit = max(1, min(2000, $limitPerKind));
        $sources = [];

        if (function_exists('get_posts')) {
            foreach (get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]) as $post) {
                if (!$post instanceof \WP_Post) continue;
                $text = implode("\n", array_filter([(string) $post->post_title, (string) $post->post_excerpt, function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) $post->post_content) : strip_tags((string) $post->post_content)]));
                if (trim($text) !== '') $sources[] = ['kind' => 'ARTICLE', 'id' => (string) $post->ID, 'text' => $text, 'context' => ['post_id' => (int) $post->ID, 'post_status' => (string) $post->post_status]];
            }
            foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]) as $post) {
                if (!$post instanceof \WP_Post) continue;
                $id = (int) $post->ID;
                $alt = function_exists('get_post_meta') ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '';
                $filename = function_exists('get_attached_file') ? basename((string) get_attached_file($id)) : '';
                $text = implode("\n", array_filter([(string) $post->post_title, (string) $post->post_excerpt, (string) $post->post_content, $alt, $filename]));
                if (trim($text) !== '') $sources[] = ['kind' => 'MEDIA', 'id' => (string) $id, 'text' => $text, 'context' => ['attachment_id' => $id, 'weak_sources' => ['title', 'alt', 'filename']]];
            }
        }

        foreach (array_slice($this->knowledge->list(), 0, $limit) as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || trim($claim->claimText) === '') continue;
            $sources[] = ['kind' => 'KNOWLEDGE', 'id' => $claim->canonicalId, 'text' => $claim->claimText, 'context' => ['claim_type' => $claim->claimType]];
        }

        foreach (array_slice((new WpdbVideoRepository($this->database))->list(), 0, $limit) as $video) {
            if (!is_object($video) || !($video->active ?? false)) continue;
            $metadata = is_array($video->metadata ?? null) ? $video->metadata : [];
            $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : [];
            $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
            $parts = [(string) ($video->title ?? ''), (string) ($source['source_title'] ?? ''), (string) ($source['source_description'] ?? ''), (string) ($editorial['summary'] ?? '')];
            foreach ((array) ($source['tags'] ?? []) as $tag) if (is_string($tag)) $parts[] = $tag;
            $text = implode("\n", array_filter(array_map('trim', $parts)));
            if ($text === '') continue;
            $sources[] = ['kind' => 'VIDEO', 'id' => (string) ($video->canonicalId ?? ''), 'text' => $text, 'context' => ['platform' => (string) ($video->platform ?? ''), 'external_video_id' => (string) ($video->externalVideoId ?? '')]];
        }

        return (new DictionaryBackfillDryRun(fn (string $text, string $kind, array $context, array $hints): array => $this->preview($text, $kind, '', $context, $hints)))->scan($sources);
    }

    public function publicTerms(): array
    {
        if (!$this->available()) return [];
        return $this->publicTermsFromHubItems((array) ($this->publicQuery->hub(2000)['items'] ?? []));
    }

    /** @param list<array<string,mixed>> $hubItems @return list<array{concept_id:string,label:string,url:string}> */
    private function publicTermsFromHubItems(array $hubItems): array
    {
        $items = [];
        foreach ($hubItems as $item) {
            if (!is_array($item) || trim((string) ($item['url'] ?? '')) === '') continue;
            $conceptId = trim((string) ($item['concept_id'] ?? ''));
            $senses = array_values(array_filter((array) ($item['senses'] ?? []), static fn (mixed $sense): bool => is_array($sense) && trim((string) ($sense['sense_id'] ?? '')) !== ''));
            if ($senses !== []) {
                if (count($senses) !== 1) continue;
                $conceptId = trim((string) $senses[0]['sense_id']);
            }
            foreach ((array) ($item['labels'] ?? []) as $label) {
                if (!is_array($label) || (string) ($label['kind'] ?? '') === DictionaryLabel::HIDDEN) continue;
                $text = trim((string) ($label['label'] ?? ''));
                if ($conceptId === '' || $text === '') continue;
                $items[$conceptId . "\0" . $this->normalizer->normalize($text)] = ['concept_id' => $conceptId, 'label' => $text, 'url' => (string) $item['url']];
            }
        }
        return array_values($items);
    }

    public function curation(): DictionaryCurationService { return $this->curation; }
    public function publicQuery(): DictionaryPublicQuery { return $this->publicQuery; }
    public function enrichmentCoverage(): DictionaryEnrichmentCoverage { return $this->enrichmentCoverage; }
    public function enrichmentAudit(array $input): array
    {
        if (!$this->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE', 'read_only' => true, 'mutated' => false];
        $canonicalResolver = new CanonicalAuthoritySubjectResolver($this->authority, $this->types);
        $ownerResolver = new DictionaryEnrichmentOwnerResolver($canonicalResolver);
        $audit = new DictionaryEnrichmentAudit($this->entries, $this->concepts, fn (string $type, string $id, array $context = []): array => $this->enrichmentCoverage->forReference($type, $id, $context), function (DictionaryConcept $sense, array $context = []) use ($ownerResolver): array {
            $reference = is_array($context['semantic_reference'] ?? null) ? $context['semantic_reference'] : [];
            if (in_array(strtoupper((string) ($reference['status'] ?? '')), ['AVAILABLE', 'PRESENT_VALID'], true)) {
                $validated = $this->revalidateDelegatedDestination((string) ($reference['type'] ?? ''), (string) ($reference['id'] ?? ''), null);
                if ($validated === null) $reference['status'] = 'INVALID';
                $context['semantic_reference'] = $reference;
            }
            return $ownerResolver->resolve($sense, $context);
        });
        return $audit->audit((int) ($input['limit'] ?? 50), isset($input['cursor']) ? (string) $input['cursor'] : null, isset($input['entry_id']) ? (string) $input['entry_id'] : null, isset($input['sense_id']) ? (string) $input['sense_id'] : null, (bool) ($input['public_only'] ?? true));
    }
    public function enrichmentPlan(array $input): array
    {
        if (!$this->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE', 'read_only' => true, 'mutated' => false];
        $audit = is_array($input['audit'] ?? null) ? $input['audit'] : $this->enrichmentAudit($input);
        return (new DictionaryEnrichmentPlan($this->entries, new DictionaryEnrichmentOwnerResolver()))->build($audit, $input);
    }
    public function enrichmentApply(array $input): array
    {
        if (!$this->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE', 'read_only' => false, 'mutated' => false];
        $plan = is_array($input['plan'] ?? null) ? $input['plan'] : [];
        $expected = trim((string) ($input['approved_plan_fingerprint'] ?? ''));
        $fingerprint = (new DictionaryEnrichmentPlan($this->entries, new DictionaryEnrichmentOwnerResolver()))->fingerprint((array) ($plan['actions'] ?? []));
        if ($expected === '' || !hash_equals($fingerprint, $expected) || ($plan['fingerprint'] ?? '') !== $expected) return ['status' => 'blocked', 'reason' => 'DICTIONARY_ENRICHMENT_PLAN_FINGERPRINT_INVALID', 'read_only' => false, 'mutated' => false];
        if (!in_array(($plan['status'] ?? ''), ['READY', 'NOOP', 'REVIEW_REQUIRED', 'BLOCKED'], true)) return ['status' => 'blocked', 'reason' => 'DICTIONARY_ENRICHMENT_PLAN_STATUS_INVALID', 'read_only' => false, 'mutated' => false];
        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '') throw new \InvalidArgumentException('DICTIONARY_IDEMPOTENCY_KEY_REQUIRED');
        $coordinator = new DictionaryEnrichmentApplyCoordinator(
            function (array $action, int $revision, string $key) use ($expected): array {
                return match ($action['action_type'] ?? '') {
                    'ADD_ENTRY_FORM' => $this->mutation()->addFormToEntry((string) $action['entry_id'], $revision, (string) $action['form'], (array) ($action['context'] ?? []) + ['enrichment_plan' => $expected], $key, (string) ($action['kind'] ?? 'ALTERNATE'), isset($action['locale']) ? (string) $action['locale'] : null),
                    'SET_SEMANTIC_REFERENCE' => $this->mutation()->setSenseSemanticReference((string) $action['entry_id'], (string) $action['sense_id'], $revision, (string) ($action['target']['type'] ?? ''), (string) ($action['target']['id'] ?? ''), isset($action['target']['revision']) ? (int) $action['target']['revision'] : null, $key),
                    default => ['status' => 'NOOP'],
                };
            },
            function (string $key): ?array {
                $row = $this->database->get_row($this->database->prepare('SELECT context_json FROM ' . $this->database->prefix . 'nhk_audit_events WHERE event_type=%s AND object_type=%s AND object_key=%s ORDER BY id DESC LIMIT 1', 'DictionaryMutation', 'dictionary', $key), ARRAY_A);
                if (!is_array($row)) return null;
                $context = json_decode((string) ($row['context_json'] ?? ''), true);
                return is_array($context) && is_array($context['result'] ?? null) ? $context['result'] : null;
            },
            static function (string $key, array $result): void {},
            function (string $entryId): ?int {
                $entry = $this->entries->findById($entryId);
                return $entry instanceof \NHK\Core\Domain\Dictionary\LexicalEntry ? $entry->revision : null;
            },
        );
        $applied = $coordinator->apply((array) $plan['actions'], $idempotency);
        return $applied + ['read_only' => false, 'mutated' => ($applied['applied_count'] ?? 0) > 0, 'fingerprint' => $expected];
    }
    public function concepts(): WpdbDictionaryConceptRepository { return $this->concepts; }
    public function entries(): WpdbDictionaryEntryRepository { return $this->entries; }
    public function configureRelationGovernance(SemanticRelationGovernanceAdapter $semantic, DictionaryLexicalRelationGovernanceAdapter $lexical, DictionaryRelationFacetRegistry $facets): void
    {
        $this->semanticRelationGovernance = $semantic;
        $this->lexicalRelationGovernance = $lexical;
        $this->relationFacetRegistry = $facets;
    }
    public function semanticRelationGovernance(): ?SemanticRelationGovernanceAdapter { return $this->semanticRelationGovernance; }
    public function lexicalRelationGovernance(): ?DictionaryLexicalRelationGovernanceAdapter { return $this->lexicalRelationGovernance; }
    public function relationFacetRegistry(): ?DictionaryRelationFacetRegistry { return $this->relationFacetRegistry; }
    public function candidates(): WpdbDictionaryCandidateRepository { return $this->candidates; }
    public function mentions(): WpdbDictionaryMentionRepository { return $this->mentions; }
    public function seedPlanner(): DictionarySeedPlanner { return $this->seedPlanner; }
    public function candidateRepository(): WpdbDictionaryCandidateRepository { return $this->candidates; }

    public function resolve(string $term, array $context = [], array $hints = []): array
    {
        return $this->preview($term, 'MCP_RESOLVE', '', $context, $hints);
    }

    public function resolveEntrySense(string $term, array $context = []): array
    {
        if (!$this->entrySenseAvailable()) return ['status' => 'UNAVAILABLE', 'reason' => 'DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE'];
        return (new DictionaryEntrySenseResolver($this->entries, fn (?string $type, ?string $id, ?string $url): ?string => $this->revalidateDelegatedDestination($type, $id, $url), $this->normalizer))->resolve($term, $context);
    }

    public function materializationPlanner(): DictionaryEntryMaterializationPlanner
    {
        return $this->materializationPlanner;
    }

    public function materializationService(): DictionaryEntryMaterializationService
    {
        $audit = new WpdbAuditSink($this->database);
        return new DictionaryEntryMaterializationService(
            $this->concepts,
            $this->entries,
            function (string $key, string $fingerprint): ?array {
                $row = $this->database->get_row($this->database->prepare('SELECT context_json FROM ' . $this->database->prefix . 'nhk_audit_events WHERE event_type=%s AND object_type=%s AND object_key=%s ORDER BY id DESC LIMIT 1', 'DictionaryEntryMaterialization', 'dictionary_materialization', $key), ARRAY_A);
                if (!is_array($row)) return null;
                $context = json_decode((string) ($row['context_json'] ?? ''), true);
                if (!is_array($context) || (string) ($context['fingerprint'] ?? '') !== $fingerprint) return ['fingerprint' => (string) ($context['fingerprint'] ?? ''), 'result' => (array) ($context['result'] ?? [])];
                return ['fingerprint' => $fingerprint, 'result' => (array) ($context['result'] ?? [])];
            },
            function (string $key, string $fingerprint, array $result) use ($audit): void {
                $audit->recordEvent('DictionaryEntryMaterialization', 'dictionary_materialization', $key, function_exists('get_current_user_id') ? (int) get_current_user_id() : null, ['fingerprint' => $fingerprint, 'result' => $result]);
            },
            function (array $event) use ($audit): void {
                $audit->recordEvent('DictionaryEntryMaterializationItem', 'dictionary_materialization', (string) ($event['concept_id'] ?? ''), function_exists('get_current_user_id') ? (int) get_current_user_id() : null, $event);
            },
            fn (): bool => $this->entrySenseAvailable(),
        );
    }

    public function candidate(string $candidateId): ?\NHK\Core\Domain\Dictionary\DictionaryCandidate
    {
        if (!$this->available()) return null;
        return $this->candidates->findById($candidateId);
    }

    public function mentionsForSource(string $sourceKind, string $sourceId): array
    {
        if (!$this->available()) throw new \RuntimeException('DICTIONARY_STORAGE_UNAVAILABLE');
        return $this->mentions->listBySource($sourceKind, $sourceId);
    }

    public function mentionsForCandidate(string $candidateId, int $limit = 100, int $offset = 0): array
    {
        if (!$this->available()) throw new \RuntimeException('DICTIONARY_STORAGE_UNAVAILABLE');
        $candidate = $this->candidates->findById($candidateId);
        return $candidate === null ? [] : $this->mentions->listByCandidate($candidate->normalizedTerm, $candidate->contextHash, max(1, min(100, $limit)) + 1, $offset);
    }

    public function profile(?string $conceptId = null, ?string $slug = null): array
    {
        if (!$this->available()) return ['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE'];
        $prefix = $this->database->prefix;
        $count = static function (object $database, string $table, string $where = '', array $args = []): int {
            $sql = "SELECT COUNT(*) FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');
            return (int) $database->get_var($database->prepare($sql, ...$args));
        };
        $statusCounts = [];
        foreach ([DictionaryConcept::DRAFT, DictionaryConcept::APPROVED, DictionaryConcept::RETIRED] as $status) $statusCounts[$status] = $count($this->database, $prefix . 'nhk_dictionary_concepts', 'status=%s', [$status]);
        $candidateCounts = [];
        foreach ([\NHK\Core\Domain\Dictionary\DictionaryCandidateState::DETECTED, \NHK\Core\Domain\Dictionary\DictionaryCandidateState::NEEDS_REVIEW, \NHK\Core\Domain\Dictionary\DictionaryCandidateState::AMBIGUOUS, \NHK\Core\Domain\Dictionary\DictionaryCandidateState::PROPOSED_NEW, \NHK\Core\Domain\Dictionary\DictionaryCandidateState::DO_NOT_SUGGEST] as $state) $candidateCounts[$state] = $count($this->database, $prefix . 'nhk_dictionary_candidates', 'candidate_state=%s', [$state]);
        $mentionRows = $this->database->get_results("SELECT source_kind,COUNT(*) AS total FROM {$prefix}nhk_dictionary_mentions GROUP BY source_kind", ARRAY_A) ?: [];
        $mentionCounts = [];
        foreach ($mentionRows as $row) $mentionCounts[(string) ($row['source_kind'] ?? '')] = (int) ($row['total'] ?? 0);
        $hub = $this->publicQuery->hub(2000);
        $publicItems = array_values(array_filter((array) ($hub['items'] ?? []), 'is_array'));
        $preview = null;
        $requestedConcept = trim((string) ($conceptId ?? ''));
        if ($requestedConcept !== '') {
            $concept = $this->concepts->findById($requestedConcept);
            if ($concept === null) $preview = ['status' => 'not_found'];
            else {
                foreach ($publicItems as $item) {
                    $senseMatch = false;
                    foreach ((array) ($item['senses'] ?? []) as $sense) {
                        if (is_array($sense) && (string) ($sense['sense_id'] ?? '') === $requestedConcept) {
                            $senseMatch = true;
                            break;
                        }
                    }
                    if ((string) ($item['concept_id'] ?? '') === $requestedConcept || $senseMatch) {
                        $preview = ['status' => 'READY', 'item' => $item];
                        break;
                    }
                }
                if ($preview === null && method_exists($this->entries, 'findDurableForConcept')) {
                    $entry = $this->entries->findDurableForConcept($requestedConcept);
                    $persistedSlug = $entry instanceof \NHK\Core\Domain\Dictionary\LexicalEntry
                        ? trim((string) ($entry->context['public_slug'] ?? ''))
                        : '';
                    $preview = $persistedSlug !== ''
                        ? $this->publicQuery->detail($persistedSlug)
                        : ['status' => 'not_found'];
                }
                $preview ??= ['status' => 'not_found'];
            }
        } elseif (trim((string) ($slug ?? '')) !== '') {
            $preview = $this->publicQuery->detail((string) $slug);
        }
        return [
            'status' => 'available',
            'storage' => ['status' => 'READY', 'migration' => DictionaryMigration015::VERSION],
            'readiness' => $this->readiness() + ['status' => $this->entrySenseAvailable() ? 'READY' : 'COMPATIBILITY'],
            'migration' => ['current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 0)],
            'public_hub' => ['status' => ($hub['status'] ?? '') === 'AVAILABLE' ? 'READY' : 'UNAVAILABLE', 'compatibility_fallback' => !$this->entrySenseAvailable()],
            'owner_revalidation' => true,
            'backfill' => 'DRY_RUN_ONLY',
            'coverage' => ['concepts' => $statusCounts, 'candidates' => $candidateCounts, 'mentions_by_source' => $mentionCounts, 'public_items_returned' => count($publicItems), 'public_items_bounded' => true],
            'public_preview' => $preview,
            'public_hub_preview' => ['status' => $hub['status'] ?? 'UNAVAILABLE', 'canonical_url' => $hub['canonical_url'] ?? '/tu-dien/', 'items' => array_slice($publicItems, 0, 20)],
        ];
    }

    public function mutation(): DictionaryMutationService
    {
        $audit = new WpdbAuditSink($this->database);
        $publicIdentityWriter = new DictionaryEntryPublicIdentityWriter(fn (string $slug, ?string $entryId = null): bool => $this->entries->publicSlugTaken($slug, $entryId));
        return new DictionaryMutationService(
            $this->concepts,
            null,
            function (string $key, string $fingerprint): ?array {
                $row = $this->database->get_row($this->database->prepare('SELECT context_json FROM ' . $this->database->prefix . 'nhk_audit_events WHERE event_type=%s AND object_type=%s AND object_key=%s ORDER BY id DESC LIMIT 1', 'DictionaryMutation', 'dictionary', $key), ARRAY_A);
                if (!is_array($row)) return null;
                $context = json_decode((string) ($row['context_json'] ?? ''), true);
                if (!is_array($context) || (string) ($context['fingerprint'] ?? '') !== $fingerprint) return ['fingerprint' => (string) ($context['fingerprint'] ?? ''), 'result' => (array) ($context['result'] ?? [])];
                return ['fingerprint' => $fingerprint, 'result' => (array) ($context['result'] ?? [])];
            },
            function (string $key, string $fingerprint, array $result) use ($audit): void {
                $audit->recordEvent('DictionaryMutation', 'dictionary', $key, function_exists('get_current_user_id') ? (int) get_current_user_id() : null, ['fingerprint' => $fingerprint, 'result' => $result]);
            },
            null,
            $this->entries,
            function (?string $type, ?string $id, ?string $url): string|bool|null {
                if ($type === 'knowledge' && $id !== null) {
                    $claim = $this->knowledge->findByCanonicalId($id);
                    return $claim instanceof KnowledgeClaim && $claim->active && $claim->isPublic();
                }
                return $this->revalidateDelegatedDestination($type, $id, $url);
            },
            fn (): bool => $this->entrySenseAvailable(),
            $publicIdentityWriter,
            function (): void { $this->invalidateLabelCache(); },
        );
    }

    public function harvester(): DictionaryHarvester { return new DictionaryHarvester($this->planning); }

    public function relationHandoff(): DictionaryRelationHandoff
    {
        return new DictionaryRelationHandoff(
            function (string $type, string $id): ?array {
                if ($this->types->has($type)) { $entity = $this->authority->findByCanonicalId($id); return $entity instanceof AuthorityEntity && $entity->entityType === $type && $entity->active() ? ['id' => $entity->canonicalId, 'revision' => $entity->revision] : null; }
                if ($type === 'knowledge') { $claim = $this->knowledge->findByCanonicalId($id); return $claim !== null && $claim->active ? ['id' => $claim->canonicalId, 'revision' => $claim->revision] : null; }
                return null;
            },
            static function (string $sourceType, string $predicate, string $targetType): bool {
                try {
                    return (new PredicateRegistry())->get($predicate)->allows($sourceType, $targetType);
                } catch (\Throwable) {
                    return false;
                }
            },
        );
    }

    public function detectionLabels(): array
    {
        if ($this->detectionLabels !== null) return $this->detectionLabels;
        $labels = [];
        foreach ($this->concepts->listApproved(2000) as $concept) foreach ($this->concepts->listLabels($concept->conceptId) as $label) {
            if (!$label instanceof DictionaryLabel || !$label->active) continue;
            $labels[$label->normalizedLabel] = $label->label;
        }
        foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) {
            if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
            foreach ($this->entityForms($entity) as $form) $labels[$this->normalizer->normalize($form)] = $form;
        }
        if ($this->entrySenseAvailable()) foreach ($this->entries->listEntries(2000) as $entry) {
            if (!$entry instanceof \NHK\Core\Domain\Dictionary\LexicalEntry || $entry->status !== DictionaryConcept::APPROVED) continue;
            foreach ($this->entries->listForms($entry) as $form) {
                $text = is_object($form) ? (string) ($form->form ?? '') : (string) ($form['form'] ?? '');
                if (trim($text) !== '') $labels[$this->normalizer->normalize($text)] = $text;
            }
        }
        return $this->detectionLabels = array_values(array_filter($labels));
    }

    public function approvedLabels(): array { return $this->detectionLabels(); }
    public function invalidateLabelCache(): void { $this->detectionLabels = null; }

    private function approvedLabelRows(string $term, array $context): array
    {
        $rows = [];
        if ($this->entrySenseAvailable() && method_exists($this->entries, 'findByForm')) {
            foreach ((array) $this->entries->findByForm($term, $context) as $entry) {
                if (!$entry instanceof \NHK\Core\Domain\Dictionary\LexicalEntry || $entry->status !== DictionaryConcept::APPROVED) continue;
                $slug = trim((string) ($entry->context['public_slug'] ?? ''));
                if ($slug === '') continue;
                foreach ((array) $this->entries->listSenses($entry, $context) as $sense) {
                    if (!$sense instanceof DictionaryConcept || !$sense->approved()) continue;
                    $type = null;
                    $id = null;
                    $url = null;
                    if (method_exists($this->entries, 'semanticReference')) {
                        $reference = $this->entries->semanticReference($entry->entryId, $sense->conceptId);
                        if (strtoupper((string) ($reference['status'] ?? 'ABSENT')) !== 'ABSENT') {
                            $type = trim((string) ($reference['type'] ?? '')) ?: null;
                            $id = trim((string) ($reference['id'] ?? '')) ?: null;
                            if ($type !== null || $id !== null) {
                                if ($type === null || $id === null || ($url = $this->revalidateDelegatedDestination($type, $id, null)) === null) continue;
                            }
                        }
                    }
                    if ($type === null || $id === null) {
                        $type = 'dictionary';
                        $id = $sense->conceptId;
                        $url = '/tu-dien/' . $slug . '/';
                    }
                    $rows[] = [
                        'concept_id' => $sense->conceptId,
                        'preferred_label' => $sense->preferredLabel,
                        'destination_type' => $type,
                        'destination_id' => $id,
                        'destination_url' => $url,
                        'label' => $entry->preferredForm,
                        'label_kind' => 'PREFERRED',
                        'locale' => $entry->locale,
                        'context' => $sense->context,
                    ];
                }
            }
            if ($rows !== []) return $rows;
        }
        foreach ($this->concepts->findApprovedByNormalizedLabel($term, $context) as $row) {
            if (!is_array($row)) continue;
            $conceptId = trim((string) ($row['concept_id'] ?? ''));
            if ($conceptId === '') continue;
            $type = trim((string) ($row['destination_type'] ?? ''));
            $id = trim((string) ($row['destination_id'] ?? ''));
            $storedUrl = trim((string) ($row['destination_url'] ?? ''));
            if ($type !== '' || $id !== '' || $storedUrl !== '') {
                $current = $this->revalidateDelegatedDestination($type, $id, $storedUrl);
                if ($current === null) continue;
                $row['destination_url'] = $current;
                $rows[] = $row;
                continue;
            }

            $concept = $this->concepts->findById($conceptId);
            if (!$concept instanceof DictionaryConcept || !$concept->approved()) continue;
            $slug = trim((string) ($concept->context['public_slug'] ?? ''));
            $row['destination_type'] = 'dictionary';
            $row['destination_id'] = $concept->conceptId;
            $row['destination_url'] = $slug !== '' ? '/tu-dien/' . $slug . '/' : null;
            $rows[] = $row;
        }
        return $rows;
    }

    private function revalidateDelegatedDestination(?string $type, ?string $id, ?string $storedUrl): ?string
    {
        $type = trim((string) $type);
        $id = trim((string) $id);
        if ($type === '' || $id === '') return null;

        if ($this->types->has($type)) {
            $entity = $this->authority->findByCanonicalId($id);
            if (!$entity instanceof AuthorityEntity || $entity->entityType !== $type || !$entity->active()) return null;
            $current = $this->routes->path($entity);
            return is_string($current) && trim($current) !== '' ? $current : null;
        }

        if ($type === 'article' && function_exists('get_post')) {
            $postId = (int) preg_replace('/^.*:/', '', $id);
            $post = $postId > 0 ? get_post($postId) : null;
            if (!$post instanceof \WP_Post || $post->post_type !== 'post' || $post->post_status !== 'publish') return null;
            $permalink = function_exists('get_permalink') ? get_permalink($post) : false;
            return is_string($permalink) && trim($permalink) !== '' ? $permalink : null;
        }

        return null;
    }

    private function entityForms(AuthorityEntity $entity): array
    {
        // Lexical equivalents belong to Dictionary Label, never to the
        // Authority payload.  Authority remains the canonical name lookup.
        return [$entity->canonicalName];
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = function_exists('iconv') ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) : $value;
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }

    private function contextHash(array $context): string
    {
        return hash('sha256', json_encode($this->sort($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
