<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Media\MediaBindingPort;
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Capture\CapturePurpose;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Governance\Exception\{GovernanceException, ProposalIdempotencyConflict, ProposalIdempotencyStaleBinding, ProposalSubjectBindingInvalid};
use NHK\Core\Domain\Video\VideoRelationEvidenceRequired;

/**
 * Shared Capture orchestration. Input/media adapters are injected at the edge;
 * resolution, retrieval and composition remain reusable for future verticals.
 */
final class EditorialCaptureCoordinator
{
    /** @var array<string,float> */
    private array $phaseStartedAt = [];
    private ?string $activeReceiptPhase = null;
    private CompletionCoordinator $completion;

    /** @param callable(array<string,mixed>):array $physicalIngest @param callable(array<string,mixed>):array $draftCreator @param callable(array<string,mixed>):array $semanticWriteBack @param callable(array<string,mixed>):array $mediaReconcile @param callable(array<string,mixed>):array $publicationGate @param callable(array<string,mixed>):array $finalReadBack @param (callable(array<string,mixed>):array)|null $draftUpdater @param (callable(array<string,mixed>):array)|null $mediaAdoption @param (callable(array<string,mixed>):array)|null $publisher @param (callable(array<string,mixed>):array)|null $videoEnrichment @param (callable(array<string,mixed>):array)|null $videoPublicationVerifier */
    public function __construct(
        private CaptureRepository $captures,
        private $physicalIngest,
        private $draftCreator,
        private TextInputInterpreter $interpreter,
        private SubjectResolutionService $subjects,
        private ClaimRetrievalEngine $claims,
        private $semanticWriteBack,
        private ArticleComposer $composer,
        private $mediaReconcile,
        private $publicationGate,
        private $finalReadBack,
        private $draftUpdater = null,
        private $mediaAdoption = null,
        private $publisher = null,
        private ?McpDocumentationRegistry $documentation = null,
        private $videoEnrichment = null,
        private $videoPublicationVerifier = null,
        ?CompletionCoordinator $completion = null,
        private ?ClockTypeShadowClassifier $clockTypeShadowClassifier = null,
        private ?ContentIntentRouter $contentIntentRouter = null,
        private ?\NHK\Core\Application\Media\VisualOpportunityDetector $visualOpportunityDetector = null,
        private ?\NHK\Core\Application\Media\VisualSupportRequirementService $visualSupportRequirements = null,
        private ?MediaBindingPort $mediaBindingService = null,
        private ?StagingAcceptanceScopeVerifier $stagingScopeVerifier = null,
    ) { $this->completion = $completion ?? new CompletionCoordinator(); }

