<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\RelatedSemanticQuery;
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use NHK\Core\Domain\Video\Video;

/**
 * Detail-only read model. It assembles public projections without becoming a
 * semantic store or changing the legacy RelatedContentQuery contract.
 */
final class SemanticDossierQuery
{
    /** @var array<string,string> */
    private const GROUPS = [
        'brand' => 'brands',
        'model' => 'models',
        'variant' => 'variants',
        'movement' => 'movements',
        'music' => 'music',
        'component' => 'components',
        'classification' => 'classifications',
        'specimen' => 'specimens',
        'product' => 'products',
        'wp_post' => 'articles',
        'media' => 'media',
        'video' => 'videos',
    ];

    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private PublicIdentityContract $identity,
        private PublicEntityEligibilityPolicy $eligibility,
        private PublicRouteResolver $routes,
        private RelatedSemanticQuery $relations,
        private EntityKnowledgeProjection $knowledge,
        private EntityMediaProjection $mediaProjection,
        private MediaRepository $media,
        private VideoRepository $videos,
    ) {}

    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity): array
    {
        if (!$this->types->has($entity->entityType) || !$entity->active() || !$this->eligibility->evaluate($entity)->eligible) {
            return $this->unavailable('ENTITY_NOT_PUBLIC');
        }
        $path = $this->routes->path($entity);
        if ($path === null) return $this->unavailable('PUBLIC_ROUTE_UNAVAILABLE');

        $knowledge = $this->knowledge->forSubject($entity->canonicalId);
        $media = $this->mediaProjection->forEntity($entity->entityType, $entity->canonicalId);
        $relationResult = $this->relations->query(new NodeReference($entity->entityType, $entity->canonicalId), array_keys(self::GROUPS), 2, 100);
        $sections = $this->relationSections($relationResult);
        $warnings = is_array($knowledge['warnings'] ?? null) ? array_values($knowledge['warnings']) : [];
        if (($relationResult['status'] ?? '') === 'unavailable') $warnings[] = 'GRAPH_UNAVAILABLE';
        if (($relationResult['status'] ?? '') === 'unsupported') $warnings[] = 'GRAPH_QUERY_UNSUPPORTED';

        $payload = $this->identity->payload($entity);
        foreach (array_keys($payload) as $key) if (str_ends_with((string) $key, '_uuid')) unset($payload[$key]);
        $publicUrl = (new PublicSeoProjection())->project([
            'path' => $path,
            'eligible' => true,
            'readiness' => SeoReadinessResult::READY,
            'canonical_url' => $path,
            'public_eligible' => true,
        ], ['type' => 'Entity']);

        $gallery = [];
        foreach ((array) ($media['gallery'] ?? []) as $item) {
            $safe = $this->safeMedia($item);
            if ($safe !== null) $gallery[] = $safe;
        }
        $primary = $this->safeMedia(is_array($media['representative'] ?? null) ? $media['representative'] : null);

        return [
            'status' => 'AVAILABLE',
            'identity' => [
                'type' => $entity->entityType,
                'name' => $entity->canonicalName,
                'payload' => $payload,
                'url' => $publicUrl['internal_link'] ?? $path,
            ],
            'seo_projection' => $publicUrl,
            'primary_media' => $primary,
            'media_gallery' => $gallery,
            'knowledge' => $knowledge,
            'relation_sections' => $sections,
            'coverage' => [
                'relation_count' => array_sum(array_map('count', $sections)),
                'knowledge_claim_count' => (int) ($knowledge['claim_count'] ?? 0),
                'public_evidence_count' => (int) ($knowledge['evidence_count'] ?? 0),
                'media_count' => count($gallery),
                'video_count' => count($sections['videos'] ?? []),
                'article_count' => count($sections['articles'] ?? []),
            ],
            'warnings' => array_values(array_unique($warnings)),
            'availability' => [
                'graph' => strtoupper((string) ($relationResult['status'] ?? 'unavailable')),
                'knowledge' => (string) ($knowledge['status'] ?? 'UNAVAILABLE'),
            ],
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function relationSections(array $relationResult): array
    {
        $sections = [];
        if (($relationResult['status'] ?? '') !== 'available') return $sections;
        foreach ((array) ($relationResult['items'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $type = (string) ($candidate['target_entity_type'] ?? '');
            $group = self::GROUPS[$type] ?? null;
            if ($group === null) continue;
            $item = $this->resolveCandidate($candidate);
            if ($item === null) continue;
            $sections[$group][] = $item;
        }
        foreach ($sections as &$items) {
            usort($items, static function(array $a, array $b): int {
                $ak = (($a['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1);
                $bk = (($b['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1);
                return [$ak, (int) ($a['origin']['hop_count'] ?? 99), (string) ($a['title'] ?? '')] <=> [$bk, (int) ($b['origin']['hop_count'] ?? 99), (string) ($b['title'] ?? '')];
            });
        }
        unset($items);
        return $sections;
    }

    /** @return array<string,mixed>|null */
    private function resolveCandidate(array $candidate): ?array
    {
        $type = (string) ($candidate['target_entity_type'] ?? '');
        $id = (string) ($candidate['target_entity_id'] ?? '');
        $origin = $this->readerOrigin($candidate);

        if ($this->types->has($type)) {
            $entity = $this->authority->findByCanonicalId($id);
            if (!$entity instanceof AuthorityEntity || $entity->entityType !== $type || !$entity->active() || !$this->eligibility->evaluate($entity)->eligible) return null;
            $path = $this->routes->path($entity);
            if ($path === null) return null;
            $url = (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'readiness' => SeoReadinessResult::READY, 'canonical_url' => $path, 'public_eligible' => true], ['type' => 'Entity'])['internal_link'] ?? null;
            if (!is_string($url) || $url === '') return null;
            return ['type' => $type, 'title' => $entity->canonicalName, 'url' => $url, 'origin' => $origin];
        }

        if ($type === 'media') {
            $media = $this->media->findByCanonicalId($id);
            if (!$media instanceof Media || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
            return ['type' => 'media', 'title' => $media->canonicalName, 'origin' => $origin];
        }

        if ($type === 'video') {
            $video = $this->videos->findByCanonicalId($id);
            if (!$video instanceof Video || !$video->active || !$video->hasValidPublicReference()) return null;
            $metadata = is_array($video->metadata) ? $video->metadata : [];
            $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
            $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
            $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title;
            $url = (new PublicSeoProjection())->project((new VideoUrlPolicy())->project($video, new VideoPublicContextSelector()), ['type' => 'VideoObject'])['internal_link'] ?? null;
            if (!is_string($url) || $url === '') return null;
            $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? trim((string) ($source['thumbnail_urls'][0] ?? '')) : '';
            if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') $thumbnail = '';
            return ['type' => 'video', 'title' => $title, 'url' => $url, 'thumbnail_url' => $thumbnail !== '' ? $thumbnail : null, 'origin' => $origin];
        }

        if ($type === 'wp_post' && function_exists('get_post') && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $id, $match) === 1) {
            $post = get_post((int) $match[1]);
            if (!$post instanceof \WP_Post || get_post_status($post) !== 'publish') return null;
            return ['type' => 'wp_post', 'title' => get_the_title($post), 'url' => get_permalink($post), 'origin' => $origin];
        }

        return null;
    }

    /** @return array{kind:string,hop_count:int,predicates:list<string>,via_types:list<string>} */
    private function readerOrigin(array $candidate): array
    {
        $path = is_array($candidate['best_path'] ?? null) ? $candidate['best_path'] : [];
        $predicates = [];
        $viaTypes = [];
        foreach ($path as $index => $hop) {
            if (!is_array($hop)) continue;
            $predicate = trim((string) ($hop['predicate'] ?? ''));
            if ($predicate !== '') $predicates[] = $predicate;
            if ($index < count($path) - 1) {
                $target = (string) ($hop['target'] ?? '');
                $type = strstr($target, ':', true);
                if (is_string($type) && $type !== '') $viaTypes[] = $type;
            }
        }
        return [
            'kind' => (string) ($candidate['relationship_class'] ?? 'DERIVED'),
            'hop_count' => (int) ($candidate['hop_count'] ?? count($path)),
            'predicates' => $predicates,
            'via_types' => $viaTypes,
        ];
    }

    /** @return array<string,mixed>|null */
    private function safeMedia(?array $item): ?array
    {
        if ($item === null || trim((string) ($item['url'] ?? '')) === '') return null;
        return [
            'url' => (string) $item['url'],
            'alt' => (string) ($item['alt'] ?? ''),
            'role' => (string) ($item['role'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'identity' => null,
            'seo_projection' => null,
            'primary_media' => null,
            'media_gallery' => [],
            'knowledge' => ['status' => 'UNAVAILABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0],
            'relation_sections' => [],
            'coverage' => [],
            'warnings' => [$reason],
            'availability' => ['graph' => 'UNAVAILABLE', 'knowledge' => 'UNAVAILABLE'],
        ];
    }
}
