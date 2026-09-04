<?php
declare(strict_types=1);

namespace NHK\Core\Application\Search;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Seo\PublicSeoProjection;

final class SearchSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null, private ?PublicEntityCollectionQuery $collection = null) {}

    public function extend(array $groups, string $term, int $page = 1, int $perPage = 12): array
    {
        $term = trim($term);
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        if ($term === '') {
            foreach (['entities', 'media', 'videos', 'knowledge'] as $group) { $groups[$group] = []; $groups['_totals'][$group] = 0; }
            return $groups;
        }
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->collection()->archive($definition->type, 1, 100, $term)['items'] as $item) $groups['entities'][] = ['type' => $item['type'], 'title' => $item['name'], 'url' => (new PublicSeoProjection())->project(['path' => $item['url'], 'eligible' => true, 'canonical_url' => $item['url'], 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'Entity'])['search']];
        if ($this->ready('media')) foreach ($this->media->list() as $item) if ($item->active && $item->readiness === 'ready' && ($path = PublicRouteResolver::existingSemanticPath('media', $item->canonicalId)) !== null && $this->matches($term, $item->canonicalName, $item->stableKey)) $groups['media'][] = ['type' => 'media', 'title' => $item->canonicalName, 'url' => (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'canonical_url' => $path, 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'ImageObject'])['search']];
        if ($this->ready('video')) { $videoSearch = new VideoSearchDocument($this->authority); foreach ($this->videos->list() as $item) {
            if (!$item->active || !$item->hasValidPublicReference() || !$videoSearch->isDiscoverable($item)) continue;
            $title = $videoSearch->title($item); $path = $videoSearch->publicUrl($item); $url = $path === null ? null : (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true], ['type' => 'VideoObject'])['search'];
            if ($url !== null && $this->matches($term, ...$videoSearch->values($item))) $groups['videos'][] = ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => $url];
        }
        }
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $item) if ($item->active && $item->isPublic() && ($path = PublicRouteResolver::existingSemanticPath('knowledge', $item->canonicalId)) !== null && $this->matches($term, $item->claimText, $item->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'title' => $item->claimText, 'url' => (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'canonical_url' => $path, 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'Claim'])['search']];
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

    private function collection(): PublicEntityCollectionQuery
    {
        $this->routes ??= new PublicRouteResolver($this->authority, $this->types);
        return $this->collection ??= new PublicEntityCollectionQuery($this->authority, $this->types, new PublicIdentityContract($this->types), new PublicEntityEligibilityPolicy($this->authority, $this->types, $this->routes), $this->routes);
    }
}
