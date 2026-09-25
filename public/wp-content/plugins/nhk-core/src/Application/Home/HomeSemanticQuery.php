<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use NHK\Core\Application\Entity\{EntityMediaProjection, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\VideoFrontendProjection;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Presentation\{HomepageVisualPolicy, LatestFirstOrder, PublicNavigationDefinition};
use NHK\Core\Contracts\Home\BoundedLatestFeedReader;

final class HomeSemanticQuery
{
    public const LATEST_VISIBLE_LIMIT = 12;
    public const LATEST_CANDIDATE_CAP = 24;
    /** Request-scope read memoization only; no persistent/public cache is introduced here. */
    private ?array $mediaItemsMemo = null;
    private ?array $videoItemsMemo = null;
    /** @var array<string,list<\NHK\Core\Domain\Authority\AuthorityEntity>> */
    private array $authorityItemsMemo = [];
    /** @var array<string,array<string,mixed>|null> */
    private array $mediaVisualMemo = [];
    /** @var array<string,array<string,mixed>|null> */
    private array $entityDetailMemo = [];
    private ?HomepageVisualPolicy $visualPolicy = null;
    private ?VideoFrontendProjection $videoProjection = null;

    public function __construct(
        private AuthorityRepository $authority,
        private MediaRepository $media,
        private VideoRepository $videos,
        private EntityTypeRegistry $types,
        private ?MigrationStatus $status = null,
        private ?PublicRouteResolver $routes = null,
        private ?PublicEntityCollectionQuery $collection = null,
        private ?PublicMediaGalleryQuery $gallery = null,
        private ?KnowledgePageQuery $knowledge = null,
        private ?KnowledgeRepository $claims = null,
        private ?EntityMediaProjection $videoMedia = null,
    ) {}

    public function extend(array $modules): array
    {
        foreach (['entities','media','videos','knowledge','hubs','clock_groups','explore_next','latest_feed'] as $key) if (!isset($modules[$key]) || !is_array($modules[$key])) $modules[$key] = [];
        foreach (['clock_groups_total', 'media_total', 'videos_total'] as $key) if (!isset($modules[$key])) $modules[$key] = 0;

        if ($this->ready('authority')) {
            $clockGroups = $this->collection()->archiveProfile('clock_type', 1, 6);
            if ((int) ($clockGroups['total'] ?? 0) > 0) {
                $modules['clock_groups_total'] = (int) $clockGroups['total'];
                $modules['hubs'][] = ['type' => 'clock_type', 'label' => 'Nhóm đồng hồ', 'total' => (int) $clockGroups['total'], 'url' => $this->routes()->archivePathForProfile('clock_type')];
                foreach ((array) ($clockGroups['items'] ?? []) as $item) {
                    if (!is_array($item) || ($item['presentation_readiness']['status'] ?? '') !== 'READY') continue;
                    $modules['clock_groups'][] = [
                        'type' => 'clock_type',
                        'title' => (string) ($item['name'] ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                        'url' => (string) ($item['url'] ?? ''),
                        'image_url' => $item['media']['representative']['url'] ?? null,
                        'image_alt' => $item['media']['representative']['alt'] ?? ($item['name'] ?? ''),
                    ];
                }
            }
            foreach ($this->types->all() as $definition) {
                $archive = $this->collection()->archive($definition->type, 1, 6);
                $archivePath = $this->routes()->archivePath($definition->type);
                if ($archivePath !== null && (int) ($archive['total'] ?? 0) > 0) $modules['hubs'][] = ['type' => $definition->type, 'total' => (int) $archive['total'], 'url' => $archivePath];
                foreach ($archive['items'] as $item) {
                    $modules['entities'][] = [
                        'type' => $item['type'],
                        'title' => $item['name'],
                        'url' => (new PublicSeoProjection())->project(['path' => $item['url'], 'eligible' => true, 'readiness' => SeoReadinessResult::READY, 'canonical_url' => $item['url'], 'public_eligible' => true], ['type' => 'Entity'])['internal_link'],
                        'image_url' => $item['media']['representative']['url'] ?? null,
                        'image_alt' => $item['media']['representative']['alt'] ?? $item['name'],
                    ];
                    if (count($modules['entities']) >= 8) break 2;
                }
            }
        }

        if ($this->ready('media') && $this->gallery !== null) {
            $modules['media'] = [];
            $modules['media_total'] = 0;
            $mediaItems = $this->mediaItems();
            foreach ($mediaItems as $item) {
                if (!$item->active || $item->readiness !== 'ready' || $item->isSystemPlaceholder()) continue;
                $visual = $this->mediaVisual($item->canonicalId);
                if (!is_array($visual) || trim((string) ($visual['image_url'] ?? '')) === '' || ($visual['has_real_image'] ?? false) !== true) continue;
                $modules['media_total']++;
                if (count($modules['media']) < 8) $modules['media'][] = $visual;
            }
            $manualIds = function_exists('get_option') ? (array) get_option('nhk_v3_home_hero_media_ids', []) : [];
            if (function_exists('apply_filters')) $manualIds = apply_filters('nhk_v3_home_hero_media_ids', $manualIds);
            $heroCandidates = [];
            foreach ($mediaItems as $item) {
                $visual = $this->mediaVisual($item->canonicalId);
                if (!is_array($visual) || trim((string) ($visual['image_url'] ?? '')) === '' || ($visual['has_real_image'] ?? false) !== true) continue;
                $visual['_canonical_id'] = $item->canonicalId;
                $heroCandidates[] = $visual;
            }
            $modules['hero_media'] = (new HomeHeroMediaSelector())->select((array) $manualIds, $heroCandidates, 1, 1);
        }

        if ($this->ready('video')) {
            $modules['videos'] = [];
            $modules['videos_total'] = 0;
            $videoItems = $this->videoItems();
            foreach ($videoItems as $item) {
                $projection = $this->videoFrontendProjection()->project($item);
                if (!$item->active || !$item->hasValidPublicReference() || ($projection['frontend_available'] ?? false) !== true) continue;
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $source = is_array($metadata['source_snapshot'] ?? null)
                    ? $metadata['source_snapshot']
                    : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
                if (isset($source['availability']) && !in_array($source['availability'], ['available','unknown'], true)) continue;
                $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                $title = trim((string) ($editorial['title'] ?? '')) ?: ($item->title ?: 'Video');
                $thumbnail = is_array($projection['item']['thumbnail'] ?? null) ? $projection['item']['thumbnail'] : [];
                $visual = $this->visualPolicy()->resolve(['type' => 'video', 'image_url' => $thumbnail['url'] ?? null, 'width' => $thumbnail['width'] ?? null, 'height' => $thumbnail['height'] ?? null]);
                $modules['videos_total']++;
                if (count($modules['videos']) >= 6) continue;
                $modules['videos'][] = [
                    'canonical_id' => $item->canonicalId,
                    'title' => (string) (($projection['item']['title'] ?? '') ?: $title),
                    'platform' => $item->platform,
                    'url' => $projection['item']['public_url'] ?? null,
                    'thumbnail_url' => $visual['image_url'] ?? null,
                    'thumbnail' => $visual['visual_kind'] === 'image' ? $thumbnail : [],
                    'visual_kind' => $visual['visual_kind'],
                    'published_at' => $this->videoPublishedAt($item),
                    'subject_context' => trim((string) ($editorial['subject_context'] ?? $editorial['summary'] ?? '')),
                ];
            }
        }

        if ($this->ready('knowledge') && $this->knowledge !== null) {
            foreach (($this->knowledge->archive(1, 6)['items'] ?? []) as $item) {
                if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') continue;
                $modules['knowledge'][] = ['text' => (string) $item['text'], 'type' => (string) ($item['type'] ?? '')];
            }
        }
        $modules['latest_feed'] = $this->latestFeed($modules);
        $modules['hubs'] = PublicNavigationDefinition::sortHubItems($modules['hubs']);
        return $modules;
    }

    /** @return list<array<string,mixed>> */
    private function latestFeed(array $modules): array
    {
        $items = [];
        foreach ($this->latestCandidates($this->videos, fn (): array => $this->videoItems()) as $video) {
            $projection = $this->videoFrontendProjection()->project($video);
            if (!$this->ready('video') || !$video->active || ($projection['frontend_available'] ?? false) !== true) continue;
            $metadata = is_array($video->metadata) ? $video->metadata : [];
            $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
            $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
            $url = $projection['item']['public_url'] ?? null;
            if (!is_string($url) || $url === '' || (($source['availability'] ?? 'unknown') !== 'available')) continue;
            $thumbnail = is_array($projection['item']['thumbnail'] ?? null) ? $projection['item']['thumbnail'] : [];
            $items[] = $this->feedItem('video', 'Video', (string) (($editorial['title'] ?? '') ?: $video->title ?: 'Video'), $url, $this->videoPublishedAt($video), $video->createdAt, (string) ($editorial['summary'] ?? ''), $thumbnail['url'] ?? null, $thumbnail['width'] ?? null, $thumbnail['height'] ?? null, $video->canonicalId);
        }
        foreach ($this->latestCandidates($this->media, fn (): array => $this->mediaItems()) as $media) {
            if (!$this->ready('media') || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) continue;
            $visual = $this->mediaVisual($media->canonicalId);
            $visual = is_array($visual) ? $visual : [];
            $items[] = $this->feedItem('media', 'Ảnh', $media->canonicalName, $visual['article_url'] ?? (function_exists('home_url') ? \home_url('/thu-vien/') : '/thu-vien/'), null, $media->createdAt, (string) ($visual['summary'] ?? 'Ảnh tư liệu trong kho hình ảnh NHK.'), $visual['image_url'] ?? null, $visual['width'] ?? null, $visual['height'] ?? null, $media->canonicalId, $visual);
        }
        if ($this->claims !== null && $this->ready('knowledge')) foreach ($this->latestCandidates($this->claims, fn (): array => $this->claims?->list() ?? []) as $claim) {
            if (!$claim->active || !$claim->isPublic()) continue;
            $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
            $subjectId = trim((string) ($metadata['subject_uuid'] ?? $metadata['subject_id'] ?? $metadata['canonical_subject_uuid'] ?? ''));
            $url = $subjectId !== '' ? $this->publicEntityUrl($subjectId) : null;
            if ($url === null) continue;
            $items[] = $this->feedItem('knowledge', 'Tri thức', $this->shorten($claim->claimText, 16), $url, null, $claim->createdAt, $claim->claimText, null, null, null, $claim->canonicalId);
        }
        if ($this->ready('authority')) {
            $authorityCandidates = $this->authority instanceof BoundedLatestFeedReader
                ? $this->authority->latestFeedCandidates(self::LATEST_CANDIDATE_CAP)
                : array_merge([], ...array_map(fn ($definition): array => $this->authorityItems($definition->type), $this->types->all()));
            foreach ($authorityCandidates as $entity) {
            if (!$entity->active() || $entity->createdAt === null) continue;
            $detail = $this->entityDetail($entity);
            if (!is_array($detail) || trim((string) ($detail['url'] ?? '')) === '') continue;
            $representative = $detail['media']['representative'] ?? [];
            $labels = ['brand' => 'Thương hiệu', 'model' => 'Mẫu', 'variant' => 'Mẫu', 'movement' => 'Bộ máy', 'music' => 'Bản nhạc', 'classification' => 'Phân loại', 'component' => 'Linh kiện', 'specimen' => 'Hiện vật', 'product' => 'Sản phẩm'];
                $items[] = $this->feedItem($entity->entityType, $labels[$entity->entityType] ?? 'Hồ sơ', $entity->canonicalName, (string) $detail['url'], null, $entity->createdAt, (string) ($detail['description'] ?? ''), $representative['url'] ?? null, $representative['width'] ?? null, $representative['height'] ?? null, $entity->canonicalId, $representative);
            }
        }
        $items = LatestFirstOrder::sort($items, static fn (array $item): ?string => (string) ($item['timestamp'] ?? ''), static fn (array $item): ?string => (string) ($item['created_at'] ?? ''), static fn (array $item): string => (string) ($item['tie_breaker'] ?? ''));
        $deduped = [];
        $seen = [];
        foreach ($items as $item) {
            $identity = trim((string) ($item['tie_breaker'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if (($identity === '' && $url === '') || ($identity !== '' && isset($seen['id:' . $identity])) || ($identity === '' && $url !== '' && isset($seen['url:' . $url]))) continue;
            if ($identity !== '') $seen['id:' . $identity] = true;
            if ($identity === '' && $url !== '') $seen['url:' . $url] = true;
            $deduped[] = $item;
        }
        return array_slice($deduped, 0, self::LATEST_VISIBLE_LIMIT);
    }

    /** @return list<object> */
    private function latestCandidates(object $repository, callable $fallback): array
    {
        if ($repository instanceof BoundedLatestFeedReader) return $repository->latestFeedCandidates(self::LATEST_CANDIDATE_CAP);
        return array_slice($fallback(), 0, self::LATEST_CANDIDATE_CAP);
    }

    private function publicEntityUrl(string $id): ?string
    {
        $entity = $this->authority->findByCanonicalId($id);
        if ($entity === null || !$entity->active()) return null;
        $detail = $this->entityDetail($entity);
        $url = is_array($detail) ? trim((string) ($detail['url'] ?? '')) : '';
        return $url !== '' ? $url : null;
    }

    /** @return list<\NHK\Core\Domain\Media\Media> */
    private function mediaItems(): array
    {
        if ($this->mediaItemsMemo === null) {
            $this->mediaItemsMemo = LatestFirstOrder::sort($this->media->list(), static fn (\NHK\Core\Domain\Media\Media $item): ?string => null, static fn (\NHK\Core\Domain\Media\Media $item): ?string => $item->createdAt, static fn (\NHK\Core\Domain\Media\Media $item): string => $item->canonicalId);
        }
        return $this->mediaItemsMemo;
    }

    /** @return list<\NHK\Core\Domain\Video\Video> */
    private function videoItems(): array
    {
        if ($this->videoItemsMemo === null) {
            $this->videoItemsMemo = LatestFirstOrder::sort($this->videos->list(), fn (\NHK\Core\Domain\Video\Video $item): ?string => $this->videoPublishedAt($item), fn (\NHK\Core\Domain\Video\Video $item): ?string => $item->createdAt, fn (\NHK\Core\Domain\Video\Video $item): string => $item->canonicalId);
        }
        return $this->videoItemsMemo;
    }

    /** @return list<\NHK\Core\Domain\Authority\AuthorityEntity> */
    private function authorityItems(string $type): array
    {
        return $this->authorityItemsMemo[$type] ??= $this->authority->listByType($type, true);
    }

    /** @return array<string,mixed>|null */
    private function mediaVisual(string $id): ?array
    {
        if (array_key_exists($id, $this->mediaVisualMemo)) return $this->mediaVisualMemo[$id];
        return $this->mediaVisualMemo[$id] = $this->gallery?->forMedia($id);
    }

    /** @return array<string,mixed>|null */
    private function entityDetail(\NHK\Core\Domain\Authority\AuthorityEntity $entity): ?array
    {
        if (array_key_exists($entity->canonicalId, $this->entityDetailMemo)) return $this->entityDetailMemo[$entity->canonicalId];
        return $this->entityDetailMemo[$entity->canonicalId] = $this->collection()->detailForEntity($entity);
    }

    private function feedItem(string $type, string $label, string $title, string $url, ?string $publishedAt, ?string $createdAt, string $summary, mixed $image, mixed $width, mixed $height, string $tieBreaker, array $visual = []): array
    {
        $plainSummary = function_exists('wp_strip_all_tags') ? \wp_strip_all_tags($summary) : strip_tags($summary);
        $normalizedWidth = is_numeric($width) ? (int) $width : null;
        $normalizedHeight = is_numeric($height) ? (int) $height : null;
        $orientation = $normalizedWidth !== null && $normalizedHeight !== null && $normalizedWidth > 0 && $normalizedHeight > 0
            ? ($normalizedHeight > $normalizedWidth ? 'portrait' : ($normalizedWidth === $normalizedHeight ? 'square' : 'landscape'))
            : 'unknown';
        $item = ['type' => $type, 'label' => $label, 'title' => trim($title), 'url' => $url, 'timestamp' => $publishedAt, 'created_at' => $createdAt, 'summary' => $this->shorten($plainSummary, 24), 'image_url' => is_string($image) && $image !== '' ? $image : null, 'orientation' => $orientation, 'width' => $normalizedWidth, 'height' => $normalizedHeight, 'tie_breaker' => $tieBreaker];
        $item = array_merge($item, $visual);
        if (!isset($item['visual_kind'])) $item = $this->visualPolicy()->resolve($item);
        return $item;
    }

    private function shorten(string $value, int $words): string
    {
        if (function_exists('wp_trim_words')) return (string) \wp_trim_words($value, $words);
        $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($parts) > $words ? implode(' ', array_slice($parts, 0, $words)) . '…' : trim($value);
    }

    private function ready(string $domain): bool
    {
        if (!$this->status) return true;
        return match ($domain) {
            'authority' => $this->status->authorityStorageReady(),
            'media' => $this->status->mediaStorageReady(),
            'video' => $this->status->videoStorageReady(),
            'knowledge' => $this->status->knowledgeStorageReady(),
            default => false,
        };
    }

    private function routes(): PublicRouteResolver { return $this->routes ??= new PublicRouteResolver($this->authority, $this->types); }

    private function videoFrontendProjection(): VideoFrontendProjection
    {
        return $this->videoProjection ??= new VideoFrontendProjection(
            null,
            $this->videoMedia === null
                ? null
                : fn (\NHK\Core\Domain\Video\Video $video, array $source): ?array => $this->videoMedia?->representativeForEntity('video', $video->canonicalId),
        );
    }
    private function visualPolicy(): HomepageVisualPolicy { return $this->visualPolicy ??= new HomepageVisualPolicy(); }
    private function collection(): PublicEntityCollectionQuery
    {
        return $this->collection ??= new PublicEntityCollectionQuery($this->authority, $this->types, new PublicIdentityContract($this->types), new PublicEntityEligibilityPolicy($this->authority, $this->types, $this->routes()), $this->routes());
    }

    private function videoPublishedAt(\NHK\Core\Domain\Video\Video $video): ?string
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
        return isset($source['published_at']) && is_string($source['published_at']) ? $source['published_at'] : null;
    }
}
