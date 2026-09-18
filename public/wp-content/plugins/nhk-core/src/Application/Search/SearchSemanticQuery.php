<?php
declare(strict_types=1);

namespace NHK\Core\Application\Search;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\{EntityProfileRegistry, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Presentation\LatestFirstOrder;
use NHK\Core\Domain\Video\Video;

final class SearchSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null, private ?PublicEntityCollectionQuery $collection = null, private $claimOwnerUrl = null) {}

    public function extend(array $groups, string $term, int $page = 1, int $perPage = 12): array
    {
        $term = trim($term);
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        if ($term === '') {
            foreach (['entities', 'media', 'videos', 'knowledge'] as $group) { $groups[$group] = []; $groups['_totals'][$group] = 0; }
            return $groups;
        }
        if ($this->ready('authority')) foreach ($this->entityItemsForSearch($term) as $item) $groups['entities'][] = $item;
        if ($this->ready('media')) foreach (LatestFirstOrder::sort($this->media->list(), static fn (\NHK\Core\Domain\Media\Media $item): ?string => null, static fn (\NHK\Core\Domain\Media\Media $item): ?string => $item->createdAt, static fn (\NHK\Core\Domain\Media\Media $item): string => $item->canonicalId) as $item) if ($item->active && $item->readiness === 'ready' && ($path = PublicRouteResolver::existingSemanticPath('media', $item->canonicalId)) !== null && $this->matches($term, $item->canonicalName, $item->stableKey)) $groups['media'][] = ['type' => 'media', 'title' => $item->canonicalName, 'url' => (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'canonical_url' => $path, 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'ImageObject'])['search']];
        if ($this->ready('video')) { $videoSearch = new VideoSearchDocument($this->authority); foreach (LatestFirstOrder::sort($this->videos->list(), fn (Video $item): ?string => $this->videoPublishedAt($item), fn (Video $item): ?string => $item->createdAt, fn (Video $item): string => $item->canonicalId) as $item) {
            if (!$item->active || !$item->hasValidPublicReference() || !$videoSearch->isDiscoverable($item)) continue;
            $title = $videoSearch->title($item); $path = $videoSearch->publicUrl($item); $url = $path === null ? null : (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true], ['type' => 'VideoObject'])['search'];
            if ($url !== null && $this->matches($term, ...$videoSearch->values($item))) $groups['videos'][] = ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => $url];
        }
        }
        if ($this->ready('knowledge')) {
            $owners = [];
            foreach (LatestFirstOrder::sort($this->claims->list(), static fn (\NHK\Core\Domain\Knowledge\KnowledgeClaim $item): ?string => null, static fn (\NHK\Core\Domain\Knowledge\KnowledgeClaim $item): ?string => $item->createdAt, static fn (\NHK\Core\Domain\Knowledge\KnowledgeClaim $item): string => $item->canonicalId) as $item) {
                if (!$item->active || !$item->isPublic() || !$this->matches($term, $item->claimText, $item->stableKey)) continue;
                $path = is_callable($this->claimOwnerUrl) ? ($this->claimOwnerUrl)($item) : null;
                if (!is_string($path) || trim($path) === '' || isset($owners[$path])) continue;
                $owners[$path] = true;
                $groups['knowledge'][] = ['type' => 'knowledge', 'title' => $item->claimText, 'url' => (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'canonical_url' => $path, 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'Entity'])['search']];
            }
        }
        $offset = ($page - 1) * $perPage;
        $groups['_totals'] = [];
        foreach (['entities', 'media', 'videos', 'knowledge'] as $group) {
            $groups['_totals'][$group] = count($groups[$group] ?? []);
            $groups[$group] = array_slice($groups[$group] ?? [], $offset, $perPage);
        }
        return $groups;
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

    private function matches(string $term, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $term) : stripos($value, $term)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }

    private function videoPublishedAt(Video $video): ?string
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
        return isset($source['published_at']) && is_string($source['published_at']) ? $source['published_at'] : null;
    }

    private function collection(): PublicEntityCollectionQuery
    {
        $this->routes ??= new PublicRouteResolver($this->authority, $this->types);
        return $this->collection ??= new PublicEntityCollectionQuery($this->authority, $this->types, new PublicIdentityContract($this->types), new PublicEntityEligibilityPolicy($this->authority, $this->types, $this->routes), $this->routes);
    }

    /** @return list<array<string,mixed>> */
    private function entityItemsForSearch(string $term): array
    {
        $collection = $this->collection();
        $profileOnly = [];
        $items = [];
        foreach ((new EntityProfileRegistry())->all() as $profile) {
            $type = (string) ($profile->matchingRule['entity_type'] ?? '');
            $profilePath = $this->routes?->archivePathForProfile($profile->key);
            $genericPath = $type === '' ? null : $this->routes?->archivePath($type);
            if ($type === '' || $profilePath === null || $profilePath === $genericPath) continue;
            $profileOnly[$profile->key] = true;
            foreach ($collection->archiveProfile($profile->key, 1, 100, $term)['items'] as $item) $items[] = $this->searchEntity($item);
        }
        foreach ($this->types->all() as $definition) foreach ($collection->archive($definition->type, 1, 100, $term)['items'] as $item) {
            if (isset($profileOnly[(string) ($item['profile_key'] ?? '')])) continue;
            $items[] = $this->searchEntity($item);
        }
        return $items;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function searchEntity(array $item): array
    {
        return ['type' => $item['type'], 'profile_key' => $item['profile_key'] ?? null, 'profile_label' => $item['profile_label'] ?? null, 'profile_badge' => $item['profile_badge'] ?? null, 'profile_status' => $item['profile_status'] ?? null, 'title' => $item['name'], 'url' => (new PublicSeoProjection())->project(['path' => $item['url'], 'eligible' => true, 'canonical_url' => $item['url'], 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'Entity'])['search']];
    }
}
