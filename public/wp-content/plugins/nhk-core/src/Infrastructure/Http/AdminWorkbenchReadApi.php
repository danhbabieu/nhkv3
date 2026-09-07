<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;

final class AdminWorkbenchReadApi
{
    public function __construct(private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private AuthorityRepository $authority) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/admin/workbench/search', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance') || current_user_can('manage_options'), 'args' => ['q' => ['required' => true], 'domain' => ['default' => 'all']], 'callback' => fn (\WP_REST_Request $request) => $this->search($request)]);
        register_rest_route('nhk/v1', '/admin/workbench/video/(?P<id>[0-9A-Fa-f-]{36})', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance') || current_user_can('manage_options'), 'callback' => fn (\WP_REST_Request $request) => $this->video((string) $request['id'])]);
    }

    private function search(\WP_REST_Request $request): array|\WP_Error
    {
        $query = strtolower(trim((string) $request['q']));
        if (strlen($query) < 2 || strlen($query) > 120) return new \WP_Error('nhk_admin_search_term_invalid', 'Từ khóa phải có 2–120 ký tự.', ['status' => 400]);
        $domain = sanitize_key((string) $request['domain']); $groups = ['videos' => [], 'media' => [], 'knowledge' => [], 'entities' => []];
        if ($domain === 'all' || $domain === 'video') foreach ($this->videos->list() as $item) if ($this->matches($query, $item->title, $item->externalVideoId, $item->canonicalId)) $groups['videos'][] = $this->videoRow($item);
        if ($domain === 'all' || $domain === 'media') foreach ($this->media->list(true) as $item) if ($this->matches($query, $item->canonicalName, $item->stableKey, $item->canonicalId)) $groups['media'][] = ['type' => 'media', 'id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey, 'readiness' => $item->readiness, 'active' => $item->active];
        if ($domain === 'all' || $domain === 'knowledge') foreach ($this->claims->list(true) as $item) if ($this->matches($query, $item->claimText, $item->stableKey, $item->canonicalId)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $item->canonicalId, 'title' => $item->claimText, 'stable_key' => $item->stableKey, 'claim_type' => $item->claimType, 'active' => $item->active];
        if ($domain === 'all' || $domain === 'entity') { $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); foreach ($types->all() as $definition) foreach ($this->authority->listByType($definition->type, true) as $item) if ($this->matches($query, $item->canonicalName, $item->stableKey, $item->canonicalId)) $groups['entities'][] = ['type' => $item->entityType, 'id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey, 'active' => $item->active()]; }
        foreach ($groups as $key => $items) $groups[$key] = array_slice($items, 0, 50);
        return ['query' => $query, 'groups' => $groups];
    }

    private function video(string $id): array|\WP_Error
    {
        $video = $this->videos->findByCanonicalId($id);
        return $video instanceof Video ? ['video' => $this->videoRow($video), 'metadata' => $video->metadata] : new \WP_Error('nhk_admin_video_not_found', 'Không tìm thấy Video canonical.', ['status' => 404]);
    }

    /** @return array<string,mixed> */
    private function videoRow(Video $item): array { return ['type' => 'video', 'id' => $item->canonicalId, 'title' => $item->title !== '' ? $item->title : 'Video chưa có tiêu đề', 'platform' => $item->platform, 'external_id' => $item->externalVideoId, 'url' => $item->canonicalUrl, 'thumbnail_media_id' => $item->thumbnailMediaId, 'active' => $item->active, 'revision' => $item->revision]; }
    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if (str_contains(strtolower($value), $query)) return true; return false; }
}
