<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Shared\Migration\MigrationStatus;

final class HomeSemanticQuery
{
    public function __construct(private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicRouteResolver $routes = null, private ?PublicEntityCollectionQuery $collection = null) {}

    public function extend(array $modules): array
    {
        if ($this->ready('authority')) {
            foreach ($this->types->all() as $definition) {
                foreach ($this->collection()->archive($definition->type, 1, 6)['items'] as $item) {
                    $modules['entities'][] = ['type' => $item['type'], 'id' => $item['id'], 'title' => $item['name'], 'url' => home_url($item['url'])];
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

    private function collection(): PublicEntityCollectionQuery
    {
        $this->routes ??= new PublicRouteResolver($this->authority, $this->types);
        return $this->collection ??= new PublicEntityCollectionQuery($this->authority, $this->types, new PublicIdentityContract($this->types), new PublicEntityEligibilityPolicy($this->authority, $this->types, $this->routes), $this->routes);
    }
}
