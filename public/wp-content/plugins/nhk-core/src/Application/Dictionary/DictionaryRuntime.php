<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Entity\{EntityMediaProjection, PublicRouteResolver};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Dictionary\DictionaryLabel;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Dictionary\{WpdbDictionaryCandidateRepository, WpdbDictionaryConceptRepository, WpdbDictionaryMentionRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Migration\DictionaryMigration015;

final class DictionaryRuntime
{
    private DictionaryTermNormalizer $normalizer;
    private EntityTypeRegistry $types;
    private WpdbAuthorityRepository $authority;
    private PublicRouteResolver $routes;
    private WpdbDictionaryConceptRepository $concepts;
    private WpdbDictionaryCandidateRepository $candidates;
    private WpdbDictionaryMentionRepository $mentions;
    private DictionaryPlanningService $planning;
    private DictionaryCurationService $curation;
    private DictionaryPublicQuery $publicQuery;
    private ?array $detectionLabels = null;

    public function __construct(private object $database)
    {
        $this->normalizer = new DictionaryTermNormalizer();
        $this->concepts = new WpdbDictionaryConceptRepository($database);
        $this->candidates = new WpdbDictionaryCandidateRepository($database);
        $this->mentions = new WpdbDictionaryMentionRepository($database);
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
        $this->authority = new WpdbAuthorityRepository($database);
        $this->routes = new PublicRouteResolver($this->authority, $this->types);

        $contextHash = fn (array $context): string => $this->contextHash($context);
        $resolver = new DictionaryResolver(
            approvedLabelLookup: fn (string $term, array $context): array => $this->concepts->findApprovedByNormalizedLabel($term, $context),
            entityLookup: function (string $term, array $context): array {
                $out = [];
                foreach ($this->types->all() as $definition) {
                    foreach ($this->authority->listByType($definition->type) as $entity) {
                        if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
                        foreach ($this->entityForms($entity) as $form) {
                            if ($this->normalizer->normalize($form) !== $term) continue;
                            $out[$entity->canonicalId] = [
                                'preferred_label' => $entity->canonicalName,
                                'destination_type' => $entity->entityType,
                                'destination_id' => $entity->canonicalId,
                                'destination_url' => $this->routes->path($entity),
                            ];
                            break;
                        }
                    }
                }
                return array_values($out);
            },
            knowledgeLookup: static fn (string $term, array $context): array => [],
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

        $this->planning = new DictionaryPlanningService(new DictionaryTermDetector($this->normalizer), $resolver, $this->candidates, $this->mentions, new DictionaryLinkPlanner($this->normalizer));
        $this->curation = new DictionaryCurationService($this->candidates, $this->concepts, null, $this->normalizer);
        $mediaProjection = new EntityMediaProjection(new WpdbMediaRepository($database), new WpdbMediaAssetRepository($database), new WpdbMediaUsageRepository($database));
        $this->publicQuery = new DictionaryPublicQuery($this->concepts, static function (string $conceptId) use ($mediaProjection): ?array {
            $projection = $mediaProjection->forEntity('dictionary_concept', $conceptId);
            return is_array($projection['representative'] ?? null) ? $projection['representative'] : null;
        });
    }

    public function available(): bool
    {
        try { return DictionaryMigration015::schemaReady($this->database); }
        catch (\Throwable) { return false; }
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

    public function publicTerms(): array
    {
        if (!$this->available()) return [];
        $items = [];
        foreach ((array) ($this->publicQuery->hub(2000)['items'] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['url'] ?? '')) === '') continue;
            $conceptId = trim((string) ($item['concept_id'] ?? ''));
            foreach ((array) ($item['labels'] ?? []) as $label) {
                if (!is_array($label) || (string) ($label['kind'] ?? '') === DictionaryLabel::HIDDEN) continue;
                $text = trim((string) ($label['label'] ?? ''));
                if ($conceptId === '' || $text === '') continue;
                $items[$conceptId . "\0" . $this->normalizer->normalize($text)] = ['concept_id' => $conceptId, 'label' => $text, 'url' => (string) $item['url']];
            }
        }
        foreach ($this->types->all() as $definition) {
            foreach ($this->authority->listByType($definition->type) as $entity) {
                if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
                $url = $this->routes->path($entity);
                if ($url === null) continue;
                foreach ($this->entityForms($entity) as $form) {
                    $key = 'entity:' . $entity->canonicalId . "\0" . $this->normalizer->normalize($form);
                    $items[$key] = ['concept_id' => 'entity:' . $entity->canonicalId, 'label' => $form, 'url' => $url];
                }
            }
        }
        return array_values($items);
    }

    public function curation(): DictionaryCurationService { return $this->curation; }
    public function publicQuery(): DictionaryPublicQuery { return $this->publicQuery; }
    public function concepts(): WpdbDictionaryConceptRepository { return $this->concepts; }
    public function candidates(): WpdbDictionaryCandidateRepository { return $this->candidates; }
    public function mentions(): WpdbDictionaryMentionRepository { return $this->mentions; }

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
        return $this->detectionLabels = array_values(array_filter($labels));
    }

    public function approvedLabels(): array { return $this->detectionLabels(); }
    public function invalidateLabelCache(): void { $this->detectionLabels = null; }

    private function entityForms(AuthorityEntity $entity): array
    {
        $forms = [$entity->canonicalName];
        foreach ((array) ($entity->payload['aliases'] ?? []) as $alias) if (is_string($alias) && trim($alias) !== '') $forms[] = trim($alias);
        return array_values(array_unique($forms));
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
