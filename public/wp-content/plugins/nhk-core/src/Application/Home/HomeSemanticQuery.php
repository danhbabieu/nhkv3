<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Shared\Migration\MigrationStatus;

final class HomeSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null) {}

    public function extend(array $modules): array
    {
        if ($this->ready('authority')) {
            foreach ($this->types->all() as $definition) {
                foreach ($this->authority->listByType($definition->type) as $entity) {
                    if (!$entity->active()) continue;
                    $path = ($this->routes ??= new PublicRouteResolver($this->authority, $this->types))->path($entity); if ($path === null) continue;
                    $modules['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'url' => home_url($path)];
                    if (count($modules['entities']) >= 6) break 2;
                }
            }
        }
        if ($this->ready('media')) {
            foreach ($this->media->list() as $item) {
                if (!$item->active || $item->readiness !== 'ready') continue;
                $path = PublicRouteResolver::existingSemanticPath('media', $item->canonicalId); if ($path === null) continue;
                $modules['media'][] = ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'url' => home_url($path)];
                if (count($modules['media']) >= 4) break;
            }
        }
        if ($this->ready('video')) {
            foreach ($this->videos->list() as $item) {
                if (!$item->active || !$item->hasValidPublicReference()) continue;
                $path = PublicRouteResolver::videoPath($item->title, $item->externalVideoId); if ($path === null) continue;
                $modules['videos'][] = ['id' => $item->canonicalId, 'title' => $item->title ?: 'Video NHK', 'platform' => $item->platform, 'url' => home_url($path)];
                if (count($modules['videos']) >= 4) break;
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
            default => false,
        };
    }
}