    /** @param array<string,mixed> $input */
    public function execute(array $input): CaptureRecord
    {
        if (CapturePurposePolicy::resolve($input) !== CapturePurpose::EDITORIAL) throw new \InvalidArgumentException('AUTHORITY_CAPTURE_REQUIRES_AUTHORITY_OWNER');
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') throw new \InvalidArgumentException('Capture idempotency key is required.');
        $this->documentation?->assertCheckpoint((array) ($input['documentation_checkpoint'] ?? []));
        $fingerprint = $this->fingerprint($input);
        $existing = $this->captures->findByIdempotencyKey($key);
        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint, $fingerprint)) return $this->conflict($existing, $fingerprint);
            if ($existing->stage === CaptureStage::READY_FOR_PUBLICATION->value || $existing->stage === CaptureStage::PUBLISHED->value) return $existing;
            return $this->run($existing, $input);
        }
        $record = $this->captures->create(new CaptureRecord(
            UuidCodec::newV7(),
            $key,
            $fingerprint,
            CaptureStage::RECEIVED->value,
            'RECEIVED',
            null,
            null,
            [],
            [
                'raw_input' => trim((string) ($input['text'] ?? $input['content'] ?? '')),
                'purpose' => CapturePurpose::EDITORIAL->value,
                'subject_hints' => is_array($input['subject_hints'] ?? null) ? array_values($input['subject_hints']) : [],
                'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [],
                'title' => trim((string) ($input['title'] ?? '')),
                'excerpt' => trim((string) ($input['excerpt'] ?? '')),
                'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                'media_bindings' => is_array($input['media_bindings'] ?? null) ? $input['media_bindings'] : [],
                'media_operations' => is_array($input['media_operations'] ?? null) ? $input['media_operations'] : [],
                'documentation_checkpoint' => is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [],
                // Retry may re-enter a child owner without accepting a new
                // editorial payload. Persist only workflow controls that are
                // not already represented by Capture context/assets; the
                // native Post remains the sole Article-body owner.
                'original_request' => [
                    'intent' => trim((string) ($input['intent'] ?? '')),
                    'publish' => ($input['publish'] ?? false) === true,
                    'video' => is_array($input['video'] ?? null) ? $this->withoutBody($input['video']) : [],
                ],
            ],
        ));
        return $this->run($record, $input);
    }

    /** Resume the persisted Capture checkpoint without creating an addendum. */
    public function retry(CaptureRecord $record, array $input): CaptureRecord
    {
        $this->documentation?->assertCheckpoint((array) ($input['documentation_checkpoint'] ?? []));
        return $this->run($record, $input);
    }

    /** Continue an existing Capture without repeating physical or draft creation. */
    public function continueWithAddendum(CaptureRecord $record, array $input): CaptureRecord
    {
        $continuation = is_array($record->context['continuation_state'] ?? null) ? $record->context['continuation_state'] : [];
        $original = trim((string) ($continuation['raw_input'] ?? $record->context['raw_input'] ?? ''));
        $addendum = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $replacement = ($metadata['editorial_replacement'] ?? false) === true
            && in_array(strtoupper(trim((string) ($input['intent'] ?? 'TEXT_ARTICLE'))), ['TEXT_ARTICLE', 'IMAGE_ARTICLE'], true);
        // An editorial replacement is a governed continuation for an existing
        // draft whose public body must be replaced atomically (for example to
        // remove internal jargon). Knowledge deltas remain append-only.
        $input['text'] = $replacement
            ? $addendum
            : trim(implode("\n\n", array_values(array_filter([$original, $addendum], static fn (string $value): bool => $value !== ''))));
        $newSubjectHints = is_array($input['subject_hints'] ?? null) ? array_values(array_filter(array_map('strval', $input['subject_hints']), static fn (string $hint): bool => trim($hint) !== '')) : [];
        // An explicit continuation subject is a correction/clarification for
        // this bounded rerun. Do not keep a stale prior variant in the
        // resolution set and accidentally turn a correction into ambiguity.
        $input['subject_hints'] = $newSubjectHints !== []
            ? array_values(array_unique($newSubjectHints))
            : array_values(array_unique(array_map('strval', is_array($continuation['subject_hints'] ?? null) ? $continuation['subject_hints'] : ($record->context['subject_hints'] ?? []))));
        $input['observations'] = array_merge(
            is_array($continuation['observations'] ?? null) ? $continuation['observations'] : (is_array($record->context['observations'] ?? null) ? $record->context['observations'] : []),
            is_array($input['observations'] ?? null) ? $input['observations'] : [],
        );
        if (trim((string) ($input['title'] ?? '')) === '') {
            $input['title'] = trim((string) ($record->context['title'] ?? '')) !== ''
                ? (string) $record->context['title']
                : (string) ($record->diagnostics['composition']['title'] ?? '');
        }
        if (trim((string) ($input['excerpt'] ?? '')) === '') $input['excerpt'] = (string) ($record->context['excerpt'] ?? ($record->diagnostics['article_draft']['excerpt'] ?? ''));
        if (strtoupper((string) ($input['followup_mode'] ?? '')) === 'ATTACH_ASSETS' && !isset($input['visual_support_contexts'])) {
            $input['visual_support_contexts'] = array_values(array_filter(array_map(static function (mixed $opportunity): array {
                $opportunity = is_array($opportunity) ? $opportunity : [];
                $subject = is_array($opportunity['subject'] ?? null) ? $opportunity['subject'] : [];
                return ['subject_type' => (string) ($subject['type'] ?? ''), 'subject_id' => (string) ($subject['id'] ?? ''), 'scope' => (string) ($opportunity['scope'] ?? ''), 'facet' => (string) ($opportunity['facet'] ?? ''), 'feature_key' => (string) ($opportunity['feature_key'] ?? ''), 'visual_intent' => (string) ($opportunity['visual_intent'] ?? '')];
            }, (array) ($record->diagnostics['visual_opportunities'] ?? [])), static fn (array $context): bool => $context['subject_type'] !== '' && $context['subject_id'] !== '' && $context['feature_key'] !== ''));
        }
        return $this->run($record, $input);
    }

    /** @param array<string,mixed> $input */
    private function run(CaptureRecord $record, array $input): CaptureRecord
    {
        $text = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $assets = $record->assets;
        $diagnostics = $record->diagnostics;
        $receipts = $record->phaseReceipts;
        try {
            $followupItems = array_values(array_filter((array) ($input['asset_followup_items'] ?? []), 'is_array'));
            if ($followupItems !== []) {
                $existingKeys = array_fill_keys(array_map([$this, 'assetIdentity'], $assets), true);
                foreach ($followupItems as $item) {
                    $key = $this->assetIdentity($item);
                    if ($key !== '' && isset($existingKeys[$key])) continue;
                    $assets[] = $item;
                    if ($key !== '') $existingKeys[$key] = true;
                }
                $this->beginPhase('ASSET_FOLLOWUP');
                $diagnostics['asset_followup'] = ['status' => 'verified', 'items' => count($followupItems), 'manifest' => $this->withoutBody((array) ($input['asset_followup_manifest'] ?? [])), 'context_hint' => $this->withoutBody((array) ($input['visual_context'] ?? []))];
                $record = $this->save($record, $record->stage, $assets, $diagnostics, $receipts, 'ASSET_FOLLOWUP', $record->articleId, $record->articleStateToken);
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
            }
            if ($assets === [] && !$this->hasStage($record, CaptureStage::ASSETS_STORED)) {
                $this->beginPhase('ASSETS_STORED');
                $manifest = ($this->physicalIngest)($input);
                $assets = is_array($manifest['items'] ?? null) ? array_values($manifest['items']) : (is_array($manifest) && array_is_list($manifest) ? $manifest : []);
                $diagnostics['physical_ingest'] = $this->withoutBody($manifest);
                $record = $this->save($record, CaptureStage::ASSETS_STORED, $assets, $diagnostics, $receipts, 'ASSETS_STORED');
            }
            // An exact typed Media binding is a deterministic fast path. It
            // deliberately runs before interpretation/Graph/Claim work so a
            // user-selected representative cannot time out in semantic
            // discovery after the physical Media is already available.
            if ($this->mediaBindingService !== null && strtoupper(trim((string) ($input['intent'] ?? ''))) === 'MEDIA_ENRICHMENT' && is_array($input['media_bindings'] ?? null) && $input['media_bindings'] !== [] && (array) ($input['media_operations'] ?? []) === []) {
                return $this->runTypedMediaBindingFastPath($record, $input, $assets, $diagnostics, $receipts);
            }
            $this->beginPhase('INTERPRETED');
            $interpretation = $this->interpreter->interpret($text, $assets, is_array($input['subject_hints'] ?? null) ? $input['subject_hints'] : [], is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
            $diagnostics['interpretation'] = $this->withoutBody($interpretation);
            $intentRouter = $this->contentIntentRouter ?? new ContentIntentRouter();
            $persistedIntent = is_array($record->context['content_intent'] ?? null)
                ? $record->context['content_intent']
                : (is_array($diagnostics['content_intent'] ?? null) ? $diagnostics['content_intent'] : []);
            $intent = ($input['existing_capture_continuation'] ?? false) === true && trim((string) ($persistedIntent['intent'] ?? '')) !== ''
                ? $intentRouter->reusePersisted($persistedIntent, $input, $assets)
                : $intentRouter->route($input, $interpretation, $assets);
            $diagnostics['content_intent'] = $intent;
            if (($intent['intent_reused'] ?? false) === true) $diagnostics['capture_intent_reused'] = strtoupper((string) ($intent['intent'] ?? ''));
            $stagingScope = null;
            // An Article target is intentionally deferred until the native
            // Article owner exists.  The staging guard must remain exact;
            // only the orchestration point moves to the first canonical
            // Article read-back.
            $deferredArticleBindings = $this->hasDeferredArticleBindings($input);
            if ($this->stagingScopeVerifier !== null && is_array($input['media_bindings'] ?? null) && $input['media_bindings'] !== [] && !$deferredArticleBindings) {
                $scopeInput = $input;
                $scopeInput['intent'] = strtoupper(trim((string) ($intent['intent'] ?? '')));
                $stagingScope = $this->stagingScopeVerifier->forCapture($record, $scopeInput, $assets);
                if ($stagingScope !== null) {
                    $input['staging_acceptance'] = $stagingScope;
                    $diagnostics['staging_acceptance'] = ['status' => 'verified', 'fingerprint' => (string) ($stagingScope['fingerprint'] ?? '')];
                }
            }
            $record = $this->save(
                $record,
                CaptureStage::INTERPRETED,
                $assets,
                $diagnostics,
                $receipts,
                'INTERPRETED',
                $record->articleId,
                $record->articleStateToken,
                'IN_PROGRESS',
                null,
                $record->context + ['content_intent' => $intent] + ($stagingScope !== null ? ['staging_acceptance' => $stagingScope] : []),
            );
            if (($intent['status'] ?? '') !== 'resolved') {
                return $this->save($record, CaptureStage::INTERPRETED, $assets, $diagnostics, $receipts, 'INTERPRETED', $record->articleId, $record->articleStateToken, 'REVIEW_REQUIRED');
            }
            $articleRequired = ($intent['article_required'] ?? false) === true;
            $videoInput = is_array($input['video'] ?? null) ? $input['video'] : [];
            // Resolve before any draft/media writer. A UUID remains the
            // selected identity, but contradictory explicit text must stop
            // the workflow fail-closed.
            $preflightResolution = $this->subjects->resolve(array_values(array_unique(array_merge(
                (array) ($interpretation['primary_subject_hints'] ?? []),
                (array) ($interpretation['secondary_subject_hints'] ?? []),
                (array) ($interpretation['entity_mentions'] ?? []),
                [trim((string) ($input['title'] ?? ''))],
                $this->videoSubjectHints($videoInput),
            ))));
            if (($preflightResolution['status'] ?? '') === 'conflict') {
                $diagnostics['subjects'] = $preflightResolution;
                $diagnostics['failure_code'] = 'SUBJECT_CONFLICT_REVIEW_REQUIRED';
                return $this->save($record, CaptureStage::INTERPRETED, $assets, $diagnostics, $receipts, 'SUBJECT_CONFLICT_REVIEW_REQUIRED', $record->articleId, $record->articleStateToken, 'REVIEW_REQUIRED');
            }
            if ($articleRequired && $record->articleId === null) {
                $this->beginPhase('DRAFT_CREATED');
                $draft = ($this->draftCreator)([
                    'capture_id' => $record->captureId,
                    'idempotency_key' => $record->captureId . ':article',
                    'title' => trim((string) ($input['title'] ?? '')),
                    'content' => $text,
                    'excerpt' => trim((string) ($input['excerpt'] ?? '')),
                    'research' => ['ready_for_draft' => true, 'capture_id' => $record->captureId],
                ]);
                $articleId = (int) ($draft['post_id'] ?? $draft['post']['post_id'] ?? 0);
                if ($articleId < 1) throw new \RuntimeException('ARTICLE_DRAFT_READBACK_UNAVAILABLE');
                $diagnostics['draft'] = $this->withoutBody($draft);
                $record = $this->save($record, CaptureStage::DRAFT_CREATED, $assets, $diagnostics, $receipts, 'DRAFT_CREATED', $articleId, (string) ($draft['state_token'] ?? ''));
            }
            if ($articleRequired && $record->articleId !== null && $deferredArticleBindings) {
                // Resolve the deferred Article reference from the server-owned
                // draft/read-back identity.  The client never supplies or
                // invents this target ID.
                [$input, $articleBindings] = $this->resolveDeferredArticleBindings($input, $record);
                foreach ($articleBindings as $binding) {
                    if (!is_array($binding)) continue;
                    $index = is_array($binding['media_ref'] ?? null) ? (int) ($binding['media_ref']['item_index'] ?? -1) : -1;
                    if (!isset($assets[$index]) || !is_array($assets[$index])) continue;
                    $seo = is_array($binding['seo'] ?? null) ? $binding['seo'] : [];
                    $assets[$index]['media_context'] = array_replace(is_array($assets[$index]['media_context'] ?? null) ? $assets[$index]['media_context'] : [], array_filter([
                        'title' => $seo['title'] ?? null,
                        'alt_text' => $seo['alt_text'] ?? null,
                        'caption' => $seo['caption'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null));
                    if (array_key_exists('sort_order', $binding)) $assets[$index]['sort_order'] = max(0, (int) $binding['sort_order']);
                }
                if ($this->stagingScopeVerifier !== null && $articleBindings !== []) {
                    $scopeInput = $input;
                    $scopeInput['media_bindings'] = $articleBindings;
                    $stagingScope = $this->stagingScopeVerifier->forCapture($record, $scopeInput, $assets);
                    if ($stagingScope !== null) {
                        $input['staging_acceptance'] = $stagingScope;
                        $diagnostics['staging_acceptance'] = ['status' => 'verified', 'fingerprint' => (string) ($stagingScope['fingerprint'] ?? ''), 'deferred_article_reference' => true];
                        $record = $this->save($record, CaptureStage::DRAFT_CREATED, $assets, $diagnostics, $receipts, 'DRAFT_CREATED', $record->articleId, $record->articleStateToken, 'IN_PROGRESS', null, $record->context + ['staging_acceptance' => $stagingScope]);
                        $diagnostics = $record->diagnostics;
                        $receipts = $record->phaseReceipts;
                    }
                }
            }
            if (!array_key_exists('media_adoption', $diagnostics) || ($followupItems !== [] && ($input['asset_followup_replay'] ?? false) !== true)) {
                $this->beginPhase('MEDIA_ADOPTED');
                $adopted = [];
                $followupKeys = array_fill_keys(array_map([$this, 'assetIdentity'], $followupItems), true);
                $hasPriorAdoption = array_key_exists('media_adoption', $diagnostics);
                foreach ($assets as $asset) {
                    if (!is_array($asset)) continue;
                    $adoptedAsset = $asset;
                    $isFollowupAsset = $followupItems !== [] && isset($followupKeys[$this->assetIdentity($asset)]);
                    $reusedCanonicalAsset = trim((string) ($asset['media_id'] ?? '')) !== ''
                        && (int) ($asset['attachment_id'] ?? 0) > 0
                        && ($asset['attachment_readback_status'] ?? '') === 'verified';
                    if (is_callable($this->mediaAdoption) && isset($asset['attachment_id']) && (!$hasPriorAdoption || $isFollowupAsset) && !$reusedCanonicalAsset) {
                        $adoption = ($this->mediaAdoption)([
                            'capture_id' => $record->captureId,
                            'attachment_id' => (int) $asset['attachment_id'],
                            'asset' => $asset,
                            'visual_context' => is_array($asset['visual_context'] ?? null) ? $asset['visual_context'] : (is_array($input['visual_context'] ?? null) ? $input['visual_context'] : null),
                            'visual_support_contexts' => is_array($input['visual_support_contexts'] ?? null) ? $input['visual_support_contexts'] : [],
                        ]);
                        $adoptedAsset['media_adoption'] = $this->withoutBody($adoption);
                        if (isset($adoption['media_id'])) $adoptedAsset['media_id'] = (string) $adoption['media_id'];
                    }
                    $adopted[] = $adoptedAsset;
                }
                $assets = $adopted;
                $diagnostics['media_adoption'] = [
                    'status' => is_callable($this->mediaAdoption) || $assets === [] ? 'verified' : 'read_back_required',
                    'items' => count($adopted),
                ];
                $record = $this->save($record, CaptureStage::MEDIA_ADOPTED, $assets, $diagnostics, $receipts, 'MEDIA_ADOPTED', $record->articleId, $record->articleStateToken);
            }
            $this->beginPhase('SUBJECTS_RESOLVED');
            $resolution = $this->subjects->resolve(array_values(array_unique(array_merge(
                (array) ($interpretation['primary_subject_hints'] ?? []),
                (array) ($interpretation['secondary_subject_hints'] ?? []),
                (array) ($interpretation['entity_mentions'] ?? []),
                [trim((string) ($input['title'] ?? ''))],
                $this->videoSubjectHints($videoInput),
            ))));
            if ($this->isVideoOnlyResume($input)) {
                $locked = is_array($record->diagnostics['subjects'] ?? null) ? $record->diagnostics['subjects'] : [];
                $lockedPrimary = is_array($locked['primary'] ?? null) ? $locked['primary'] : [];
                if (UuidCodec::isValid((string) ($lockedPrimary['id'] ?? '')) && trim((string) ($lockedPrimary['type'] ?? '')) !== '') {
                    $resolution = $locked + [
                        'status' => 'resolved',
                        'primary' => $lockedPrimary,
                        'subjects' => [$lockedPrimary],
                        'resolved' => [$lockedPrimary],
                    ];
                }
            }
            $diagnostics['subjects'] = $resolution;
            $record = $this->save($record, CaptureStage::SUBJECTS_RESOLVED, $assets, $diagnostics, $receipts, 'SUBJECTS_RESOLVED', $record->articleId, $record->articleStateToken);

            $hasVideoAsset = array_filter($assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video') !== [];
            if (is_callable($this->videoEnrichment) && $videoInput !== [] && !$hasVideoAsset) {
                $this->beginPhase('VIDEO_ENRICHED');
                $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'VIDEO_ENRICHED');
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
                $videoManifest = ($this->videoEnrichment)([
                    'capture_id' => $record->captureId,
                    'video' => $videoInput,
                    'subject_resolution' => $resolution,
                    'raw_input' => $text,
                    'editorial_title' => trim((string) ($input['title'] ?? '')),
                    'compliance_note' => trim((string) ((is_array($input['metadata'] ?? null) ? ($input['metadata']['compliance_note'] ?? '') : ''))),
                ]);
                $videoItems = is_array($videoManifest['items'] ?? null) ? array_values(array_filter($videoManifest['items'], 'is_array')) : [];
                if ($videoItems !== []) $assets = array_merge($assets, $videoItems);
                $handoff = $this->videoSubjectHandoff($resolution, $videoManifest, $videoItems, $this->isVideoOnlyResume($input));
                if ($handoff !== null) {
                    $resolution = $handoff;
                    $diagnostics['subjects'] = $resolution;
                }
                $diagnostics['video_enrichment'] = $this->withoutBody($videoManifest);
                $record = $this->save($record, CaptureStage::SUBJECTS_RESOLVED, $assets, $diagnostics, $receipts, 'VIDEO_ENRICHED', $record->articleId, $record->articleStateToken);
            }

            // Clock-Type is a sibling shadow diagnostic of the resolved
            // Capture subject. It must run after the video handoff has locked
            // the primary subject and is never fed back into any writer.
            if ($this->clockTypeShadowClassifier !== null) {
                $shadow = $this->clockTypeShadowClassifier->resolve([
                    'capture_id' => $record->captureId,
                    'raw_input' => $text,
                    'title' => trim((string) ($input['title'] ?? '')),
                    'subject_resolution' => $resolution,
                    'interpretation' => $interpretation,
                    'assets' => $assets,
                    'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [],
                    'brand_context' => $input['brand_context'] ?? null,
                    'classification_uuid' => $input['classification_uuid'] ?? null,
                    'classification_stable_key' => $input['classification_stable_key'] ?? null,
                    'clock_type_name' => $input['clock_type_name'] ?? null,
                    'clock_type_hints' => $input['clock_type_hints'] ?? [],
                ]);
                $diagnostics['semantic_diagnostics']['clock_type_shadow'] = $shadow->toArray();
            }

            $visualOpportunities = $this->visualOpportunityDetector?->detect($text, $interpretation, $resolution) ?? [];
            $visualRequirements = [];
            $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
            if ($visualOpportunities !== [] && $this->visualSupportRequirements !== null) {
                foreach ($visualOpportunities as $opportunity) {
                    $requirement = $this->visualSupportRequirements->require((string) $primary['id'], (string) $opportunity['scope'], (string) $opportunity['facet'], (string) $opportunity['feature_key'], (string) $opportunity['visual_intent'], [
                        'consumer' => $record->articleId !== null ? ['endpoint_type' => 'wp_post', 'endpoint_key' => (string) $record->articleId] : ['endpoint_type' => 'capture', 'endpoint_key' => $record->captureId],
                        'feature_label' => (string) ($opportunity['feature_label'] ?? ''), 'recommended_view' => (string) ($opportunity['recommended_view'] ?? ''), 'reason' => (string) ($opportunity['reason'] ?? ''), 'priority' => (int) ($opportunity['priority'] ?? 0), 'potential_reuse' => $opportunity['potential_reuse'] ?? [], 'opportunity_source' => 'editorial_capture',
                    ], (string) $primary['type']);
                    $visualRequirements[] = ['requirement_id' => $requirement->canonicalId, 'state' => $requirement->state, 'revision' => $requirement->revision, 'feature_key' => $requirement->featureKey];
                }
            }
            $diagnostics['visual_opportunities'] = $visualOpportunities;
            $diagnostics['visual_support'] = ['status' => $visualRequirements === [] ? 'not_requested' : 'optional_enrichment', 'requirements' => $visualRequirements];

            $inputMetadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
            $semanticContext = ['capture_id' => $record->captureId, 'article_id' => $record->articleId, 'article_endpoint_key' => $record->articleId !== null ? ((function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1) . ':' . (int) $record->articleId) : '', 'raw_input' => $text, 'continuation_delta_text' => trim((string) ($input['continuation_delta_text'] ?? '')), 'assets' => $assets, 'media_bindings' => is_array($input['media_bindings'] ?? null) ? $input['media_bindings'] : [], 'media_operations' => is_array($input['media_operations'] ?? null) ? $input['media_operations'] : [], 'interpretation' => $interpretation, 'subject_resolution' => $resolution, 'content_intent' => $intent, 'visual_opportunities' => $visualOpportunities, 'visual_support' => $diagnostics['visual_support'], 'visual_context' => is_array($input['visual_context'] ?? null) ? $input['visual_context'] : [], 'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [], 'provenance_packets' => is_array($inputMetadata['provenance_packets'] ?? null) ? $inputMetadata['provenance_packets'] : [], 'existing_capture_continuation' => ($input['existing_capture_continuation'] ?? false) === true, 'continuation_idempotency_key' => (string) ($input['continuation_idempotency_key'] ?? ''), 'governance' => is_array($input['governance'] ?? null) ? $input['governance'] : [], 'prior_diagnostics' => $diagnostics, 'phase_receipts' => $receipts];
            $isMediaEnrichment = strtoupper(trim((string) ($intent['intent'] ?? ''))) === 'MEDIA_ENRICHMENT';
            if ($isMediaEnrichment) {
                // MEDIA_ENRICHMENT owns Media and MediaUsage only. Do not
                // enter the unrelated claim/Governance boundary or create an
                // Article while repairing an existing attachment.
                $retrieved = ['status' => 'not_requested', 'items' => [], 'selected_claims' => []];
                $diagnostics['claim_retrieval'] = $retrieved;
                $record = $this->save($record, CaptureStage::KNOWLEDGE_RETRIEVED, $assets, $diagnostics, $receipts, 'KNOWLEDGE_RETRIEVED', $record->articleId, $record->articleStateToken, 'SKIPPED');
                $writes = ['status' => 'SKIPPED', 'writes' => [], 'blockers' => [], 'canonical_readback' => null];
                $diagnostics['semantic_write_back'] = $writes;
                $record = $this->save($record, CaptureStage::SEMANTICS_RECONCILED, $assets, $diagnostics, $receipts, 'SEMANTICS_RECONCILED', $record->articleId, $record->articleStateToken, 'SKIPPED');
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
                $videoPublication = ['status' => 'not_requested', 'items' => [], 'blockers' => []];
                $diagnostics['video_publication'] = $videoPublication;
                $diagnostics['deep_enrichment'] = ['status' => 'NOT_REQUESTED', 'visual_support' => ['status' => 'not_requested', 'requirements' => []], 'knowledge_reuse' => [], 'article_reuse_internal_link' => [], 'new_deep_content_opportunity' => null];
            } else {
                $this->beginPhase('KNOWLEDGE_RETRIEVED');
                $retrieved = $this->claims->retrieve($semanticContext);
                $diagnostics['claim_retrieval'] = $retrieved;
                $record = $this->save($record, CaptureStage::KNOWLEDGE_RETRIEVED, $assets, $diagnostics, $receipts, 'KNOWLEDGE_RETRIEVED', $record->articleId, $record->articleStateToken);

                $this->beginPhase('SEMANTICS_RECONCILED');
                $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'SEMANTICS_RECONCILED');
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
                $writes = ($this->semanticWriteBack)($semanticContext + ['retrieval' => $retrieved]);
                // Child Governance receipts are persisted by the continuation
                // service through the same Capture repository. Refresh the
                // optimistic revision before the coordinator writes its result.
                $latest = $this->captures->findById($record->captureId);
                if ($latest !== null) {
                    $record = $latest;
                    $receipts = $record->phaseReceipts;
                }
                $diagnostics['semantic_write_back'] = $this->withoutBody($writes);
                $semanticStatus = (string) ($writes['status'] ?? 'COMPLETED');
                $record = $this->save($record, CaptureStage::SEMANTICS_RECONCILED, $assets, $diagnostics, $receipts, 'SEMANTICS_RECONCILED', $record->articleId, $record->articleStateToken, $semanticStatus);
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
                if (in_array((string) ($writes['status'] ?? ''), ['FAILED_RETRYABLE', 'SYSTEM_BLOCKED'], true)) {
                    return $this->save($record, CaptureStage::SEMANTICS_RECONCILED, $assets, $diagnostics, $receipts, 'SEMANTICS_RECONCILED', $record->articleId, $record->articleStateToken, (string) $writes['status']);
                }

                $videoPublication = is_callable($this->videoPublicationVerifier)
                    ? ($this->videoPublicationVerifier)(['capture_id' => $record->captureId, 'assets' => $assets, 'subject_resolution' => $resolution, 'semantic_write_back' => $writes])
                    : ['status' => 'not_requested', 'items' => [], 'blockers' => []];
                $diagnostics['video_publication'] = $this->withoutBody($videoPublication);
                $diagnostics['deep_enrichment'] = $this->deepEnrichment($retrieved, $writes, [], $visualOpportunities);
            }

            if (!$articleRequired && $record->articleId === null) {
                $media = [];
                if (strtoupper(trim((string) ($intent['intent'] ?? ''))) === 'MEDIA_ENRICHMENT') {
                    if (!is_callable($this->mediaReconcile)) throw new \RuntimeException('MEDIA_ENRICHMENT_RECONCILIATION_UNAVAILABLE');
                    $this->beginPhase('MEDIA_RECONCILED');
                    $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'MEDIA_RECONCILED');
                    $assets = $record->assets;
                    $diagnostics = $record->diagnostics;
                    $receipts = $record->phaseReceipts;
                    $media = ($this->mediaReconcile)([
                        'capture' => $record->toArray(),
                        'article_id' => null,
                        'assets' => $assets,
                        'subject_resolution' => $resolution,
                        'content_intent' => $intent,
                        'semantic' => $retrieved,
                        'semantic_write_back' => $writes,
                    ]);
                    $diagnostics['media_enrichment'] = $this->withoutBody($media);
                    $mediaStatus = strtoupper(trim((string) ($media['status'] ?? '')));
                    $record = $this->save($record, 'MEDIA_RECONCILED', $assets, $diagnostics, $receipts, 'MEDIA_RECONCILED', $record->articleId, $record->articleStateToken, $mediaStatus === 'RECONCILED' ? 'COMPLETED' : 'PARTIAL');
                    $assets = $record->assets;
                    $diagnostics = $record->diagnostics;
                    $receipts = $record->phaseReceipts;
                }
                return $this->finishNonArticleIntent($record, $assets, $diagnostics, $receipts, $intent, $retrieved, $writes, $videoPublication, $resolution, $media);
            }

            $videoThumbnailFallback = $this->eligibleVideoThumbnailFallback($assets, $videoPublication);

            $observations = array_merge($semanticContext['observations'], is_array($interpretation['media_observations'] ?? null) ? $interpretation['media_observations'] : []);
            $this->beginPhase('COMPOSED');
            $composition = $this->composer->compose($text, $observations, $retrieved['selected_claims'] ?? [], ['title' => (string) ($input['title'] ?? ''), 'excerpt' => (string) ($input['excerpt'] ?? ''), 'asset_count' => count($assets), 'assets' => $assets, 'visual_opportunities' => $visualOpportunities, 'prior_composition' => is_array($diagnostics['composition'] ?? null) ? $diagnostics['composition'] : []]);
            $diagnostics['composition'] = ['title' => $composition['title'], 'claim_trace' => $composition['claim_trace'], 'research_snapshot' => $composition['research_snapshot'], 'managed_sections' => $composition['managed_sections'] ?? []];
            $diagnostics['article_draft'] = ['title' => $composition['title'], 'excerpt' => $composition['excerpt'], 'content_available' => true];
            if (is_callable($this->draftUpdater) && $record->articleId !== null && $record->articleStateToken !== null) {
                $updatedDraft = ($this->draftUpdater)([
                    'capture_id' => $record->captureId,
                    'article_id' => $record->articleId,
                    'expected_state_token' => $record->articleStateToken,
                    'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                    'fields' => [
                        'post_title' => $composition['title'],
                        'post_content' => $composition['content'],
                        'post_excerpt' => $composition['excerpt'],
                        ...$this->editorialFields($input),
                    ],
                ]);
                if (($updatedDraft['ok'] ?? false) !== true) throw new \RuntimeException((string) ($updatedDraft['reason'] ?? 'ARTICLE_DRAFT_UPDATE_FAILED'));
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, (string) ($updatedDraft['state_token'] ?? $record->articleStateToken));
            } elseif (!$this->hasStage($record, CaptureStage::COMPOSED)) {
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, $record->articleStateToken);
            }

            $mediaContext = ['capture' => $record->toArray(), 'capture_record' => $record, 'article_id' => $record->articleId, 'assets' => $assets, 'media_bindings' => is_array($input['media_bindings'] ?? null) ? $input['media_bindings'] : [], 'article_media_bindings' => is_array($input['article_media_bindings'] ?? null) ? $input['article_media_bindings'] : [], 'media_operations' => is_array($input['media_operations'] ?? null) ? $input['media_operations'] : [], 'subject_resolution' => $resolution, 'subject_resolution_packet' => $resolution['primary'] ?? null, 'content_intent' => $intent, 'composition' => $this->withoutBody($composition), 'visual_opportunities' => $visualOpportunities, 'visual_support' => $diagnostics['visual_support'], 'capture_fingerprint' => $record->requestFingerprint, 'staging_acceptance' => is_array($input['staging_acceptance'] ?? null) ? $input['staging_acceptance'] : null];
            if (is_array($mediaContext['staging_acceptance']) && isset($mediaContext['staging_acceptance']['payload_fingerprint'])) $mediaContext['payload_fingerprint'] = $mediaContext['staging_acceptance']['payload_fingerprint'];
            if ($videoThumbnailFallback !== null) $mediaContext['video_thumbnail_fallback'] = $videoThumbnailFallback;
            $media = ($this->mediaReconcile)($mediaContext);
            $diagnostics['media_usage'] = $this->withoutBody($media);
            $diagnostics['deep_enrichment'] = $this->deepEnrichment($retrieved, $writes, $media, $visualOpportunities);
            if (trim((string) ($media['editorial_state_token'] ?? '')) !== '' && $media['editorial_state_token'] !== $record->articleStateToken) {
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, (string) $media['editorial_state_token']);
            }
            $this->beginPhase('PUBLICATION');
            $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'PUBLICATION');
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $publicationContext = ['capture' => $record->toArray(), 'article_id' => $record->articleId, 'content_intent' => $intent, 'composition' => $this->withoutBody($composition), 'media' => $media, 'semantic' => $retrieved, 'semantic_write_back' => $writes, 'subject_resolution' => $resolution];
            $publication = ($this->publicationGate)($publicationContext);
            // A native media/editorial write may rotate the token between the
            // first gate read and review. Refresh once, then continue with the
            // current canonical state; never replay the old plan indefinitely.
            if (($publication['eligible'] ?? false) !== true && array_intersect(['EDITORIAL_CAS_REQUIRED', 'EDITORIAL_STATE_CHANGED'], array_map('strval', (array) ($publication['blockers'] ?? $publication['diagnostics'] ?? []))) !== []) {
                $publicationContext['refresh_current_state'] = true;
                $publication = ($this->publicationGate)($publicationContext);
            }
            if (trim((string) ($publication['state_token'] ?? '')) !== '' && $publication['state_token'] !== $record->articleStateToken) {
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, (string) $publication['state_token']);
            }
            $diagnostics['publication'] = $publication;
            $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'PUBLICATION', $record->articleId, $record->articleStateToken);
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $eligible = ($publication['eligible'] ?? false) === true;
            $published = false;
            if ($eligible && ($input['publish'] ?? false) === true && is_callable($this->publisher)) {
                $publishedResult = ($this->publisher)([
                    'capture_id' => $record->captureId,
                    'article_id' => $record->articleId,
                    'expected_state_token' => $record->articleStateToken,
                    'evidence' => $publication,
                    'idempotency_key' => $record->captureId . ':publish',
                ]);
                $published = ($publishedResult['ok'] ?? false) === true && (($publishedResult['post']['status'] ?? '') === 'publish');
                $diagnostics['publication_write'] = $this->withoutBody($publishedResult);
                if (!$published) throw new \RuntimeException((string) ($publishedResult['reason'] ?? 'PUBLICATION_RESULT_UNCERTAIN'));
            }
            $this->beginPhase('FINAL_READBACK');
            $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'FINAL_READBACK');
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $final = ($this->finalReadBack)(['capture' => $record->toArray(), 'article_id' => $record->articleId, 'composition' => $this->withoutBody($composition), 'publication' => $publication, 'video_publication' => $videoPublication, 'semantic_write_back' => $writes, 'published' => $published]);
            $diagnostics['final_read_back'] = $this->withoutBody($final);
            if (($final['status'] ?? '') !== 'verified') throw new \RuntimeException('CAPTURE_FINAL_READBACK_UNAVAILABLE');
            $children = $this->completionChildren($record, $writes, $media, $videoPublication, $publication, $final, $published);
            $completion = $this->completion->aggregateCapture($record->captureId, $children, [
                'canonical_state' => 'COMPLETE',
                'required_owners' => $this->requiredOwners($intent, $record, $assets, $media, $videoPublication, $writes),
            ]);
            $diagnostics['completion'] = $completion;
            $diagnostics = $this->settleHistoricalFailure($diagnostics, $receipts);
            $currentStatus = ($completion['complete'] ?? false) === true ? 'COMPLETE' : 'PARTIAL';
            $record = $this->save($record, $record->stage, $assets, $diagnostics, $receipts, 'FINAL_READBACK', $record->articleId, $record->articleStateToken, $currentStatus, 'VERIFIED');
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $stage = $published ? CaptureStage::PUBLISHED->value : CaptureStage::READY_FOR_PUBLICATION->value;
            $status = $published ? 'PUBLISHED' : (($resolution['status'] ?? '') === 'ambiguous' ? 'REVIEW_REQUIRED' : (($completion['complete'] ?? false) === true ? 'COMPLETE' : 'PARTIAL'));
            return $this->save($record, $stage, $assets, $diagnostics, $receipts, $stage, $record->articleId, $record->articleStateToken, $completion['complete'] === true ? $status : 'PARTIAL');
        } catch (\Throwable $error) {
            $latest = $this->captures->findById($record->captureId);
            if ($latest !== null) {
                $record = $latest;
                $assets = $record->assets;
                $diagnostics = $record->diagnostics;
                $receipts = $record->phaseReceipts;
            }
            $failureCode = $this->failureCode($error);
            $status = $this->failureStatus($failureCode);
            $diagnostics['failure'] = ['code' => $failureCode, 'message' => $error->getMessage(), 'classification' => $status];
            $intent = is_array($diagnostics['content_intent'] ?? null) ? $diagnostics['content_intent'] : [];
            $writes = is_array($diagnostics['semantic_write_back'] ?? null) ? $diagnostics['semantic_write_back'] : [];
            $media = is_array($diagnostics['media_usage'] ?? null) ? $diagnostics['media_usage'] : [];
            $videoPublication = is_array($diagnostics['video_publication'] ?? null) ? $diagnostics['video_publication'] : [];
            $partialCompletion = $this->completion->aggregateCapture(
                $record->captureId,
                $this->completionChildren($record, $writes, $media, $videoPublication, [], [], false),
                [
                    'canonical_state' => 'COMPLETE',
                    'required_owners' => $this->requiredOwners($intent, $record, $assets, $media, $videoPublication, $writes),
                    'blockers' => [$failureCode],
                ],
            );
            $diagnostics['completion'] = $partialCompletion;
            $diagnostics['resume_hints'] = $partialCompletion['resume_hints'] ?? ['resume_children' => []];
            return $this->save($record, $record->stage, $assets, $diagnostics, $receipts, $this->activeReceiptPhase ?? $status, $record->articleId, $record->articleStateToken, $status);
        }
    }

    /** @param list<array<string,mixed>> $assets @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts */
    private function runTypedMediaBindingFastPath(CaptureRecord $record, array $input, array $assets, array $diagnostics, array $receipts): CaptureRecord
    {
        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (strtoupper(trim((string) ($binding['selection_source'] ?? 'USER_EXPLICIT'))) === 'SYSTEM_AUTO') throw new \RuntimeException('MEDIA_BINDING_GOVERNANCE_REQUIRED');
        }
        $scope = $this->stagingScopeVerifier?->forCapture($record, $input, $assets);
        unset($input['staging_acceptance']);
        if ($scope !== null) $input['staging_acceptance'] = $scope;
        $intent = ['status' => 'resolved', 'intent' => 'MEDIA_ENRICHMENT', 'source' => 'EXPLICIT_TYPED_BINDING', 'article_required' => false, 'media_required' => true, 'diagnostics' => [], 'signals' => ['typed_media_binding' => true]];
        $diagnostics['content_intent'] = $intent;
        $context = $record->context + ['content_intent' => $intent];
        if ($scope !== null) $context['staging_acceptance'] = $scope;
        $record = $this->save($record, CaptureStage::INTERPRETED, $assets, $diagnostics, $receipts, 'INTERPRETED', null, null, 'IN_PROGRESS', null, $context);
        $diagnostics = $record->diagnostics;
        $receipts = $record->phaseReceipts;
        $this->beginPhase('KNOWLEDGE_RETRIEVED');
        $retrieved = ['status' => 'not_requested', 'items' => [], 'selected_claims' => []];
        $diagnostics['claim_retrieval'] = $retrieved;
        $record = $this->save($record, CaptureStage::KNOWLEDGE_RETRIEVED, $assets, $diagnostics, $receipts, 'KNOWLEDGE_RETRIEVED', null, null, 'SKIPPED');
        $diagnostics = $record->diagnostics;
        $receipts = $record->phaseReceipts;
        $writes = ['status' => 'SKIPPED', 'writes' => [], 'blockers' => [], 'canonical_readback' => null];
        $diagnostics['semantic_write_back'] = $writes;
        $record = $this->save($record, CaptureStage::SEMANTICS_RECONCILED, $assets, $diagnostics, $receipts, 'SEMANTICS_RECONCILED', null, null, 'SKIPPED');
        $diagnostics = $record->diagnostics;
        $receipts = $record->phaseReceipts;
        $this->beginPhase('MEDIA_RECONCILED');
        $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'MEDIA_RECONCILED');
        $mediaContext = ['capture_id' => $record->captureId, 'capture_fingerprint' => $record->requestFingerprint, 'staging_acceptance' => $input['staging_acceptance'] ?? null];
        if (is_array($input['staging_acceptance'] ?? null) && isset($input['staging_acceptance']['payload_fingerprint'])) $mediaContext['payload_fingerprint'] = $input['staging_acceptance']['payload_fingerprint'];
        $media = $this->mediaBindingService?->bindMany((array) ($input['media_bindings'] ?? []), $record->captureId . ':media-binding', $assets, $mediaContext) ?? ['status' => 'PARTIAL', 'bindings' => [], 'media_ids' => []];
        $this->assertTypedMediaBindingReceipt($media);
        $diagnostics = array_replace($record->diagnostics, ['media_enrichment' => $this->withoutBody($media)]);
        $record = $this->save($record, 'MEDIA_RECONCILED', $assets, $diagnostics, $record->phaseReceipts, 'MEDIA_RECONCILED', null, null, ($media['status'] ?? '') === 'COMPLETE' ? 'COMPLETED' : 'PARTIAL');
        return $this->finishNonArticleIntent($record, $record->assets, $record->diagnostics, $record->phaseReceipts, $intent, $retrieved, $writes, ['status' => 'not_requested', 'items' => [], 'blockers' => []], [], $media);
    }

    /** @param array<string,mixed> $media */
    private function assertTypedMediaBindingReceipt(array $media): void
    {
        if (strtoupper(trim((string) ($media['status'] ?? ''))) !== 'COMPLETE') throw new \RuntimeException('MEDIA_BINDING_FINAL_READBACK_REQUIRED');
        $bindings = $media['bindings'] ?? null;
        if (!is_array($bindings) || $bindings === []) throw new \RuntimeException('MEDIA_BINDING_FINAL_READBACK_REQUIRED');
        foreach ($bindings as $binding) {
            if (!is_array($binding) || strtoupper(trim((string) ($binding['status'] ?? ''))) !== 'COMPLETE') throw new \RuntimeException('MEDIA_BINDING_FINAL_READBACK_REQUIRED');
            $readback = $binding['readback'] ?? null;
            if (!is_array($readback) || strtolower(trim((string) ($readback['status'] ?? ''))) !== 'verified' || trim((string) ($readback['media_id'] ?? '')) === '' || trim((string) ($readback['usage_id'] ?? '')) === '') throw new \RuntimeException('MEDIA_BINDING_FINAL_READBACK_REQUIRED');
        }
    }

    private function hasStage(CaptureRecord $record, CaptureStage $stage): bool
    {
        $order = array_flip(array_map(static fn (CaptureStage $item): string => $item->value, CaptureStage::cases()));
        return isset($order[$record->stage], $order[$stage->value]) && $order[$record->stage] >= $order[$stage->value];
    }

    private function assetIdentity(array $asset): string
    {
        foreach (['media_id', 'attachment_id', 'checksum_sha256', 'checksum', 'client_file_id'] as $key) {
            $value = trim((string) ($asset[$key] ?? ''));
            if ($value !== '') return $key . ':' . $value;
        }
        return '';
    }

    private function hasDeferredArticleBindings(array $input): bool
    {
        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            $type = strtolower(trim((string) ($target['type'] ?? '')));
            if (in_array($type, ['article', 'wp_post'], true) && trim((string) ($target['id'] ?? '')) === '' && trim((string) ($target['stable_key'] ?? '')) === '') return true;
        }
        return false;
    }

    /** @return array{0:array<string,mixed>,1:list<array<string,mixed>>} */
    private function resolveDeferredArticleBindings(array $input, CaptureRecord $record): array
    {
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $endpointKey = max(1, $blogId) . ':' . (int) $record->articleId;
        $resolved = [];
        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            $type = strtolower(trim((string) ($target['type'] ?? '')));
            if (in_array($type, ['article', 'wp_post'], true)) {
                $target['type'] = 'wp_post';
                $target['id'] = $endpointKey;
                unset($target['stable_key']);
                $binding['target'] = $target;
            }
            $resolved[] = $binding;
        }
        $input['article_media_bindings'] = array_values(array_filter($resolved, static function (array $binding): bool {
            return strtolower(trim((string) (($binding['target']['type'] ?? '')))) === 'wp_post';
        }));
        $input['media_bindings'] = array_values(array_filter($resolved, static function (array $binding): bool {
            return strtolower(trim((string) (($binding['target']['type'] ?? '')))) !== 'wp_post';
        }));
        return [$input, $resolved];
    }

    /** @return array<string,mixed> */
    private function deepEnrichment(array $retrieved, array $writes, array $media, array $opportunities): array
    {
        $reusedKnowledge = array_values(array_filter((array) ($writes['reused_claims'] ?? $retrieved['selected_claims'] ?? []), 'is_array'));
        $articleCandidates = array_values(array_filter((array) ($media['internal_link_candidates'] ?? $media['related_articles'] ?? $writes['internal_link_candidates'] ?? []), 'is_array'));
        return [
            'status' => ($reusedKnowledge !== [] || $articleCandidates !== []) ? 'REUSE_EXISTING' : ($opportunities !== [] ? 'NEW_DEEP_CONTENT_OPPORTUNITY' : 'NOT_REQUESTED'),
            'visual_support' => $opportunities === [] ? ['status' => 'not_requested', 'requirements' => []] : ['status' => 'optional_enrichment', 'requirements' => array_values(array_map(static fn (array $item): array => ['feature_key' => $item['feature_key'] ?? '', 'recommended_view' => $item['recommended_view'] ?? '', 'priority' => $item['priority'] ?? 0], $opportunities))],
            'knowledge_reuse' => $reusedKnowledge,
            'article_reuse_internal_link' => $articleCandidates,
            'new_deep_content_opportunity' => $reusedKnowledge === [] && $articleCandidates === [] && $opportunities !== [] ? ['status' => 'NEW_DEEP_CONTENT_OPPORTUNITY', 'opportunities' => $opportunities, 'auto_create' => false] : null,
        ];
    }

    /**
     * Finish a Capture whose intent has no Article owner. The semantic/video
     * owners still receive final read-back, but Article publication stages are
     * not fabricated as a substitute for their canonical completion.
     *
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $retrieved
     * @param array<string,mixed> $writes
     * @param array<string,mixed> $videoPublication
     * @param array<string,mixed> $resolution
     */
    private function finishNonArticleIntent(CaptureRecord $record, array $assets, array $diagnostics, array $receipts, array $intent, array $retrieved, array $writes, array $videoPublication, array $resolution, array $media = []): CaptureRecord
    {
        $this->beginPhase('FINAL_READBACK');
        $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'FINAL_READBACK');
        $assets = $record->assets;
        $diagnostics = $record->diagnostics;
        $receipts = $record->phaseReceipts;
        $final = ($this->finalReadBack)([
            'capture' => $record->toArray(),
            'article_id' => null,
            'content_intent' => $intent,
            'semantic' => $retrieved,
            'semantic_write_back' => $writes,
            'media' => $media,
            'video_publication' => $videoPublication,
            'subject_resolution' => $resolution,
        ]);
        $diagnostics['final_read_back'] = $this->withoutBody($final);
        if (($final['status'] ?? '') !== 'verified') throw new \RuntimeException('CAPTURE_FINAL_READBACK_UNAVAILABLE');

        $completion = $this->completion->aggregateCapture($record->captureId, $this->completionChildren($record, $writes, $media, $videoPublication, [], $final, false), [
            'canonical_state' => 'COMPLETE',
            'required_owners' => $this->requiredOwners($intent, $record, $assets, $media, $videoPublication, $writes),
        ]);
        $diagnostics['completion'] = $completion;
        $semanticStatus = strtoupper(trim((string) ($writes['status'] ?? '')));
        $status = ($completion['complete'] ?? false) === true
            ? 'COMPLETE'
            : (in_array($semanticStatus, ['REVIEW_REQUIRED', 'PLANNED', 'APPROVAL_PENDING'], true) || ($videoPublication['blockers'] ?? []) !== [] ? 'REVIEW_REQUIRED' : 'PARTIAL');
        return $this->save($record, CaptureStage::SEMANTICS_RECONCILED, $assets, $diagnostics, $receipts, 'FINAL_READBACK', null, null, $status, 'VERIFIED');
    }

    /** @param array<string,mixed> $input */
    private function isVideoOnlyResume(array $input): bool
    {
        if (($input['existing_capture_continuation'] ?? false) !== true) return false;
        $governance = is_array($input['governance'] ?? null) ? $input['governance'] : [];
        $children = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($governance['resume_children'] ?? [])))));
        return $children === ['video'];
    }

    /** @return array<string,string> */
    private function editorialFields(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $slug = trim((string) ($metadata['desired_slug'] ?? ''));
        return $slug === '' ? [] : ['post_name' => $slug];
    }

    /**
     * Declares the owner branches required by the resolved intent. This is a
     * receipt-level policy only; each owner still performs its own mutation
     * and canonical read-back.
     *
     * @param array<string,mixed> $intent
     * @param list<array<string,mixed>> $assets
     * @param array<string,mixed> $media
     * @param array<string,mixed> $videoPublication
     * @return list<array{owner_type:string,owner_id:string}>
     */
    private function requiredOwners(array $intent, CaptureRecord $record, array $assets, array $media, array $videoPublication, array $writes = []): array
    {
        $videoOwnerId = $this->videoOwnerId($assets, $videoPublication, $writes);
        $mediaOwners = array_map(
            static fn (string $mediaId): array => ['owner_type' => 'media', 'owner_id' => $mediaId],
            $this->mediaOwnerIds($media),
        );
        $required = match (strtoupper(trim((string) ($intent['intent'] ?? '')))) {
            'VIDEO' => [['owner_type' => 'video', 'owner_id' => $videoOwnerId]],
            'KNOWLEDGE_DELTA' => [['owner_type' => 'knowledge']],
            'IMAGE_ARTICLE', 'TEXT_ARTICLE' => [['owner_type' => 'wp_post']],
            'MEDIA_ENRICHMENT' => $mediaOwners !== [] ? $mediaOwners : [['owner_type' => 'media', 'owner_id' => '']],
            default => [],
        };
        if (strtoupper(trim((string) ($intent['intent'] ?? ''))) === 'IMAGE_ARTICLE' && $assets !== []) {
            $required = array_merge($required, $mediaOwners !== [] ? $mediaOwners : [['owner_type' => 'media', 'owner_id' => '']]);
        }
        return $required;
    }

    /** @return list<string> */
    private function mediaOwnerIds(array $media): array
    {
        $ids = [];
        foreach ((array) ($media['media_ids'] ?? []) as $mediaId) {
            $mediaId = trim((string) $mediaId);
            if ($mediaId !== '') $ids[] = $mediaId;
        }
        foreach (['media_id', 'canonical_id'] as $key) {
            $mediaId = trim((string) ($media[$key] ?? ''));
            if ($mediaId !== '') $ids[] = $mediaId;
        }
        foreach ((array) ($media['bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            foreach (['media_id', 'canonical_id'] as $key) {
                $mediaId = trim((string) ($binding[$key] ?? ''));
                if ($mediaId !== '') $ids[] = $mediaId;
            }
            $readback = is_array($binding['readback'] ?? null) ? $binding['readback'] : [];
            $mediaId = trim((string) ($readback['media_id'] ?? $readback['canonical_id'] ?? ''));
            if ($mediaId !== '') $ids[] = $mediaId;
        }
        return array_values(array_unique($ids));
    }

    /** @return string */
    private function videoOwnerId(array $assets, array $videoPublication, array $writes): string
    {
        foreach ((array) ($videoPublication['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = trim((string) ($item['video_id'] ?? $item['canonical_id'] ?? ''));
            if ($id !== '') return $id;
        }
        foreach ((array) ($writes['writes'] ?? []) as $write) {
            if (!is_array($write) || strtolower((string) ($write['entity_type'] ?? '')) !== 'video') continue;
            $readback = is_array($write['canonical_readback'] ?? null) ? $write['canonical_readback'] : [];
            $id = trim((string) ($write['canonical_id'] ?? $write['result_entity_uuid'] ?? ($readback['canonical_id'] ?? '')));
            if ($id !== '') return $id;
        }
        foreach ((array) ($writes['video_children'] ?? []) as $child) {
            if (!is_array($child)) continue;
            $readback = is_array($child['canonical_readback'] ?? null) ? $child['canonical_readback'] : [];
            $id = trim((string) ($child['canonical_id'] ?? ($readback['canonical_id'] ?? '')));
            if ($id !== '') return $id;
        }
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['kind'] ?? '') !== 'video') continue;
            $proposal = is_array($asset['video_proposal'] ?? null) ? $asset['video_proposal'] : [];
            $payload = is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [];
            $id = trim((string) ($asset['video_id'] ?? $payload['canonical_id'] ?? $proposal['subject_id'] ?? $proposal['target_uuid'] ?? ''));
            if ($id !== '') return $id;
        }
        return '';
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(array $input): string
    {
        unset($input['operation_id']);
        if (isset($input['files']) && is_array($input['files'])) {
            $batch = isset($input['files']['files']) && is_array($input['files']['files']) ? $input['files']['files'] : $input['files'];
            if (isset($batch['tmp_name']) && is_array($batch['tmp_name'])) {
                $normalized = [];
                foreach ($batch['tmp_name'] as $index => $path) {
                    $path = is_string($path) ? $path : '';
                    $normalized[] = [
                        'name' => is_array($batch['name'] ?? null) ? (string) ($batch['name'][$index] ?? '') : '',
                        'size' => is_array($batch['size'] ?? null) ? (int) ($batch['size'][$index] ?? 0) : 0,
                        'checksum' => is_file($path) ? hash_file('sha256', $path) : null,
                    ];
                }
                $input['files'] = $normalized;
            } else {
                $input['files'] = array_map(static function (mixed $file): mixed {
                    if (!is_array($file)) return $file;
                    $path = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
                    return ['name' => (string) ($file['name'] ?? ''), 'size' => (int) ($file['size'] ?? 0), 'checksum' => is_file($path) ? hash_file('sha256', $path) : null];
                }, $input['files']);
            }
        }
        return hash('sha256', CommandCanonicalizer::canonicalize($input));
    }

    private function conflict(CaptureRecord $record, string $fingerprint): CaptureRecord
    {
        return new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, 'IDEMPOTENCY_CONFLICT', $record->articleId, $record->articleStateToken, $record->assets, $record->context, $record->diagnostics + ['failure' => ['code' => 'CAPTURE_IDEMPOTENCY_KEY_REUSED', 'request_fingerprint' => $fingerprint]], $record->phaseReceipts, $record->revision, $record->createdAt, $record->updatedAt);
    }

    /** @param list<array<string,mixed>> $assets @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts */
    private function save(CaptureRecord $record, CaptureStage|string $stage, array $assets, array $diagnostics, array $receipts, string $receiptStage, ?int $articleId = null, ?string $token = null, string $status = 'IN_PROGRESS', ?string $receiptResult = null, ?array $context = null): CaptureRecord
    {
        $stage = $stage instanceof CaptureStage ? $stage->value : $stage;
        $completedAt = microtime(true);
        $startedEpoch = $this->phaseStartedAt[$receiptStage] ?? $completedAt;
        $startedAt = gmdate('c', (int) $startedEpoch);
        unset($this->phaseStartedAt[$receiptStage]);
        if ($this->activeReceiptPhase === $receiptStage) $this->activeReceiptPhase = null;
        $prior = is_array($receipts[$receiptStage] ?? null) ? $receipts[$receiptStage] : [];
        $receiptStatus = match ($status) {
            'FAILED_RETRYABLE' => 'FAILED',
            'SYSTEM_BLOCKED' => 'BLOCKED',
            'REVIEW_REQUIRED' => 'REVIEW_REQUIRED',
            'PARTIAL' => $receiptStage === 'SEMANTICS_RECONCILED' && is_array($diagnostics['semantic_write_back']['blockers'] ?? null) && $diagnostics['semantic_write_back']['blockers'] !== [] ? 'BLOCKED' : 'COMPLETED',
            default => 'COMPLETED',
        };
        $attempt = [
            'status' => $receiptStatus,
            'result' => $receiptResult ?? $status,
            'started_at' => (string) ($prior['started_at'] ?? $startedAt),
            'completed_at' => gmdate('c'),
            'elapsed_ms' => $startedEpoch === false ? 0 : max(0, (int) (($completedAt - (float) $startedEpoch) * 1000)),
            'at' => gmdate('c'),
        ];
        $semanticDiagnostics = is_array($diagnostics['semantic_write_back'] ?? null) ? $diagnostics['semantic_write_back'] : [];
        $failureCode = trim((string) ($diagnostics['failure']['code'] ?? ($semanticDiagnostics['blockers'][0] ?? '')));
        if ($failureCode !== '' && $receiptStatus !== 'COMPLETED') $attempt['failure_code'] = $failureCode;
        $receipts = CapturePhaseReceiptReducer::append($receipts, $receiptStage, $attempt);
        return $this->captures->save(new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $stage, $status, $articleId ?? $record->articleId, $token ?? $record->articleStateToken, $assets, $context ?? $record->context, $diagnostics, $receipts, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
    }

    private function beginPhase(string $phase): void
    {
        $this->phaseStartedAt[$phase] = microtime(true);
    }

    /** Persist the STARTED marker before entering a long external/governed phase. */
    private function startReceipt(CaptureRecord $record, array $assets, array $diagnostics, array $receipts, string $phase): CaptureRecord
    {
        $now = gmdate('c');
        $this->activeReceiptPhase = $phase;
        $receipts = CapturePhaseReceiptReducer::append($receipts, $phase, ['status' => 'STARTED', 'result' => 'IN_PROGRESS', 'started_at' => $now, 'completed_at' => null, 'elapsed_ms' => null]);
        return $this->captures->save(new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, $record->status, $record->articleId, $record->articleStateToken, $assets, $record->context, $diagnostics, $receipts, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function withoutBody(array $value): array
    {
        foreach (['body', 'content', 'post_content'] as $key) unset($value[$key]);
        return $value;
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts @return array<string,mixed> */
    private function settleHistoricalFailure(array $diagnostics, array $receipts): array
    {
        $failure = is_array($diagnostics['failure'] ?? null) ? $diagnostics['failure'] : null;
        if ($failure === null) return $diagnostics;
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) continue;
            $latest = is_array($receipt['latest'] ?? null) ? $receipt['latest'] : $receipt;
            $status = strtoupper(trim((string) ($latest['status'] ?? '')));
            $result = strtoupper(trim((string) ($latest['result'] ?? '')));
            if (in_array($status, ['FAILED', 'BLOCKED', 'REVIEW_REQUIRED'], true) || str_contains($result, 'FAILED')) return $diagnostics;
        }
        $history = is_array($diagnostics['failure_history'] ?? null) ? $diagnostics['failure_history'] : [];
        $history[] = $failure + ['resolved_at' => gmdate('c'), 'resolution' => 'LATEST_REQUIRED_PHASES_CONVERGED'];
        $diagnostics['failure_history'] = $history;
        unset($diagnostics['failure']);
        return $diagnostics;
    }

    private function failureCode(\Throwable $error): string
    {
        if ($error instanceof ProposalSubjectBindingInvalid) return 'PROPOSAL_SUBJECT_BINDING_INVALID';
        if ($error instanceof ProposalIdempotencyConflict) return 'PROPOSAL_IDEMPOTENCY_CONFLICT';
        if ($error instanceof ProposalIdempotencyStaleBinding) return 'IDEMPOTENCY_STALE_BINDING';
        if ($error instanceof VideoRelationEvidenceRequired) return VideoRelationEvidenceRequired::ERROR_CODE;
        if ($error instanceof GovernanceException) return 'CAPTURE_GOVERNANCE_CONTRACT_FAILURE';
        $message = strtoupper(trim($error->getMessage()));
        return $message !== '' ? preg_replace('/[^A-Z0-9_:-]+/', '_', $message) ?? 'CAPTURE_FAILED' : 'CAPTURE_FAILED';
    }

    private function failureStatus(string $failureCode): string
    {
        if (preg_match('/(?:ARTICLE_MEDIA_BLUEPRINT_IS_INVALID|PROPOSAL_SUBJECT_BINDING_INVALID|VIDEO_PROPOSAL_REPAIR_REQUIRED|REPAIR_APPLIED_PROPOSAL_FORBIDDEN|IDEMPOTENCY_STALE_BINDING|PROPOSAL_IDEMPOTENCY_CONFLICT|PROPOSAL_BINDING_CONFLICT)/', $failureCode) === 1) return 'SYSTEM_BLOCKED';
        if ($failureCode === VideoRelationEvidenceRequired::ERROR_CODE) return 'REVIEW_REQUIRED';
        if (preg_match('/(?:SUBJECT_NOT_FOUND|AMBIGUOUS_SUBJECT|NO_SEMANTIC_ATTACHMENT|TRANSCRIPT_UNAVAILABLE|MEDIA_(?:FEATURED|INLINE)_MISSING|MEDIAUSAGE_INCOMPLETE)/', $failureCode) === 1) return 'PARTIAL';
        return 'FAILED_RETRYABLE';
    }

    /** @param array<string,mixed> $video @return list<string> */
    private function videoSubjectHints(array $video): array
    {
        $hints = [];
        $packet = is_array($video['metadata']['subject_resolution_packet'] ?? null) ? $video['metadata']['subject_resolution_packet'] : [];
        if (UuidCodec::isValid((string) ($packet['id'] ?? '')) && trim((string) ($packet['type'] ?? '')) !== '') $hints[] = (string) $packet['id'];

        $targets = [];
        foreach ((array) ($video['intended_relations'] ?? []) as $relation) {
            if (!is_array($relation) || strtolower(trim((string) ($relation['predicate'] ?? 'about'))) !== 'about') continue;
            $id = trim((string) ($relation['target_id'] ?? ''));
            $type = trim((string) ($relation['target_type'] ?? ''));
            if (UuidCodec::isValid($id) && $type !== '') $targets[strtolower($id)] = $id;
        }
        if (count($targets) === 1) $hints[] = array_values($targets)[0];

        $hint = trim((string) ($video['user_hint'] ?? ''));
        if ($hint !== '') $hints[] = $hint;
        $sourceTitle = trim((string) ($video['metadata']['source_snapshot']['source_title'] ?? $video['source_title'] ?? ''));
        if ($sourceTitle !== '') $hints[] = $sourceTitle;
        return array_values(array_unique($hints));
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $manifest @param list<array<string,mixed>> $items @return array<string,mixed>|null */
    private function videoSubjectHandoff(array $resolution, array $manifest, array $items, bool $preferCurrent = false): ?array
    {
        // Retry resolution is current executable state. A persisted preview is
        // historical derived metadata and must not overwrite a valid current
        // Classification handoff after Video contracts evolve.
        $current = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        if ($preferCurrent && UuidCodec::isValid((string) ($current['id'] ?? '')) && trim((string) ($current['type'] ?? '')) !== '') return $resolution;
        $previewWasReturned = array_key_exists('video_preview', $manifest) || isset($items[0]['video_preview']);
        $preview = is_array($manifest['video_preview']['package']['subject_resolution_packet'] ?? null)
            ? $manifest['video_preview']['package']['subject_resolution_packet']
            : [];
        $proposal = is_array($items[0]['video_proposal']['payload']['metadata']['subject_resolution_packet'] ?? null)
            ? $items[0]['video_proposal']['payload']['metadata']['subject_resolution_packet']
            : [];
        $packet = $preview !== [] ? $preview : $proposal;
        if ($packet === []) {
            if ($previewWasReturned && is_array($resolution['primary'] ?? null) && UuidCodec::isValid((string) ($resolution['primary']['id'] ?? ''))) throw new \RuntimeException('VIDEO_SUBJECT_HANDOFF_INVARIANT_FAILED');
            return null;
        }
        $packetId = trim((string) ($packet['id'] ?? ''));
        $packetType = strtolower(trim((string) ($packet['type'] ?? '')));
        if (!UuidCodec::isValid($packetId) || $packetType === '') throw new \RuntimeException('VIDEO_SUBJECT_HANDOFF_INVARIANT_FAILED');

        if ($current !== [] && (strtolower((string) ($current['id'] ?? '')) !== strtolower($packetId) || strtolower((string) ($current['type'] ?? '')) !== $packetType)) {
            throw new \RuntimeException('VIDEO_SUBJECT_HANDOFF_INVARIANT_FAILED');
        }
        if ($current !== []) return $resolution;
        return ['status' => 'resolved', 'primary' => $packet, 'subjects' => [$packet], 'resolved' => [$packet], 'candidates' => [], 'unresolved' => [], 'diagnostics' => []];
    }

    /** @return list<array<string,mixed>> */
    /** @param list<array<string,mixed>> $assets @param array<string,mixed> $videoPublication @return array<string,mixed>|null */
    private function eligibleVideoThumbnailFallback(array $assets, array $videoPublication): ?array
    {
        $verified = [];
        foreach ((array) ($videoPublication['items'] ?? []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') !== 'verified') continue;
            $verified[(string) ($item['video_id'] ?? '') . '|' . (string) ($item['platform'] ?? '') . '|' . (string) ($item['external_video_id'] ?? $item['external_id'] ?? '')] = true;
        }
        if ($verified === []) return null;
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? '') !== 'video') continue;
            $proposal = is_array($asset['video_proposal'] ?? null) ? $asset['video_proposal'] : [];
            $payload = is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [];
            $source = is_array($payload['metadata']['source'] ?? null) ? $payload['metadata']['source'] : (is_array($payload['metadata']['source_snapshot'] ?? null) ? $payload['metadata']['source_snapshot'] : []);
            $selection = is_array($source['thumbnail_selection'] ?? null) ? $source['thumbnail_selection'] : [];
            $url = trim((string) ($selection['url'] ?? ''));
            $width = (int) ($selection['width'] ?? 0);
            $height = (int) ($selection['height'] ?? 0);
            if ($url === '' || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $width < 320 || $height < 180) continue;
            $key = (string) ($asset['video_id'] ?? $payload['canonical_id'] ?? '') . '|' . (string) ($asset['platform'] ?? $source['platform'] ?? '') . '|' . (string) ($asset['external_video_id'] ?? $asset['external_id'] ?? $source['external_video_id'] ?? '');
            if (!isset($verified[$key])) continue;
            return [
                'eligible' => true,
                'url' => $url,
                'variant' => (string) ($selection['variant'] ?? ''),
                'width' => $width,
                'height' => $height,
                'video_id' => (string) ($asset['video_id'] ?? $payload['canonical_id'] ?? ''),
                'external_video_id' => (string) ($asset['external_video_id'] ?? $asset['external_id'] ?? $source['external_video_id'] ?? ''),
                'adopted_as_media' => false,
                'user_upload_preferred' => true,
            ];
        }
        return null;
    }

    private function completionChildren(CaptureRecord $record, array $writes, array $media, array $videoPublication, array $publication, array $final, bool $published): array
    {
        $children = [];
        $articleId = $record->articleId;
        if ($articleId !== null) {
            $articleBlockers = $published ? [] : ['ARTICLE_NOT_PUBLISHED'];
            $renderedVerified = ($final['frontend_verified'] ?? false) === true
                || ($final['rendered_public_verification'] ?? false) === true
                || ($final['rendered_public_verification_status'] ?? '') === 'verified';
            if ($published && !$renderedVerified) $articleBlockers[] = 'ARTICLE_FRONTEND_READBACK_REQUIRED';
            $children[] = [
                'owner_type' => 'wp_post', 'owner_id' => (string) $articleId,
                'canonical_readback' => ($final['status'] ?? '') === 'verified' ? ['id' => $articleId] : null,
                'dependency_state' => ($publication['eligible'] ?? false) === true ? 'COMPLETE' : 'PARTIAL',
                'relation_or_usage_state' => ($media['status'] ?? '') === 'RECONCILED' ? 'COMPLETE' : 'PARTIAL',
                'public_eligible' => $published && ($publication['eligible'] ?? false) === true,
                'frontend_verified' => $published && ($final['status'] ?? '') === 'verified' && $renderedVerified,
                'blockers' => array_merge($articleBlockers, array_values(array_map('strval', (array) ($publication['blockers'] ?? [])))),
            ];
        }
        foreach ($writes as $write) {
            if (!is_array($write) || !isset($write['completion']) && trim((string) ($write['canonical_id'] ?? '')) === '') continue;
            $type = trim((string) ($write['entity_type'] ?? 'knowledge')) ?: 'knowledge';
            $children[] = is_array($write['completion'] ?? null)
                ? ['completion' => $write['completion']]
                : ['owner_type' => $type, 'owner_id' => (string) ($write['canonical_id'] ?? ''), 'canonical_readback' => $write['canonical_readback'] ?? null, 'dependency_state' => ($write['status'] ?? '') === 'APPLIED' ? 'COMPLETE' : 'PARTIAL', 'blockers' => (array) ($write['blockers'] ?? [])];
        }
        foreach ((array) ($videoPublication['items'] ?? []) as $video) if (is_array($video) && is_array($video['completion'] ?? null)) $children[] = ['completion' => $video['completion']];
        $mediaIds = array_values(array_unique(array_filter(array_map('strval', (array) ($media['media_ids'] ?? [])), static fn (string $id): bool => trim($id) !== '')));
        $singleMediaId = trim((string) ($media['media_id'] ?? $media['canonical_id'] ?? ''));
        if ($singleMediaId !== '') $mediaIds[] = $singleMediaId;
        $mediaComplete = in_array(strtoupper(trim((string) ($media['status'] ?? ''))), ['COMPLETE', 'RECONCILED'], true);
        $mediaFrontendVerified = $media['frontend_verified'] ?? ($final['frontend_verified'] ?? null);
        foreach (array_values(array_unique($mediaIds)) as $mediaId) {
            $children[] = ['owner_type' => 'media', 'owner_id' => $mediaId, 'canonical_readback' => $mediaComplete ? ['id' => $mediaId] : null, 'relation_or_usage_state' => $mediaComplete ? 'COMPLETE' : 'PARTIAL', 'public_eligible' => ($media['media_complete'] ?? false) === true || (($media['media_complete'] ?? null) === null && $mediaComplete), 'frontend_verified' => $mediaFrontendVerified, 'blockers' => (array) ($media['blockers'] ?? [])];
        }
        return $children;
    }
}
