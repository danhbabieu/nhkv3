<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\RelatedSemanticQuery;
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
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
        private ?\Closure $postProjector = null,
        private ?PublicMediaGalleryQuery $mediaGallery = null,
        private ?SemanticProfileComposer $profileComposer = null,
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
        $warnings = $this->relationWarnings($relationResult, $warnings);

        $payload = $this->identity->payload($entity);
        foreach (array_keys($payload) as $key) if (str_ends_with((string) $key, '_uuid')) unset($payload[$key]);
        $publicUrl = (new PublicSeoProjection())->project([
            'path' => $path,
            'eligible' => true,
            'readiness' => SeoReadinessResult::READY,
            'canonical_url' => $path,
            'public_eligible' => true,
        ], ['type' => 'Entity']);

        [$primary, $gallery] = $this->mediaPacket($media);
        $dossier = [
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
            'coverage' => $this->coverage($sections, $gallery, (int) ($knowledge['claim_count'] ?? 0), (int) ($knowledge['evidence_count'] ?? 0)),
            'warnings' => array_values(array_unique($warnings)),
            'availability' => [
                'graph' => strtoupper((string) ($relationResult['status'] ?? 'unavailable')),
                'knowledge' => (string) ($knowledge['status'] ?? 'UNAVAILABLE'),
            ],
        ];
        $dossier['profile'] = ($this->profileComposer ?? new SemanticProfileComposer())->compose($entity->entityType, $dossier);
        return $dossier;
    }

    /** @return array<string,mixed> */
    public function forPost(int $postId): array
    {
        if ($postId < 1) return $this->unavailable('ARTICLE_NOT_PUBLIC');
        $post = $this->projectPost($postId);
        if ($post === null) return $this->unavailable('ARTICLE_NOT_PUBLIC');
        $blogId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        $endpointKey = $blogId . ':' . $postId;
        $media = $this->mediaProjection->forEntity('wp_post', $endpointKey);
        $relationResult = $this->relations->query(new NodeReference('wp_post', $endpointKey), array_keys(self::GROUPS), 2, 100);
        $sections = $this->relationSections($relationResult);
        [$primary, $gallery] = $this->mediaPacket($media);
        $warnings = $this->relationWarnings($relationResult, []);

        return [
            'status' => 'AVAILABLE',
            'identity' => [
                'type' => 'wp_post',
                'title' => (string) ($post['title'] ?? ''),
                'url' => (string) ($post['url'] ?? ''),
                'excerpt' => (string) ($post['excerpt'] ?? ''),
            ],
            // Native WordPress owns Article canonical/permalink truth.
            'seo_projection' => null,
            'primary_media' => $primary,
            'media_gallery' => $gallery,
            'knowledge' => ['status' => 'NOT_APPLICABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0],
            'relation_sections' => $sections,
            'coverage' => $this->coverage($sections, $gallery, 0, 0),
            'warnings' => array_values(array_unique($warnings)),
            'availability' => [
                'graph' => strtoupper((string) ($relationResult['status'] ?? 'unavailable')),
                'knowledge' => 'NOT_APPLICABLE',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function forVideo(Video $video): array
    {
        if (!$video->active || !$video->hasValidPublicReference()) return $this->unavailable('VIDEO_NOT_PUBLIC');
        $route = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        $seo = (new PublicSeoProjection())->project($route, ['type' => 'VideoObject']);
        $url = $seo['internal_link'] ?? null;
        if (!is_string($url) || $url === '') return $this->unavailable('PUBLIC_ROUTE_UNAVAILABLE');

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title;
        $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? trim((string) ($source['thumbnail_urls'][0] ?? '')) : '';
        if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') $thumbnail = '';

        $media = $this->mediaProjection->forEntity('video', $video->canonicalId);
        [$primary, $gallery] = $this->mediaPacket($media);
        $relationResult = $this->relations->query(new NodeReference('video', $video->canonicalId), array_keys(self::GROUPS), 2, 100);
        $sections = $this->relationSections($relationResult);
        $warnings = $this->relationWarnings($relationResult, []);

        return [
            'status' => 'AVAILABLE',
            'identity' => [
                'type' => 'video',
                'title' => $title,
                'url' => $url,
                'source_url' => $video->canonicalUrl,
                'thumbnail_url' => $thumbnail !== '' ? $thumbnail : null,
            ],
            'seo_projection' => $seo,
            'primary_media' => $primary,
            'media_gallery' => $gallery,
            'knowledge' => ['status' => 'NOT_APPLICABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0],
            'relation_sections' => $sections,
            'coverage' => $this->coverage($sections, $gallery, 0, 0),
            'warnings' => array_values(array_unique($warnings)),
            'availability' => [
                'graph' => strtoupper((string) ($relationResult['status'] ?? 'unavailable')),
                'knowledge' => 'NOT_APPLICABLE',
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
            $value = ['type' => 'media', 'title' => $media->canonicalName, 'origin' => $origin];
            $visual = $this->mediaGallery?->forMedia($media->canonicalId);
            if (is_array($visual)) {
                $value['image_url'] = $visual['image_url'] ?? null;
                $value['alt'] = $visual['alt'] ?? $media->canonicalName;
                $value['width'] = $visual['width'] ?? null;
                $value['height'] = $visual['height'] ?? null;
            }
            return $value;
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

        if ($type === 'wp_post' && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $id, $match) === 1) {
            $post = $this->projectPost((int) $match[1]);
            if ($post === null || trim((string) ($post['url'] ?? '')) === '') return null;
            return ['type' => 'wp_post', 'title' => (string) ($post['title'] ?? ''), 'url' => (string) $post['url'], 'origin' => $origin];
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

    /** @return array{0:?array<string,mixed>,1:list<array<string,mixed>>} */
    private function mediaPacket(array $media): array
    {
        $gallery = [];
        foreach ((array) ($media['gallery'] ?? []) as $item) {
            $safe = $this->safeMedia(is_array($item) ? $item : null);
            if ($safe !== null) $gallery[] = $safe;
        }
        $primary = $this->safeMedia(is_array($media['representative'] ?? null) ? $media['representative'] : null);
        return [$primary, $gallery];
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

    /** @return array<string,mixed>|null */
    private function projectPost(int $postId): ?array
    {
        if ($this->postProjector !== null) {
            $result = ($this->postProjector)($postId);
            return is_array($result) ? $result : null;
        }
        if (!function_exists('get_post') || !function_exists('get_post_status') || !function_exists('get_permalink') || !function_exists('get_the_title')) return null;
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || get_post_status($post) !== 'publish') return null;
        return [
            'title' => (string) get_the_title($post),
            'url' => (string) get_permalink($post),
            'excerpt' => function_exists('get_the_excerpt') ? (string) get_the_excerpt($post) : '',
        ];
    }

    /** @param list<string> $warnings @return list<string> */
    private function relationWarnings(array $relationResult, array $warnings): array
    {
        if (($relationResult['status'] ?? '') === 'unavailable') $warnings[] = 'GRAPH_UNAVAILABLE';
        if (($relationResult['status'] ?? '') === 'unsupported') $warnings[] = 'GRAPH_QUERY_UNSUPPORTED';
        return $warnings;
    }

    /** @return array<string,int> */
    private function coverage(array $sections, array $gallery, int $knowledgeClaims, int $evidenceCount): array
    {
        return [
            'relation_count' => array_sum(array_map('count', $sections)),
            'knowledge_claim_count' => $knowledgeClaims,
            'public_evidence_count' => $evidenceCount,
            'media_count' => count($gallery),
            'video_count' => count($sections['videos'] ?? []),
            'article_count' => count($sections['articles'] ?? []),
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
