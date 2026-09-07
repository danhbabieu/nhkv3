<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Knowledge\SourceRepository;
use NHK\Core\Contracts\Knowledge\EvidenceRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Infrastructure\Admin\{AdminMediaAdapter, AdminVideoAdapter};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;

final class AdminWorkbenchReadApi
{
    public function __construct(
        private MediaRepository $media,
        private VideoRepository $videos,
        private KnowledgeRepository $claims,
        private AuthorityRepository $authority,
        private ?SourceRepository $sources = null,
        private ?EvidenceRepository $evidence = null,
        private ?GraphService $graph = null,
        private ?ProposalRepository $proposals = null,
        private ?ProposalEligibilityService $eligibility = null,
        private ?MediaAssetRepository $assets = null,
        private ?MediaUsageRepository $usages = null,
    ) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/admin/workbench/search', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance') || current_user_can('manage_options'), 'args' => ['q' => ['required' => true], 'domain' => ['default' => 'all']], 'callback' => fn (\WP_REST_Request $request) => $this->search($request)]);
        register_rest_route('nhk/v1', '/admin/workbench/video/(?P<id>[0-9A-Fa-f-]{36})', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance') || current_user_can('manage_options'), 'callback' => fn (\WP_REST_Request $request) => $this->video((string) $request['id'])]);
        register_rest_route('nhk/v1', '/admin/workbench/media/(?P<id>[0-9A-Fa-f-]{36})', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance') || current_user_can('manage_options'), 'callback' => fn (\WP_REST_Request $request) => $this->media((string) $request['id'])]);
    }

    private function search(\WP_REST_Request $request): array|\WP_Error
    {
        $query = strtolower(trim((string) $request['q']));
        if (strlen($query) < 2 || strlen($query) > 120) return new \WP_Error('nhk_admin_search_term_invalid', 'Từ khóa phải có 2–120 ký tự.', ['status' => 400]);
        $domain = sanitize_key((string) $request['domain']); $groups = ['videos' => [], 'media' => [], 'knowledge' => [], 'entities' => []];
        if ($domain === 'all' || $domain === 'video' || $domain === 'media') foreach ($this->videos->list() as $item) if ($this->matches($query, $item->title, $item->externalVideoId, $item->canonicalId)) $groups['videos'][] = $this->videoRow($item);
        if ($domain === 'all' || $domain === 'media') foreach ($this->media->list(true) as $item) if ($this->matches($query, $item->canonicalName, $item->stableKey, $item->canonicalId)) $groups['media'][] = array_merge(['type' => 'media'], (new AdminMediaAdapter([$item], $this->assets?->listByMediaId($item->canonicalId) ?? [], $this->usages?->listByMediaId($item->canonicalId) ?? []))->find()[0] ?? []);
        if ($domain === 'all' || $domain === 'knowledge') foreach ($this->claims->list(true) as $item) if ($this->matches($query, $item->claimText, $item->stableKey, $item->canonicalId)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $item->canonicalId, 'title' => $item->claimText, 'stable_key' => $item->stableKey, 'claim_type' => $item->claimType, 'active' => $item->active];
        if ($domain === 'all' || $domain === 'entity') { $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); foreach ($types->all() as $definition) foreach ($this->authority->listByType($definition->type, true) as $item) if ($this->matches($query, $item->canonicalName, $item->stableKey, $item->canonicalId)) $groups['entities'][] = ['type' => $item->entityType, 'id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey, 'active' => $item->active()]; }
        foreach ($groups as $key => $items) $groups[$key] = array_slice($items, 0, 50);
        return ['query' => $query, 'groups' => $groups];
    }

    private function media(string $id): array|\WP_Error
    {
        $media = $this->media->findByCanonicalId($id);
        if (!$media instanceof Media) return new \WP_Error('nhk_admin_media_not_found', 'Không tìm thấy Media canonical.', ['status' => 404]);
        return (new AdminMediaAdapter([$media], $this->assets?->listByMediaId($id) ?? [], $this->usages?->listByMediaId($id) ?? []))->detail($media);
    }

    private function video(string $id): array|\WP_Error
    {
        $video = $this->videos->findByCanonicalId($id);
        if (!$video instanceof Video) return new \WP_Error('nhk_admin_video_not_found', 'Không tìm thấy Video canonical.', ['status' => 404]);

        $relations = [];
        if ($this->graph !== null) {
            try {
                foreach ($this->graph->findOutgoing(new NodeReference('video', $video->canonicalId), 'about')['items'] ?? [] as $edge) {
                    $relations[] = ['predicate' => $edge->predicate, 'target_type' => $edge->target->reference->endpoint_type, 'target_key' => $edge->target->reference->endpoint_key, 'state' => $edge->state->value, 'revision' => $edge->revision];
                }
            } catch (\Throwable) {
                $relations = [];
            }
        }

        $evidence = [];
        $seenEvidence = [];
        foreach (is_array($video->metadata['semantic_attachments'] ?? null) ? $video->metadata['semantic_attachments'] : [] as $attachment) {
            if (!is_array($attachment) || !is_array($attachment['evidence_refs'] ?? null)) continue;
            foreach ($attachment['evidence_refs'] as $ref) {
                $evidenceId = is_array($ref) ? (string) ($ref['evidence_id'] ?? '') : (string) $ref;
                if ($evidenceId === '' || isset($seenEvidence[$evidenceId]) || $this->evidence === null) continue;
                $item = $this->evidence->findByCanonicalId($evidenceId);
                if ($item === null) continue;
                $seenEvidence[$evidenceId] = true;
                $claim = $this->claims->findByCanonicalId($item->claimId);
                $source = $this->sources?->findByCanonicalId($item->sourceId);
                $evidence[] = ['evidence_id' => $item->canonicalId, 'claim_id' => $item->claimId, 'claim' => $claim?->claimText, 'source_id' => $item->sourceId, 'source' => $source?->title, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator];
            }
        }

        $governance = ['state' => null, 'proposal_id' => null, 'revision' => null, 'eligible' => null, 'blockers' => []];
        if ($this->proposals !== null) {
            $proposal = $this->proposals->findLatestVideoIngest($video->canonicalId);
            if ($proposal !== null) {
                $eligibility = $this->eligibility?->check($proposal->id);
                $governance = ['state' => $proposal->state->value, 'proposal_id' => $proposal->id, 'revision' => $proposal->revision, 'eligible' => $eligibility?->ready, 'blockers' => $eligibility?->reasons ?? []];
            }
        }

        $frontendProjection = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        return (new AdminVideoAdapter([$video]))->detail($video, $relations, $evidence, $governance, $frontendProjection);
    }

    /** @return array<string,mixed> */
    private function videoRow(Video $item): array { return array_merge(['type' => 'video'], (new AdminVideoAdapter([$item]))->find($item->externalVideoId)[0] ?? []); }
    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if (str_contains(strtolower($value), $query)) return true; return false; }
}
