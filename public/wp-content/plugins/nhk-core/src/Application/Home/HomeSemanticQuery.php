<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Shared\Migration\MigrationStatus;

final class HomeSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private EntityTypeRegistry $types, private ?MigrationStatus $status = null) {}

    public function extend(array $modules): array
    {
        if ($this->ready('authority')) {
            foreach ($this->types->all() as $definition) {
                foreach ($this->authority->listByType($definition->type) as $entity) {
                    if (!$entity->active()) continue;
                    $modules['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'url' => home_url('/' . $entity->entityType . '/' . rawurlencode($entity->stableKey) . '/')];
                    if (count($modules['entities']) >= 6) break 2;
                }
            }
        }
        if ($this->ready('media')) {
            foreach ($this->media->list() as $item) {
                if (!$item->active) continue;
                $modules['media'][] = ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'url' => home_url('/media/' . rawurlencode($item->canonicalId) . '/')];
                if (count($modules['media']) >= 4) break;
            }
        }
        if ($this->ready('video')) {
            foreach ($this->videos->list() as $item) {
                if (!$item->active) continue;
                $modules['videos'][] = ['id' => $item->canonicalId, 'title' => $item->title ?: 'Video NHK', 'platform' => $item->platform, 'url' => home_url('/video/' . rawurlencode($item->canonicalId) . '/')];
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
