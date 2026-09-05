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
        foreach (['entities','media','videos','knowledge','hubs'] as $key) if (!isset($modules[$key]) || !is_array($modules[$key])) $modules[$key] = [];

        if ($this->ready('authority')) {
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

        if ($this->ready('media') && $this->gallery !== null) $modules['media'] = $this->gallery->archive(1, 8)['items'];

        if ($this->ready('video')) {
            foreach ($this->videos->list() as $item) {
                if (!$item->active || !$item->hasValidPublicReference()) continue;
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
                if (isset($source['availability']) && !in_array($source['availability'], ['available','unknown'], true)) continue;
                $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                $title = trim((string) ($editorial['title'] ?? '')) ?: ($item->title ?: 'Video');
                $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
                if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') $thumbnail = '';
                $modules['videos'][] = [
                    'title' => $title,
                    'platform' => $item->platform,
                    'url' => (new PublicSeoProjection())->project((new VideoUrlPolicy())->project($item, new VideoPublicContextSelector()), ['type' => 'VideoObject'])['internal_link'],
                    'thumbnail_url' => $thumbnail !== '' ? $thumbnail : null,
                ];
                if (count($modules['videos']) >= 6) break;
            }
        }

        if ($this->ready('knowledge') && $this->knowledge !== null) {
            foreach (($this->knowledge->archive(1, 6)['items'] ?? []) as $item) {
                if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') continue;
                $modules['knowledge'][] = ['text' => (string) $item['text'], 'type' => (string) ($item['type'] ?? '')];
            }
        }
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
}
