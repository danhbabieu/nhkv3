<?php
declare(strict_types=1);

namespace NHK\Core\Application\Search;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Shared\Migration\MigrationStatus;

final class SearchSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private EntityTypeRegistry $types, private ?MigrationStatus $status = null) {}

    public function extend(array $groups, string $term, int $page = 1, int $perPage = 12): array
    {
        $term = trim($term);
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) if ($entity->active() && $this->matches($term, $entity->canonicalName, $entity->stableKey, $this->json($entity->payload))) $groups['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'stable_key' => $entity->stableKey];
        if ($this->ready('media')) foreach ($this->media->list() as $item) if ($item->active && $this->matches($term, $item->canonicalName, $item->stableKey)) $groups['media'][] = ['type' => 'media', 'id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey];
        if ($this->ready('video')) foreach ($this->videos->list() as $item) if ($item->active && $this->matches($term, $item->title, $item->externalVideoId, $item->canonicalUrl)) $groups['videos'][] = ['type' => 'video', 'id' => $item->canonicalId, 'title' => $item->title ?: 'Video NHK', 'platform' => $item->platform];
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $item) if ($item->active && $item->isPublic() && $this->matches($term, $item->claimText, $item->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $item->canonicalId, 'title' => $item->claimText, 'stable_key' => $item->stableKey];
        $page = max(1, $page); $perPage = min(50, max(1, $perPage)); $offset = ($page - 1) * $perPage;
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
