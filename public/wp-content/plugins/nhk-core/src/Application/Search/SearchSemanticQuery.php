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
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Shared\Migration\MigrationStatus;

final class SearchSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null, private ?PublicEntityCollectionQuery $collection = null, private ?PublicIdentityRepository $identities = null, private ?\NHK\Core\Application\Video\VideoUrlPolicy $videoPolicy = null) {}

    public function extend(array $groups, string $term, int $page = 1, int $perPage = 12): array
    {
        $term = trim($term);
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        if ($term === '') {
            foreach (['entities', 'media', 'videos', 'knowledge'] as $group) { $groups[$group] = []; $groups['_totals'][$group] = 0; }
            return $groups;
        }
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->collection()->archive($definition->type, 1, 100, $term, true)['items'] as $item) {
            $projection = (new PublicSeoProjection())->project($item['_public_url_result'], ['type' => $item['type'], 'title' => $item['name']]);
            if ($projection['search'] !== null) $groups['entities'][] = ['type' => $item['type'], 'title' => $item['name'], 'url' => function_exists('home_url') ? home_url($projection['search']) : $projection['search']];
        }
        // Media has delivery URLs, not a governed public detail route.
        if ($this->ready('video')) { $videoSearch = new VideoSearchDocument($this->authority, $this->identities, $this->videoPolicy); foreach ($this->videos->list() as $item) {
            if (!$item->active || !$item->hasValidPublicReference() || !$videoSearch->isDiscoverable($item)) continue;
            $title = $videoSearch->title($item); $path = $videoSearch->publicUrl($item);
            if ($path !== null && $this->matches($term, ...$videoSearch->values($item))) $groups['videos'][] = ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => function_exists('home_url') ? home_url($path) : $path];
        }
        }
        // Atomic Knowledge claims are projection fragments, not public detail routes.
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
