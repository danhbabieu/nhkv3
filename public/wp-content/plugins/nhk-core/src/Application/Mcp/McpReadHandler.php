<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaBindingOperationRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Application\Graph\SemanticNeighborhoodQuery;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Application\Inventory\{CanonicalInventoryService, GraphInventoryService};
use NHK\Core\Application\Graph\RelationBackfillService;
use NHK\Core\Application\Presentation\LatestFirstOrder;
use NHK\Core\Application\Capture\CaptureCurrentOutcomeReducer;
use NHK\Core\Application\Graph\RelationshipReadService;

final class McpReadHandler
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private VideoRepository $videos,
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private ?MigrationStatus $status = null,
        private ?SourceRepository $sources = null,
        private ?PublicMediaAssetDelivery $delivery = null,
        private ?McpSemanticContextResolver $resolver = null,
        private ?WordPressMediaAttachmentIngestor $wordpressAttachments = null,
        private ?SemanticNeighborhoodQuery $neighborhood = null,
        private ?CanonicalInventoryService $canonicalInventory = null,
        private ?GraphInventoryService $graphInventory = null,
        private ?RelationBackfillService $relationBackfill = null,
        private ?MediaBindingOperationRepository $mediaBindingOperations = null,
        private ?CaptureRepository $captures = null,
        private ?RelationshipReadService $relationships = null,
    ) { $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media); }

    public function entityGet(string $type, string $id): ?array
    {
        if (!$this->types->has($type) || !$this->ready('authority') || !UuidCodec::isValid($id)) return null;
        $entity = $this->authority->findByCanonicalId($id);
        return $entity && $entity->entityType === $type && $entity->active() ? $this->entity($entity) : null;
    }

    public function mediaGet(string $id): ?array
    {
        if (!$this->ready('media') || !UuidCodec::isValid($id)) return null;
        $media = $this->media->findByCanonicalId($id);
        if (!$media || !$media->active || $media->readiness !== 'ready') return null;
        $assets = array_values(array_filter($this->assets->listByMediaId($id), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null)));
        $usages = array_values(array_filter($this->usages->listByMediaId($id), static fn (MediaUsage $usage): bool => $usage->activeSlot !== 'retired'));
        return ['id' => $media->canonicalId, 'stable_key' => $media->stableKey, 'name' => $media->canonicalName, 'assets' => array_map($this->publicAsset(...), $assets), 'usages' => array_map($this->publicUsage(...), $usages)];
    }

    /** Read-only operator projection of one Capture; request secrets remain private. */
    public function captureGet(string $id): array
    {
        if (!UuidCodec::isValid($id)) throw new \InvalidArgumentException('Capture id must be a canonical UUID.');
        if ($this->captures === null) return ['status' => 'unavailable', 'reason' => 'CAPTURE_READBACK_UNAVAILABLE', 'capture_id' => $id];
        $capture = $this->captures->findById($id);
        if ($capture === null) return ['status' => 'not_found', 'reason' => 'CAPTURE_NOT_FOUND', 'capture_id' => $id, 'retry' => ['eligible' => false, 'reason' => 'CAPTURE_NOT_FOUND']];
        $context = $capture->context;
        $diagnostics = $capture->diagnostics;
        $packet = is_array($context['subject_resolution_packet'] ?? null)
            ? $context['subject_resolution_packet']
            : (is_array($diagnostics['subject_resolution_packet'] ?? null) ? $diagnostics['subject_resolution_packet'] : null);
        $completion = is_array($diagnostics['completion'] ?? null) ? $diagnostics['completion'] : [];
        $completion = $this->reconcileCurrentCaptureCompletion($capture, $completion);
        $preparation = is_array($diagnostics['content_preparation'] ?? null) ? $diagnostics['content_preparation'] : [];
        $review = null;
        if (strtoupper(trim((string) ($preparation['status'] ?? ''))) === 'REVIEW_REQUIRED') {
            $reasons = array_values(array_filter(array_map('strval', (array) ($preparation['review_reasons'] ?? [])), static fn (string $reason): bool => trim($reason) !== ''));
            $subjectReview = array_intersect($reasons, ['PRIMARY_SUBJECT_AMBIGUOUS', 'SUBJECT_CONFLICT_REVIEW_REQUIRED', 'FINAL_SUBJECT_PACKET_INVALID']) !== [];
            $review = [
                'status' => 'REVIEW_REQUIRED',
                'reasons' => $reasons,
                'blockers' => array_values(array_map('strval', (array) ($preparation['blockers'] ?? []))),
                'candidates' => is_array($preparation['candidates'] ?? null) ? array_values($preparation['candidates']) : [],
                'continuation' => [
                    'entrypoint' => 'nhk.capture.ingest',
                    'input' => $subjectReview ? 'subject_reconciliation' : 'capture_continuation',
                ],
            ];
        }
        $children = \NHK\Core\Application\Completion\CompletionCoordinator::effectiveChildren(
            array_values(array_filter((array) ($completion['children'] ?? []), 'is_array')),
        );
        $ownersByIdentity = [];
        foreach ($children as $child) {
            $owner = [
                'owner_type' => (string) ($child['owner_type'] ?? ''),
                'owner_id' => (string) ($child['owner_id'] ?? ''),
                'status' => (string) ($child['status'] ?? (($child['complete'] ?? false) === true ? 'COMPLETE' : 'INCOMPLETE')),
            ];
            $identity = strtolower(trim($owner['owner_type'])) . '|' . trim($owner['owner_id']);
            $ownersByIdentity[$identity] = $owner;
        }
        $owners = array_values($ownersByIdentity);
        $media = [];
        foreach ($capture->assets as $asset) {
            if (!is_array($asset)) continue;
            $mediaId = trim((string) ($asset['media_id'] ?? ''));
            if ($mediaId !== '') $media[$mediaId] = ['media_id' => $mediaId, 'attachment_id' => (int) ($asset['attachment_id'] ?? 0), 'status' => (string) ($asset['attachment_readback_status'] ?? '')];
        }
        $videos = [];
        foreach ($capture->assets as $asset) {
            if (!is_array($asset) || ($asset['kind'] ?? '') !== 'video') continue;
            $videoId = trim((string) ($asset['video_id'] ?? $asset['canonical_id'] ?? $asset['video_proposal']['payload']['canonical_id'] ?? ''));
            if ($videoId !== '') $videos[] = ['id' => $videoId, 'status' => (string) ($asset['status'] ?? '')];
        }
        $retry = CaptureCurrentOutcomeReducer::retryEligibility($capture);
        $articleMediaPlan = is_array($diagnostics['media_usage'] ?? null)
            ? $diagnostics['media_usage']
            : (is_array($diagnostics['media_enrichment'] ?? null) ? $diagnostics['media_enrichment'] : []);
        $blogId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        $canonicalUsageReadback = $capture->articleId === null ? [] : array_map($this->usage(...), array_values(array_filter(
            $this->usages->listByEndpoint('wp_post', $blogId . ':' . $capture->articleId),
            static fn (MediaUsage $usage): bool => $usage->activeSlot !== 'retired',
        )));
        $contentIntent = is_array($context['content_intent'] ?? null) ? $context['content_intent'] : [];
        return [
            // `status` describes the read result. Keep the persisted Capture
            // lifecycle state separate so PARTIAL/REVIEW_REQUIRED cannot
            // overwrite FOUND and become indistinguishable from transport
            // absence.
            'status' => 'found',
            'capture_id' => $capture->captureId,
            'purpose' => (string) ($context['purpose'] ?? 'EDITORIAL'),
            'intent' => is_array($context['content_intent'] ?? null) ? $context['content_intent'] : null,
            'revision' => $capture->revision, 'stage' => $capture->stage, 'capture_status' => $capture->status,
            'subject_resolution_packet' => $packet,
            'article' => $capture->articleId === null ? null : ['post_id' => $capture->articleId, 'state' => (string) ($diagnostics['publication']['status'] ?? '')],
            'article_id' => $capture->articleId,
            'post_id' => $capture->articleId,
            'content_intent' => (string) ($contentIntent['intent'] ?? ''),
            'article_media_plan' => $articleMediaPlan,
            'per_media_disposition' => array_values(array_filter((array) ($articleMediaPlan['media_dispositions'] ?? $articleMediaPlan['article_media_dispositions'] ?? []), 'is_array')),
            'canonical_usage_readback' => $canonicalUsageReadback,
            'projection_state' => ['publication' => $diagnostics['publication'] ?? null, 'final_read_back' => $diagnostics['final_read_back'] ?? null],
            'frontend_public_state' => ['public' => $completion['public_state'] ?? null, 'frontend' => $completion['frontend_state'] ?? null],
            'video' => array_values(array_unique($videos, SORT_REGULAR)), 'media' => array_values($media),
            'media_bindings' => is_array($context['media_bindings'] ?? null) ? array_values(array_map(static fn (mixed $binding): array => is_array($binding) ? [
                'target' => $binding['target'] ?? null, 'role' => $binding['role'] ?? null, 'selection_source' => $binding['selection_source'] ?? null, 'selection_policy' => $binding['selection_policy'] ?? null,
            ] : [], $context['media_bindings'])) : [],
            'owners' => $owners,
            'review' => $review,
            'blockers' => array_values(array_map('strval', (array) ($completion['blockers'] ?? $diagnostics['blockers'] ?? []))),
            'warnings' => array_values(array_map('strval', (array) ($diagnostics['publication']['warnings'] ?? $diagnostics['warnings'] ?? []))),
            'required_owners' => $completion['required_owners'] ?? [], 'missing_required_owners' => $completion['missing_required_owners'] ?? [],
            'publication' => is_array($diagnostics['publication'] ?? null) ? ['eligible' => ($diagnostics['publication']['eligible'] ?? false) === true, 'status' => (string) ($diagnostics['publication']['status'] ?? ''), 'blockers' => array_values(array_map('strval', (array) ($diagnostics['publication']['blockers'] ?? [])))] : null,
            'enrichment' => ['complete' => ($completion['complete'] ?? false) === true, 'deep_enrichment' => $diagnostics['deep_enrichment']['status'] ?? null, 'missing' => $completion['missing_required_owners'] ?? []],
            'result_packet' => $this->resultPacket($capture, $completion, $diagnostics),
            'retry' => ['eligible' => $retry['eligible'], 'reason' => $retry['reason'], 'capture_id' => $capture->captureId],
        ];
    }

    /**
     * Rebuild the read projection from current canonical owner/usage state.
     * Persisted phase receipts remain audit history; they are not a source of
     * truth for an Article or its MediaUsage after a later owner update.
     *
     * @param array<string,mixed> $completion
     * @return array<string,mixed>
     */
    private function reconcileCurrentCaptureCompletion(\NHK\Core\Domain\Capture\CaptureRecord $capture, array $completion): array
    {
        $intent = strtoupper(trim((string) (($capture->context['content_intent']['intent'] ?? ''))));
        $articleId = $capture->articleId;
        if ($articleId === null || !in_array($intent, ['IMAGE_ARTICLE', 'TEXT_ARTICLE'], true)) return $completion;

        $children = \NHK\Core\Application\Completion\CompletionCoordinator::effectiveChildren(
            array_values(array_filter((array) ($completion['children'] ?? []), 'is_array')),
        );
        $blogId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        $postKey = $blogId . ':' . $articleId;
        $currentUsages = array_values(array_filter(
            $this->usages->listByEndpoint('wp_post', $postKey),
            static fn (\NHK\Core\Domain\Media\MediaUsage $usage): bool => $usage->activeSlot !== 'retired',
        ));
        $hasEmptyPostOwner = false;
        foreach ((array) ($completion['required_owners'] ?? []) as $required) {
            if (is_array($required) && strtolower(trim((string) ($required['owner_type'] ?? $required['type'] ?? ''))) === 'wp_post' && trim((string) ($required['owner_id'] ?? $required['id'] ?? '')) === '') {
                $hasEmptyPostOwner = true;
                break;
            }
        }
        $activeMediaIds = [];
        $activeRoles = [];
        foreach ($currentUsages as $usage) {
            $activeMediaIds[$usage->mediaId] = true;
            $activeRoles[$usage->role] = true;
        }
        $captureMediaIds = [];
        foreach ($capture->assets as $asset) {
            if (!is_array($asset)) continue;
            $mediaId = trim((string) ($asset['media_id'] ?? ''));
            if ($mediaId !== '') $captureMediaIds[$mediaId] = true;
        }
        $mediaUsageComplete = $this->captureMediaUsageComplete($captureMediaIds, $activeMediaIds, diagnostics: $capture->diagnostics);

        $requiredOwners = [];
        foreach ((array) ($completion['required_owners'] ?? []) as $required) {
            if (!is_array($required)) continue;
            $type = strtolower(trim((string) ($required['owner_type'] ?? $required['type'] ?? '')));
            if ($type === '') continue;
            $id = trim((string) ($required['owner_id'] ?? $required['id'] ?? ''));
            if ($type === 'wp_post') $id = (string) $articleId;
            $requiredOwners[] = ['owner_type' => $type, 'owner_id' => $id];
        }
        if (!array_filter($requiredOwners, static fn (array $owner): bool => $owner['owner_type'] === 'wp_post')) {
            $requiredOwners[] = ['owner_type' => 'wp_post', 'owner_id' => (string) $articleId];
        }
        if ($intent === 'IMAGE_ARTICLE') {
            foreach (array_keys($captureMediaIds) as $mediaId) {
                $requiredOwners[] = ['owner_type' => 'media', 'owner_id' => $mediaId];
            }
        }
        $requiredOwners = array_values(array_unique($requiredOwners, SORT_REGULAR));

        foreach ($children as $index => $child) {
            $wrapped = is_array($child['completion'] ?? null);
            $packet = $wrapped ? $child['completion'] : $child;
            if (!is_array($packet)) continue;
            $type = strtolower(trim((string) ($packet['owner_type'] ?? '')));
            $ownerId = trim((string) ($packet['owner_id'] ?? ''));
            if ($type === 'wp_post' && ($ownerId === '' || $ownerId === (string) $articleId)) {
                $packet['owner_id'] = (string) $articleId;
                $packet['canonical_readback'] = ['id' => $articleId];
                $packet['current_outcome'] = true;
                if ($activeRoles !== [] && $mediaUsageComplete) {
                    $packet['relation_or_usage_state'] = 'COMPLETE';
                    $packet['blockers'] = $this->withoutStaleArticleMediaBlockers((array) ($packet['blockers'] ?? []));
                } elseif (!$mediaUsageComplete) {
                    $packet['relation_or_usage_state'] = 'PARTIAL';
                    $packet['blockers'] = array_values(array_unique([...array_map('strval', (array) ($packet['blockers'] ?? [])), 'MEDIAUSAGE_INCOMPLETE']));
                }
            } elseif ($type === 'media' && $ownerId !== '' && isset($activeMediaIds[$ownerId]) && $mediaUsageComplete) {
                $packet['canonical_readback'] = ['canonical_id' => $ownerId];
                $packet['relation_or_usage_state'] = 'COMPLETE';
                $packet['current_outcome'] = true;
                $packet['blockers'] = $this->withoutStaleArticleMediaBlockers((array) ($packet['blockers'] ?? []));
            } elseif ($type === 'media' && $ownerId !== '' && isset($activeMediaIds[$ownerId])) {
                $packet['relation_or_usage_state'] = 'PARTIAL';
                $packet['blockers'] = array_values(array_unique([...array_map('strval', (array) ($packet['blockers'] ?? [])), 'MEDIAUSAGE_INCOMPLETE']));
            }
            $children[$index] = $wrapped ? ['completion' => $packet, 'current_outcome' => true] : $packet;
        }
        $childMediaIds = [];
        foreach ($children as $child) {
            $packet = is_array($child['completion'] ?? null) ? $child['completion'] : $child;
            if (is_array($packet) && strtolower(trim((string) ($packet['owner_type'] ?? ''))) === 'media') {
                $childMediaIds[trim((string) ($packet['owner_id'] ?? ''))] = true;
            }
        }
        foreach (array_keys($activeMediaIds) as $mediaId) {
            if (isset($childMediaIds[$mediaId])) continue;
            $currentMedia = $this->media->findByCanonicalId($mediaId);
            $publicReady = $currentMedia instanceof \NHK\Core\Domain\Media\Media
                && $currentMedia->active
                && $currentMedia->readiness === 'ready'
                && array_filter($this->assets->listByMediaId($mediaId), static fn (\NHK\Core\Domain\Media\MediaAsset $asset): bool => $asset->visibility === 'PUBLIC') !== [];
            $children[] = [
                'owner_type' => 'media',
                'owner_id' => $mediaId,
                'canonical_readback' => ['canonical_id' => $mediaId],
                'dependency_state' => 'COMPLETE',
                'relation_or_usage_state' => 'COMPLETE',
                'public_eligible' => $publicReady,
                'frontend_verified' => $publicReady,
                'current_outcome' => true,
            ];
        }

        $aggregate = (new \NHK\Core\Application\Completion\CompletionCoordinator())->aggregateCapture(
            $capture->captureId,
            $children,
            [
                'canonical_readback' => ['canonical_id' => $capture->captureId],
                'required_owners' => $requiredOwners,
                'blockers' => $this->withoutStaleArticleMediaBlockers((array) ($completion['blockers'] ?? [])),
            ],
        );
        return $aggregate;
    }

    /** @param array<string,bool> $captureMediaIds @param array<string,bool> $activeMediaIds @param array<string,mixed> $diagnostics */
    private function captureMediaUsageComplete(array $captureMediaIds, array $activeMediaIds, array $diagnostics): bool
    {
        if ($captureMediaIds === []) return true;
        foreach (array_keys($captureMediaIds) as $mediaId) {
            if (!isset($activeMediaIds[$mediaId])) return false;
        }
        $enrichment = is_array($diagnostics['media_usage'] ?? null) ? $diagnostics['media_usage'] : (is_array($diagnostics['media_enrichment'] ?? null) ? $diagnostics['media_enrichment'] : []);
        $dispositions = array_values(array_filter((array) ($enrichment['media_dispositions'] ?? $enrichment['article_media_dispositions'] ?? []), 'is_array'));
        if ($dispositions === []) return false;
        $dispositionMediaIds = [];
        foreach ($dispositions as $disposition) {
            if (strtoupper(trim((string) ($disposition['status'] ?? ''))) !== 'APPLIED') return false;
            $mediaId = trim((string) ($disposition['media_id'] ?? ''));
            if ($mediaId === '') return false;
            $dispositionMediaIds[$mediaId] = true;
        }
        foreach (array_keys($captureMediaIds) as $mediaId) if (!isset($dispositionMediaIds[$mediaId]) || !isset($activeMediaIds[$mediaId])) return false;
        return true;
    }

    /** @param array<string,mixed> $completion @param array<string,mixed> $diagnostics @return array<string,mixed> */
    private function resultPacket(CaptureRecord $capture, array $completion, array $diagnostics): array
    {
        $complete = ($completion['complete'] ?? false) === true;
        $knowledgeStatus = strtoupper(trim((string) (($diagnostics['semantic_write_back']['status'] ?? $diagnostics['knowledge']['status'] ?? ''))));
        $publicStatus = strtolower(trim((string) (($diagnostics['final_read_back']['status'] ?? $diagnostics['publication']['status'] ?? ''))));
        return [
            'status' => $complete ? 'COMPLETE' : (strtoupper($capture->status) === 'REVIEW_REQUIRED' ? 'REVIEW_REQUIRED' : 'PARTIAL'),
            'summary' => $complete ? 'Đã hoàn tất xử lý Capture.' : 'Capture đã được tiếp nhận nhưng còn bước cần hoàn tất.',
            'article' => ['status' => $capture->articleId === null ? 'Chưa tạo bài viết.' : 'Đã tạo bài viết.'],
            'media' => ['status' => $complete ? 'Đã xử lý liên kết ảnh theo trạng thái chuẩn.' : 'Còn ảnh hoặc liên kết ảnh cần hoàn tất.'],
            'knowledge' => ['status' => in_array($knowledgeStatus, ['APPLIED', 'COMPLETED', 'REUSED', 'IDEMPOTENT'], true) ? 'Đã xử lý tri thức.' : 'Tri thức chưa hoàn tất hoặc không được yêu cầu.'],
            'public' => ['status' => $publicStatus === 'verified' ? 'Đã kiểm tra hiển thị công khai.' : 'Chưa xác nhận hiển thị công khai.'],
            'next_step' => $complete ? null : 'Tiếp tục Capture bằng cùng idempotency key.',
        ];
    }

    /** @param list<mixed> $blockers @return list<string> */
    private function withoutStaleArticleMediaBlockers(array $blockers): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $blockers), static fn (string $blocker): bool => !in_array($blocker, [
            'MEDIAUSAGE_INCOMPLETE',
            'ARTICLE_MEDIA_FEATURED_MISSING',
            'ARTICLE_MEDIA_INLINE_MISSING',
            'REQUIRED_OWNER_READBACK_UNVERIFIED',
        ], true))));
    }

    public function mediaAttachmentGet(int $attachmentId): ?array
    {
        return $this->wordpressAttachments?->read($attachmentId);
    }

    public function mediaBindingGet(string $operationId = '', string $idempotencyKey = ''): ?array
    {
        $operation = $operationId !== ''
            ? $this->mediaBindingOperations?->findByOperationId($operationId)
            : $this->mediaBindingOperations?->findByIdempotencyKey($idempotencyKey);
        return $operation?->toArray();
    }

    public function videoGet(string $id): ?array
    {
        if (!$this->ready('video') || !UuidCodec::isValid($id)) return null;
        $video = $this->videos->findByCanonicalId($id);
        return $video && $video->active && $video->hasValidPublicReference() ? ['id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'title' => $video->title] : null;
    }

    public function knowledgeGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || !UuidCodec::isValid($id)) return null;
        $claim = $this->claims->findByCanonicalId($id);
        return $claim && $claim->active && $claim->isPublic() ? ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType, 'evidence' => array_map($this->publicEvidence(...), $this->publicEvidenceByClaim($id))] : null;
    }

    public function sourceGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || $this->sources === null || !UuidCodec::isValid($id)) return null;
        $source = $this->sources->findByCanonicalId($id);
        if (!$source || !$source->active || !$source->isPublic()) return null;
        $evidence = array_values(array_filter($this->evidence->listBySource($id), function (Evidence $item): bool {
            if (!$item->active || !$item->isPublic()) return false;
            $claim = $this->claims->findByCanonicalId($item->claimId);
            return $claim !== null && $claim->active && $claim->isPublic();
        }));
        return ['id' => $source->canonicalId, 'stable_key' => $source->stableKey, 'title' => $source->title, 'type' => $source->sourceType, 'locator' => $source->locator, 'evidence' => array_map($this->publicEvidence(...), $evidence)];
    }

    public function evidenceGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || $this->sources === null || !UuidCodec::isValid($id)) return null;
        $item = $this->evidence->findByCanonicalId($id);
        if (!$item || !$item->active || !$item->isPublic()) return null;
        $claim = $this->claims->findByCanonicalId($item->claimId);
        $source = $this->sources->findByCanonicalId($item->sourceId);
        if (!$claim || !$claim->active || !$claim->isPublic() || !$source || !$source->active || !$source->isPublic()) return null;
        return $this->publicEvidence($item) + ['source_title' => $source->title, 'source_type' => $source->sourceType, 'source_locator' => $source->locator];
    }

    public function semanticResolve(array $context): array
    {
        if ($this->resolver === null) throw new \InvalidArgumentException('Semantic context resolver is unavailable.');
        return $this->resolver->resolve($context);
    }

    public function canonicalInventory(array $filters, int $limit = 50, ?string $after = null): array
    {
        if ($this->canonicalInventory === null) return ['status' => 'unavailable', 'reason' => 'CANONICAL_INVENTORY_UNAVAILABLE'];
        $page = $this->canonicalInventory->inventory($filters, $limit, $after);
        return $page->reason !== null ? ['status' => 'unavailable', 'reason' => $page->reason] : ['status' => 'available'] + $page->toArray();
    }

    public function graphInventory(array $filters, int $limit = 50, ?string $after = null): array
    {
        if ($this->graphInventory === null) return ['status' => 'unavailable', 'reason' => 'GRAPH_INVENTORY_UNAVAILABLE'];
        $report = $this->graphInventory->inventory($filters, $limit, $after);
        return $report->reason !== null ? ['status' => 'unavailable', 'reason' => $report->reason] : ['status' => 'available'] + $report->toArray();
    }

    public function relationshipRegistry(): array { return $this->relationships?->registry() ?? ['status' => 'unavailable', 'reason' => 'RELATIONSHIP_REGISTRY_UNAVAILABLE']; }
    public function relationshipList(array $filters, int $limit = 50, ?string $after = null): array { return $this->relationships?->list($filters, $limit, $after) ?? ['status' => 'unavailable', 'reason' => 'RELATIONSHIP_READ_UNAVAILABLE']; }
    public function relationshipGet(string $id, ?string $kind = null, array $context = []): array { return $this->relationships?->get($id, $kind, $context) ?? ['status' => 'unavailable', 'reason' => 'RELATIONSHIP_READ_UNAVAILABLE']; }
    public function relationshipPreview(array $input): array { return $this->relationships?->preview($input) ?? ['status' => 'unavailable', 'reason' => 'RELATIONSHIP_PREVIEW_UNAVAILABLE']; }

    public function relationBackfillDryRun(array $records): array
    {
        if ($this->relationBackfill === null) return ['status' => 'unavailable', 'reason' => 'RELATION_DRY_RUN_UNAVAILABLE'];
        return ['status' => 'available', 'read_only' => true] + $this->relationBackfill->dryRun($records)->toArray();
    }

    /** @return array{status:string,items:list<array<string,mixed>>,reason?:string} */
    public function entityNeighborhood(string $type, string $id, string $profile, int $maxHops = 2, int $limit = 50): array
    {
        if ($this->neighborhood === null || !UuidCodec::isValid($id)) return ['status' => 'unavailable', 'items' => [], 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE'];
        return $this->neighborhood->query(new NodeReference($type, $id), $profile, $maxHops, $limit);
    }

    public function search(string $term, int $page = 1, int $perPage = 20): array
    {
        $term = trim($term); $length = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if ($length < 2 || $length > 120) throw new \InvalidArgumentException('Search term must contain 2–120 characters.');
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        $posts = [];
        if (class_exists('WP_Query')) {
            $query = new \WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => $perPage, 'paged' => $page, 'ignore_sticky_posts' => true, 'orderby' => ['date' => 'DESC', 'ID' => 'DESC']]);
            $posts = array_map(static fn (\WP_Post $post): array => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 28), 'date' => get_the_date('c', $post)], $query->posts);
            $postTotal = (int) $query->found_posts;
        } else $postTotal = 0;
        $groups = ['posts' => $posts, 'entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []];
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach (LatestFirstOrder::sort($this->authority->listByType($definition->type), static fn (AuthorityEntity $entity): ?string => null, static fn (AuthorityEntity $entity): ?string => $entity->createdAt, static fn (AuthorityEntity $entity): string => $entity->canonicalId) as $entity) { $publicPayload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); if ($entity->active() && $this->matches($term, $entity->canonicalName, $entity->stableKey, $this->json($publicPayload))) $groups['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'stable_key' => $entity->stableKey, 'revision' => $entity->revision]; }
        if ($this->ready('media')) foreach (LatestFirstOrder::sort($this->media->list(), static fn (Media $media): ?string => null, static fn (Media $media): ?string => $media->createdAt, static fn (Media $media): string => $media->canonicalId) as $media) if ($media->active && $media->readiness === 'ready' && $this->matches($term, $media->canonicalName, $media->stableKey)) $groups['media'][] = ['type' => 'media', 'id' => $media->canonicalId, 'title' => $media->canonicalName, 'stable_key' => $media->stableKey];
        if ($this->ready('video')) { $videoSearch = new VideoSearchDocument($this->authority); foreach (LatestFirstOrder::sort($this->videos->list(), fn (Video $video): ?string => $this->videoPublishedAt($video), fn (Video $video): ?string => $video->createdAt, fn (Video $video): string => $video->canonicalId) as $video) if ($videoSearch->isDiscoverable($video) && $this->matches($term, ...$videoSearch->values($video))) $groups['videos'][] = ['type' => 'video', 'id' => $video->canonicalId, 'title' => $videoSearch->title($video), 'platform' => $video->platform, 'url' => $video->canonicalUrl]; }
        if ($this->ready('knowledge')) foreach (LatestFirstOrder::sort($this->claims->list(), static fn (KnowledgeClaim $claim): ?string => null, static fn (KnowledgeClaim $claim): ?string => $claim->createdAt, static fn (KnowledgeClaim $claim): string => $claim->canonicalId) as $claim) if ($claim->active && $claim->isPublic() && $this->matches($term, $claim->claimText, $claim->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $claim->canonicalId, 'title' => $claim->claimText, 'stable_key' => $claim->stableKey];
        $semanticTotals = [];
        $semanticOffset = ($page - 1) * $perPage;
        foreach (['entities', 'media', 'videos', 'knowledge'] as $group) {
            $semanticTotals[$group] = count($groups[$group]);
            $groups[$group] = array_slice($groups[$group], $semanticOffset, $perPage);
        }
        return ['query' => $term, 'page' => $page, 'per_page' => $perPage, 'post_total' => $postTotal, 'semantic_totals' => $semanticTotals, 'groups' => $groups];
    }

    private function ready(string $domain): bool
    {
        if (!$this->status) return true;
        return match ($domain) { 'authority' => $this->status->authorityStorageReady(), 'media' => $this->status->mediaStorageReady(), 'video' => $this->status->videoStorageReady(), 'knowledge' => $this->status->knowledgeStorageReady(), default => false };
    }
    private function matches(string $term, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $term) : stripos($value, $term)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
    private function videoPublishedAt(Video $video): ?string { $metadata = is_array($video->metadata) ? $video->metadata : []; $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []); return isset($source['published_at']) && is_string($source['published_at']) ? $source['published_at'] : null; }
    private function entity(AuthorityEntity $entity): array { $definition = $this->types->get($entity->entityType); $payload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'revision' => $entity->revision, 'payload' => $payload]; }
    private function asset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'storage_key' => $asset->storageKey, 'checksum' => $asset->checksum, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'visibility' => $asset->visibility, 'metadata' => $asset->metadata]; }
    private function usage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'media_id' => $usage->mediaId, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'role' => $usage->role, 'sort_order' => $usage->sortOrder, 'alt' => $usage->altText, 'caption' => $usage->caption, 'keyword_groups' => $usage->keywordGroups, 'selection_source' => $usage->selectionSource, 'selection_policy' => $usage->selectionPolicy, 'active_slot' => $usage->activeSlot, 'active' => $usage->activeSlot !== 'retired']; }
    private function publicAsset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'public_url' => '/media/asset/' . $asset->assetId . '/']; }
    private function publicUsage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'media_id' => $usage->mediaId, 'target_type' => $usage->endpointType, 'target_id' => $usage->endpointKey, 'role' => $usage->role, 'placement_key' => $usage->placementKey, 'sort_order' => $usage->sortOrder, 'active' => $usage->activeSlot !== 'retired', 'revision' => $usage->revision]; }
    private function evidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator, 'metadata' => $evidence->metadata, 'active' => $evidence->active, 'revision' => $evidence->revision]; }
    private function publicEvidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator]; }
    private function publicEvidenceByClaim(string $claimId): array { return array_values(array_filter($this->evidence->listByClaim($claimId), function (Evidence $item): bool { if (!$item->active || !$item->isPublic() || $this->sources === null) return false; $source = $this->sources->findByCanonicalId($item->sourceId); $claim = $this->claims->findByCanonicalId($item->claimId); return $source !== null && $source->active && $source->isPublic() && $claim !== null && $claim->active && $claim->isPublic(); })); }
}
