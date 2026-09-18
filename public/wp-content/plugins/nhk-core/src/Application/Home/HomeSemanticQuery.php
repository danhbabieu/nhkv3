<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Presentation\{LatestFirstOrder, PublicNavigationDefinition};

final class HomeSemanticQuery
{
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
    ) {}

    public function extend(array $modules): array
    {
        foreach (['entities','media','videos','knowledge','hubs','clock_groups','explore_next'] as $key) if (!isset($modules[$key]) || !is_array($modules[$key])) $modules[$key] = [];
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
            $mediaItems = LatestFirstOrder::sort($this->media->list(), static fn (\NHK\Core\Domain\Media\Media $item): ?string => null, static fn (\NHK\Core\Domain\Media\Media $item): ?string => $item->createdAt, static fn (\NHK\Core\Domain\Media\Media $item): string => $item->canonicalId);
            foreach ($mediaItems as $item) {
                if (!$item->active || $item->readiness !== 'ready' || $item->isSystemPlaceholder()) continue;
                $visual = $this->gallery->forMedia($item->canonicalId);
                if (!is_array($visual) || trim((string) ($visual['image_url'] ?? '')) === '' || ($visual['has_real_image'] ?? false) !== true) continue;
                $modules['media_total']++;
                if (count($modules['media']) < 8) $modules['media'][] = $visual;
            }
            $manualIds = function_exists('get_option') ? (array) get_option('nhk_v3_home_hero_media_ids', []) : [];
            if (function_exists('apply_filters')) $manualIds = apply_filters('nhk_v3_home_hero_media_ids', $manualIds);
            $heroCandidates = [];
            foreach ($mediaItems as $item) {
                $visual = $this->gallery->forMedia($item->canonicalId);
                if (!is_array($visual) || trim((string) ($visual['image_url'] ?? '')) === '' || ($visual['has_real_image'] ?? false) !== true) continue;
                $visual['_canonical_id'] = $item->canonicalId;
                $heroCandidates[] = $visual;
            }
            $modules['hero_media'] = (new HomeHeroMediaSelector())->select((array) $manualIds, $heroCandidates);
        }

        if ($this->ready('video')) {
            $modules['videos'] = [];
            $modules['videos_total'] = 0;
            $videoItems = LatestFirstOrder::sort($this->videos->list(), fn (\NHK\Core\Domain\Video\Video $item): ?string => $this->videoPublishedAt($item), fn (\NHK\Core\Domain\Video\Video $item): ?string => $item->createdAt, fn (\NHK\Core\Domain\Video\Video $item): string => $item->canonicalId);
            foreach ($videoItems as $item) {
                if (!$item->active || !$item->hasValidPublicReference()) continue;
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $source = is_array($metadata['source_snapshot'] ?? null)
                    ? $metadata['source_snapshot']
                    : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
                if (isset($source['availability']) && !in_array($source['availability'], ['available','unknown'], true)) continue;
                $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                $title = trim((string) ($editorial['title'] ?? '')) ?: ($item->title ?: 'Video');
                $thumbnail = (new \NHK\Core\Application\Video\VideoThumbnailSelector())->fromSource($source);
                $modules['videos_total']++;
                if (count($modules['videos']) >= 6) continue;
                $modules['videos'][] = [
                    'title' => $title,
                    'platform' => $item->platform,
                    'url' => (new PublicSeoProjection())->project((new VideoUrlPolicy())->project($item, new VideoPublicContextSelector()), ['type' => 'VideoObject'])['internal_link'] ?? null,
                    'thumbnail_url' => $thumbnail['url'] ?? null,
                    'thumbnail' => $thumbnail,
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
        $modules['hubs'] = PublicNavigationDefinition::sortHubItems($modules['hubs']);
        return $modules;
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
