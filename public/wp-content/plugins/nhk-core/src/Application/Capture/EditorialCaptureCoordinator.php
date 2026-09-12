<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Domain\Capture\CapturePurpose;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Mcp\McpDocumentationRegistry;
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
                'documentation_checkpoint' => is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [],
            ],
        ));
        return $this->run($record, $input);
    }

    /** Continue an existing Capture without repeating physical or draft creation. */
    public function continueWithAddendum(CaptureRecord $record, array $input): CaptureRecord
    {
        $continuation = is_array($record->context['continuation_state'] ?? null) ? $record->context['continuation_state'] : [];
        $original = trim((string) ($continuation['raw_input'] ?? $record->context['raw_input'] ?? ''));
        $addendum = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $input['text'] = trim(implode("\n\n", array_values(array_filter([$original, $addendum], static fn (string $value): bool => $value !== ''))));
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
            if ($assets === [] && !$this->hasStage($record, CaptureStage::ASSETS_STORED)) {
                $this->beginPhase('ASSETS_STORED');
                $manifest = ($this->physicalIngest)($input);
                $assets = is_array($manifest['items'] ?? null) ? array_values($manifest['items']) : (is_array($manifest) && array_is_list($manifest) ? $manifest : []);
                $diagnostics['physical_ingest'] = $this->withoutBody($manifest);
                $record = $this->save($record, CaptureStage::ASSETS_STORED, $assets, $diagnostics, $receipts, 'ASSETS_STORED');
            }
            if (!$this->hasStage($record, CaptureStage::DRAFT_CREATED)) {
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
            if (!$this->hasStage($record, CaptureStage::MEDIA_ADOPTED)) {
                $this->beginPhase('MEDIA_ADOPTED');
                $adopted = [];
                foreach ($assets as $asset) {
                    if (!is_array($asset)) continue;
                    $adoptedAsset = $asset;
                    if (is_callable($this->mediaAdoption) && isset($asset['attachment_id'])) {
                        $adoption = ($this->mediaAdoption)([
                            'capture_id' => $record->captureId,
                            'attachment_id' => (int) $asset['attachment_id'],
                            'asset' => $asset,
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
            $this->beginPhase('INTERPRETED');
            $interpretation = $this->interpreter->interpret($text, $assets, is_array($input['subject_hints'] ?? null) ? $input['subject_hints'] : [], is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
            $diagnostics['interpretation'] = $this->withoutBody($interpretation);
            $record = $this->save($record, CaptureStage::INTERPRETED, $assets, $diagnostics, $receipts, 'INTERPRETED', $record->articleId, $record->articleStateToken);

            $this->beginPhase('SUBJECTS_RESOLVED');
            $resolution = $this->subjects->resolve(array_values(array_unique(array_merge(
                (array) ($interpretation['primary_subject_hints'] ?? []),
                (array) ($interpretation['secondary_subject_hints'] ?? []),
            ))));
            $diagnostics['subjects'] = $resolution;
            $record = $this->save($record, CaptureStage::SUBJECTS_RESOLVED, $assets, $diagnostics, $receipts, 'SUBJECTS_RESOLVED', $record->articleId, $record->articleStateToken);

            $videoInput = is_array($input['video'] ?? null) ? $input['video'] : [];
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
                $diagnostics['video_enrichment'] = $this->withoutBody($videoManifest);
                $record = $this->save($record, CaptureStage::SUBJECTS_RESOLVED, $assets, $diagnostics, $receipts, 'VIDEO_ENRICHED', $record->articleId, $record->articleStateToken);
            }

            $semanticContext = ['capture_id' => $record->captureId, 'raw_input' => $text, 'continuation_delta_text' => trim((string) ($input['continuation_delta_text'] ?? '')), 'assets' => $assets, 'interpretation' => $interpretation, 'subject_resolution' => $resolution, 'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [], 'existing_capture_continuation' => ($input['existing_capture_continuation'] ?? false) === true, 'continuation_idempotency_key' => (string) ($input['continuation_idempotency_key'] ?? ''), 'governance' => is_array($input['governance'] ?? null) ? $input['governance'] : [], 'prior_diagnostics' => $diagnostics];
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

            $observations = array_merge($semanticContext['observations'], is_array($interpretation['media_observations'] ?? null) ? $interpretation['media_observations'] : []);
            $this->beginPhase('COMPOSED');
            $composition = $this->composer->compose($text, $observations, $retrieved['selected_claims'] ?? [], ['title' => (string) ($input['title'] ?? ''), 'excerpt' => (string) ($input['excerpt'] ?? ''), 'asset_count' => count($assets), 'assets' => $assets]);
            $diagnostics['composition'] = ['title' => $composition['title'], 'claim_trace' => $composition['claim_trace'], 'research_snapshot' => $composition['research_snapshot']];
            $diagnostics['article_draft'] = ['title' => $composition['title'], 'excerpt' => $composition['excerpt'], 'content_available' => true];
            if (is_callable($this->draftUpdater) && $record->articleId !== null && $record->articleStateToken !== null) {
                $updatedDraft = ($this->draftUpdater)([
                    'capture_id' => $record->captureId,
                    'article_id' => $record->articleId,
                    'expected_state_token' => $record->articleStateToken,
                    'fields' => [
                        'post_title' => $composition['title'],
                        'post_content' => $composition['content'],
                        'post_excerpt' => $composition['excerpt'],
                    ],
                ]);
                if (($updatedDraft['ok'] ?? false) !== true) throw new \RuntimeException((string) ($updatedDraft['reason'] ?? 'ARTICLE_DRAFT_UPDATE_FAILED'));
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, (string) ($updatedDraft['state_token'] ?? $record->articleStateToken));
            } elseif (!$this->hasStage($record, CaptureStage::COMPOSED)) {
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, $record->articleStateToken);
            }

            $media = ($this->mediaReconcile)(['capture' => $record->toArray(), 'article_id' => $record->articleId, 'assets' => $assets, 'subject_resolution' => $resolution, 'subject_resolution_packet' => $resolution['primary'] ?? null, 'composition' => $this->withoutBody($composition)]);
            $diagnostics['media_usage'] = $this->withoutBody($media);
            if (trim((string) ($media['editorial_state_token'] ?? '')) !== '' && $media['editorial_state_token'] !== $record->articleStateToken) {
                $record = $this->save($record, CaptureStage::COMPOSED, $assets, $diagnostics, $receipts, 'COMPOSED', $record->articleId, (string) $media['editorial_state_token']);
            }
            $this->beginPhase('PUBLICATION');
            $record = $this->startReceipt($record, $assets, $diagnostics, $receipts, 'PUBLICATION');
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $publicationContext = ['capture' => $record->toArray(), 'article_id' => $record->articleId, 'composition' => $this->withoutBody($composition), 'media' => $media, 'semantic' => $retrieved, 'semantic_write_back' => $writes, 'subject_resolution' => $resolution];
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
            $completion = $this->completion->aggregateCapture($record->captureId, $children, ['canonical_state' => 'COMPLETE']);
            $diagnostics['completion'] = $completion;
            $record = $this->save($record, $record->stage, $assets, $diagnostics, $receipts, 'FINAL_READBACK', $record->articleId, $record->articleStateToken, $record->status, 'VERIFIED');
            $assets = $record->assets;
            $diagnostics = $record->diagnostics;
            $receipts = $record->phaseReceipts;
            $stage = $published ? CaptureStage::PUBLISHED->value : CaptureStage::READY_FOR_PUBLICATION->value;
            $status = $published ? 'PUBLISHED' : (($resolution['status'] ?? '') === 'ambiguous' ? 'REVIEW_REQUIRED' : 'PARTIAL');
            return $this->save($record, $stage, $assets, $diagnostics, $receipts, $stage, $record->articleId, $record->articleStateToken, $completion['complete'] === true ? $status : 'PARTIAL');
        } catch (\Throwable $error) {
            $latest = $this->captures->findById($record->captureId);
            if ($latest !== null) {
                $record = $latest;
                $assets = $record->assets;
                $receipts = $record->phaseReceipts;
            }
            $failureCode = $this->failureCode($error);
            $status = $this->failureStatus($failureCode);
            $diagnostics['failure'] = ['code' => $failureCode, 'message' => $error->getMessage(), 'classification' => $status];
            return $this->save($record, $record->stage, $assets, $diagnostics, $receipts, $this->activeReceiptPhase ?? $status, $record->articleId, $record->articleStateToken, $status);
        }
    }

    private function hasStage(CaptureRecord $record, CaptureStage $stage): bool
    {
        $order = array_flip(array_map(static fn (CaptureStage $item): string => $item->value, CaptureStage::cases()));
        return isset($order[$record->stage], $order[$stage->value]) && $order[$record->stage] >= $order[$stage->value];
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
        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function conflict(CaptureRecord $record, string $fingerprint): CaptureRecord
    {
        return new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, 'IDEMPOTENCY_CONFLICT', $record->articleId, $record->articleStateToken, $record->assets, $record->context, $record->diagnostics + ['failure' => ['code' => 'CAPTURE_IDEMPOTENCY_KEY_REUSED', 'request_fingerprint' => $fingerprint]], $record->phaseReceipts, $record->revision, $record->createdAt, $record->updatedAt);
    }

    /** @param list<array<string,mixed>> $assets @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts */
    private function save(CaptureRecord $record, CaptureStage|string $stage, array $assets, array $diagnostics, array $receipts, string $receiptStage, ?int $articleId = null, ?string $token = null, string $status = 'IN_PROGRESS', ?string $receiptResult = null): CaptureRecord
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
        $receipts[$receiptStage] = [
            'status' => $receiptStatus,
            'result' => $receiptResult ?? $status,
            'started_at' => (string) ($prior['started_at'] ?? $startedAt),
            'completed_at' => gmdate('c'),
            'elapsed_ms' => $startedEpoch === false ? 0 : max(0, (int) (($completedAt - (float) $startedEpoch) * 1000)),
            'at' => gmdate('c'),
        ];
        $semanticDiagnostics = is_array($diagnostics['semantic_write_back'] ?? null) ? $diagnostics['semantic_write_back'] : [];
        $failureCode = trim((string) ($diagnostics['failure']['code'] ?? ($semanticDiagnostics['blockers'][0] ?? '')));
        if ($failureCode !== '' && $receiptStatus !== 'COMPLETED') $receipts[$receiptStage]['failure_code'] = $failureCode;
        return $this->captures->save(new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $stage, $status, $articleId ?? $record->articleId, $token ?? $record->articleStateToken, $assets, $record->context, $diagnostics, $receipts, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
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
        $receipts[$phase] = ['status' => 'STARTED', 'result' => 'IN_PROGRESS', 'started_at' => $now, 'completed_at' => null, 'elapsed_ms' => null];
        return $this->captures->save(new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, $record->status, $record->articleId, $record->articleStateToken, $assets, $record->context, $diagnostics, $receipts, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function withoutBody(array $value): array
    {
        foreach (['body', 'content', 'post_content'] as $key) unset($value[$key]);
        return $value;
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

    /** @return list<array<string,mixed>> */
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
        $mediaId = trim((string) ($media['media_id'] ?? $media['canonical_id'] ?? ''));
        if ($mediaId !== '') $children[] = ['owner_type' => 'media', 'owner_id' => $mediaId, 'canonical_readback' => ($media['status'] ?? '') === 'RECONCILED' ? ['id' => $mediaId] : null, 'relation_or_usage_state' => ($media['status'] ?? '') === 'RECONCILED' ? 'COMPLETE' : 'PARTIAL', 'public_eligible' => ($media['media_complete'] ?? false) === true, 'frontend_verified' => ($media['frontend_verified'] ?? null), 'blockers' => (array) ($media['blockers'] ?? [])];
        return $children;
    }
}
