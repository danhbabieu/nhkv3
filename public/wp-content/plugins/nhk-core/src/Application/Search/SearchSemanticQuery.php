<?php
declare(strict_types=1);

namespace NHK\Core\Application\Search;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Shared\Migration\MigrationStatus;

final class SearchSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null) {}

    public function extend(array $groups, string $term, int $page = 1, int $perPage = 12): array
    {
        $term = trim($term);
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        if ($term === '') {
            foreach (['entities', 'media', 'videos', 'knowledge'] as $group) { $groups[$group] = []; $groups['_totals'][$group] = 0; }
            return $groups;
        }
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) { $publicPayload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); $path = ($this->routes ??= new PublicRouteResolver($this->authority, $this->types))->path($entity); if ($entity->active() && $path !== null && $this->matches($term, $entity->canonicalName, $entity->stableKey, $this->json($publicPayload))) $groups['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'stable_key' => $entity->stableKey, 'url' => function_exists('home_url') ? home_url($path) : $path]; }
        if ($this->ready('media')) foreach ($this->media->list() as $item) if ($item->active && $item->readiness === 'ready' && $this->matches($term, $item->canonicalName, $item->stableKey)) $groups['media'][] = ['type' => 'media', 'id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey];
        if ($this->ready('video')) foreach ($this->videos->list() as $item) if ($item->active && $item->hasValidPublicReference() && $this->matches($term, $item->title, $item->externalVideoId, $item->canonicalUrl)) $groups['videos'][] = ['type' => 'video', 'id' => $item->canonicalId, 'title' => $item->title ?: 'Video NHK', 'platform' => $item->platform];
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $item) if ($item->active && $item->isPublic() && $this->matches($term, $item->claimText, $item->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $item->canonicalId, 'title' => $item->claimText, 'stable_key' => $item->stableKey];
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
}
