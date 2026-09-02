<?php
declare(strict_types=1);

namespace NHK\Core\Application\Search;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Shared\Migration\MigrationStatus;

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
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->collection()->archive($definition->type, 1, 100, $term)['items'] as $item) $groups['entities'][] = ['type' => $item['type'], 'title' => $item['name'], 'url' => function_exists('home_url') ? home_url($item['url']) : $item['url']];
        if ($this->ready('media')) foreach ($this->media->list() as $item) if ($item->active && $item->readiness === 'ready' && ($path = PublicRouteResolver::existingSemanticPath('media', $item->canonicalId)) !== null && $this->matches($term, $item->canonicalName, $item->stableKey)) $groups['media'][] = ['type' => 'media', 'title' => $item->canonicalName, 'url' => home_url($path)];
        if ($this->ready('video')) foreach ($this->videos->list() as $item) {
            if (!$item->active || !$item->hasValidPublicReference()) continue;
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
            if (isset($source['availability']) && $source['availability'] !== 'available' && $source['availability'] !== 'unknown') continue;
            $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
            $title = trim((string) ($editorial['title'] ?? '')) ?: ($item->title ?: 'Video NHK');
            $searchValues = [$title, $item->externalVideoId, $item->canonicalUrl, (string) ($editorial['summary'] ?? ''), (string) ($editorial['body'] ?? ''), (string) ($editorial['why_this_matters'] ?? ''), (string) ($source['source_title'] ?? ''), (string) ($source['source_description'] ?? ''), (string) ($metadata['category']['primary']['label'] ?? '')];
            foreach ((array) ($source['tags'] ?? []) as $tag) $searchValues[] = (string) $tag;
            foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
                $target = $this->authority->findByCanonicalId((string) ($attachment['target_key'] ?? $attachment['target_id'] ?? ''));
                if ($target !== null && $target->active()) $searchValues[] = $target->canonicalName;
            }
            $path = PublicRouteResolver::videoPath($title, $item->externalVideoId);
            if ($path !== null && $this->matches($term, ...$searchValues)) $groups['videos'][] = ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => function_exists('home_url') ? home_url($path) : $path];
        }
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $item) if ($item->active && $item->isPublic() && ($path = PublicRouteResolver::existingSemanticPath('knowledge', $item->canonicalId)) !== null && $this->matches($term, $item->claimText, $item->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'title' => $item->claimText, 'url' => home_url($path)];
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
