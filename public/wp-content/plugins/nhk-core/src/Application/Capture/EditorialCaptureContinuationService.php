<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage, ResolvedSubjectReconciliationPacket, SubjectResolutionPacket};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Continues one existing Capture/Post with an idempotent text or asset addendum. */
final class EditorialCaptureContinuationService
{
    private CaptureSubjectBindingRecovery $subjectBinding;

    /** @param callable(array<string,mixed>):array|null $assetIngest */
    public function __construct(private CaptureRepository $captures, private CaptureAddendumRepository $addenda, private EditorialCaptureCoordinator $coordinator, private $assetIngest = null, ?CaptureSubjectBindingRecovery $subjectBinding = null)
    {
        $this->subjectBinding = $subjectBinding ?? new CaptureSubjectBindingRecovery($captures);
    }

    /** @return array{capture:array<string,mixed>,retry:array<string,mixed>} */
    public function retry(array $input): array
    {
        $captureId = trim((string) ($input['capture_id'] ?? ''));
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if (!UuidCodec::isValid($captureId)) return $this->retryFailure($captureId, 'CAPTURE_RETRY_CAPTURE_ID_INVALID');
        if (strtoupper(trim((string) ($input['resume_mode'] ?? ''))) !== 'RETRY') return $this->retryFailure($captureId, 'CAPTURE_RETRY_MODE_REQUIRED');
        $capture = $this->captures->findById($captureId);
        if (!$capture instanceof CaptureRecord) return $this->retryFailure($captureId, 'CAPTURE_NOT_FOUND');
        if ($key !== '' && !hash_equals($capture->idempotencyKey, $key)) return $this->retryFailure($captureId, 'CAPTURE_RETRY_IDEMPOTENCY_KEY_MISMATCH', $capture);
        $payloadError = $this->retryPayloadError($capture, $input);
        if ($payloadError !== null) return $this->retryFailure($captureId, $payloadError, $capture);
        $intent = strtoupper(trim((string) ($input['intent'] ?? '')));
        $storedIntent = is_array($capture->context['content_intent'] ?? null) ? strtoupper(trim((string) ($capture->context['content_intent']['intent'] ?? ''))) : '';
        if ($intent !== '' && $storedIntent !== '' && $intent !== $storedIntent) return $this->retryFailure($captureId, 'CAPTURE_RETRY_INTENT_MISMATCH', $capture);
        $purpose = strtoupper(trim((string) ($input['purpose'] ?? '')));
        if ($purpose !== '' && $purpose !== strtoupper(trim((string) ($capture->context['purpose'] ?? 'EDITORIAL')))) return $this->retryFailure($captureId, 'CAPTURE_RETRY_PURPOSE_MISMATCH', $capture);
        if (array_key_exists('subject_reconciliation', $input)) {
            [$capture, $reconciliationError] = $this->reconcileSubject($capture, $input['subject_reconciliation']);
            if ($reconciliationError !== null) return $this->retryFailure($captureId, $reconciliationError, $capture);
            // A completed Capture may receive the exact same confirmation
            // again from a retried client. Reconciliation has already
            // fail-closed checked that it matches the persisted authority;
            // return the canonical read-back without reopening the pipeline.
            if ($capture->status === 'COMPLETE') {
                return ['capture' => $capture->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => 'REPLAYED', 'code' => null, 'eligible' => false, 'reason' => null]];
            }
        }
        $requestedChildren = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? [])))));
        $subjectReconciliationProvided = array_key_exists('subject_reconciliation', $input);
        $videoCompletionRetry = !$subjectReconciliationProvided
            && ($input['_recovery_mode'] ?? false) !== true
            && CaptureCurrentOutcomeReducer::supportsCanonicalVideoCompletionRetry($capture)
            && ($requestedChildren === [] || $requestedChildren === ['video']);
        if ($videoCompletionRetry) {
            $retryInput = $this->rehydrateRetryInput($capture, $input);
            try {
                // Refresh and persist the bounded current outcome before a
                // terminal no-retry response. This keeps retry admission and
                // capture.get on the same current Capture projection.
                $continued = $this->coordinator->retryVideoCompletion($capture, $retryInput);
                if ($this->isCompleteOutcome($continued)) {
                    return ['capture' => $continued->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => $continued->status, 'code' => null, 'eligible' => false, 'reason' => null]];
                }
                $decision = CaptureCurrentOutcomeReducer::retryEligibility($continued);
                $terminalCode = $decision['eligible'] ? CaptureCurrentOutcomeReducer::failureCode($continued) : $decision['reason'];
                return ['capture' => $continued->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => $continued->status, 'code' => $terminalCode, 'eligible' => $decision['eligible'], 'reason' => $decision['reason']]];
            } catch (\Throwable $error) {
                return $this->retryFailure($captureId, $this->code($error), $this->captures->findById($captureId));
            }
        }
        $retryDecision = CaptureCurrentOutcomeReducer::retryEligibility($capture, $input);
        $completionResumable = $retryDecision['eligible'] && $capture->status !== 'FAILED_RETRYABLE';
        if (!$completionResumable && in_array($capture->stage, [CaptureStage::READY_FOR_PUBLICATION->value, CaptureStage::PUBLISHED->value], true)) {
            return ['capture' => $capture->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => 'REPLAYED', 'code' => null]];
        }
        if (!$retryDecision['eligible']) return $this->retryFailure($captureId, (string) ($retryDecision['reason'] ?? 'CAPTURE_RETRY_NOT_ALLOWED'), $capture);

        $retryInput = $this->rehydrateRetryInput($capture, $input);
        try {
            $continued = $this->coordinator->retry($capture, $retryInput);
            if ($this->isCompleteOutcome($continued)) {
                return ['capture' => $continued->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => $continued->status, 'code' => null, 'eligible' => false, 'reason' => null]];
            }
            $decision = CaptureCurrentOutcomeReducer::retryEligibility($continued);
            $retryCode = $decision['eligible'] ? CaptureCurrentOutcomeReducer::failureCode($continued) : $decision['reason'];
            return ['capture' => $continued->toArray(), 'retry' => ['mode' => 'RETRY', 'status' => $continued->status, 'code' => $retryCode, 'eligible' => $decision['eligible'], 'reason' => $decision['reason']]];
        } catch (\Throwable $error) {
            return $this->retryFailure($captureId, $this->code($error), $this->captures->findById($captureId));
        }
    }

    /**
     * Resume one existing Capture through a recovery-only boundary.
     *
     * The caller supplies only the Capture identity and a CAS revision. The
     * persisted original idempotency identity is intentionally used below the
     * transport boundary and is never returned in this projection.
     *
     * @return array{capture:array<string,mixed>,recovery:array<string,mixed>}
     */
    public function recover(array $input): array
    {
        $captureId = trim((string) ($input['capture_id'] ?? ''));
        $expectedRevision = (int) ($input['expected_revision'] ?? 0);
        if (!UuidCodec::isValid($captureId)) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_CAPTURE_ID_INVALID');
        if ($expectedRevision < 1) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_REVISION_REQUIRED');

        $capture = $this->captures->findById($captureId);
        if (!$capture instanceof CaptureRecord) return $this->recoveryFailure($captureId, 'CAPTURE_NOT_FOUND');
        // The MCP transport supplies this only after the internal capability
        // and one-entry-point guards have passed. An owner binding, when
        // present, is still mandatory; legacy rows without one require the
        // stronger internal recovery capability.
        if (($input['_internal_authorized'] ?? false) !== true) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_AUTHORIZATION_REQUIRED', $capture);
        $owner = trim((string) ($capture->context['owner_principal_id'] ?? ''));
        $actor = trim((string) ($input['_actor_id'] ?? ''));
        if ($owner !== '' && ($actor === '' || !hash_equals($owner, $actor))) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_OWNERSHIP_REQUIRED', $capture);

        $children = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? ['video'])))));
        if ($children === []) $children = ['video'];
        if (array_diff($children, ['video']) !== []) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_CHILD_NOT_SUPPORTED', $capture);
        $operationFingerprint = hash('sha256', CommandCanonicalizer::canonicalize([
            'capture_id' => $capture->captureId,
            'capture_revision' => $expectedRevision,
            'resume_children' => $children,
            'subject_reconciliation' => is_array($input['subject_reconciliation'] ?? null) ? $input['subject_reconciliation'] : null,
        ]));
        $audit = is_array($capture->diagnostics['recovery_audit'] ?? null) ? $capture->diagnostics['recovery_audit'] : [];
        foreach ($audit as $entry) {
            if (!is_array($entry) || !hash_equals((string) ($entry['operation_fingerprint'] ?? ''), $operationFingerprint)) continue;
            $current = $this->captures->findById($captureId) ?? $capture;
            return ['capture' => $this->redactRecoveryCapture($current->toArray()), 'recovery' => ['status' => 'REPLAYED', 'audit_id' => (string) ($entry['audit_id'] ?? ''), 'operation_fingerprint' => $operationFingerprint]];
        }
        if ($capture->revision !== $expectedRevision) return $this->recoveryFailure($captureId, 'CAPTURE_RECOVERY_STALE_REVISION', $capture);
        $capture = $this->promoteDocumentationCheckpoint($capture, (array) ($input['documentation_checkpoint'] ?? []));

        $auditId = UuidCodec::newV7();
        $audit[] = [
            'audit_id' => $auditId,
            'operation_fingerprint' => $operationFingerprint,
            'capture_revision' => $capture->revision,
            'actor_id' => $actor !== '' ? $actor : 'internal-recovery',
            'status' => 'STARTED',
            'started_at' => gmdate('c'),
        ];
        $diagnostics = $capture->diagnostics;
        $diagnostics['recovery_audit'] = $audit;
        try {
            $started = $this->captures->save(new CaptureRecord(
                $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint,
                $capture->stage, $capture->status, $capture->articleId, $capture->articleStateToken,
                $capture->assets, $capture->context, $diagnostics, $capture->phaseReceipts,
                $capture->revision + 1, $capture->createdAt, gmdate('Y-m-d H:i:s.u'),
            ));
            $retryInput = [
                'capture_id' => $started->captureId,
                'idempotency_key' => $started->idempotencyKey,
                'resume_mode' => 'RETRY',
                // Recovery is the privileged continuation boundary. It must
                // re-enter the governed plan builder so a stale completion
                // projection cannot bypass provenance and Video Proposal
                // reconciliation.
                '_recovery_mode' => true,
                'resume_children' => $children,
                'documentation_checkpoint' => is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [],
            ];
            if (is_array($input['subject_reconciliation'] ?? null)) $retryInput['subject_reconciliation'] = $input['subject_reconciliation'];
            $result = $this->retry($retryInput);
            $current = $this->captures->findById($captureId);
            if ($current instanceof CaptureRecord) {
                $audit = is_array($current->diagnostics['recovery_audit'] ?? null) ? $current->diagnostics['recovery_audit'] : $audit;
                foreach ($audit as &$entry) {
                    if (is_array($entry) && ($entry['audit_id'] ?? '') === $auditId) {
                        $entry['status'] = (string) ($result['retry']['status'] ?? 'COMPLETED');
                        $entry['code'] = $result['retry']['code'] ?? null;
                        $entry['finished_at'] = gmdate('c');
                    }
                }
                unset($entry);
                $current = $this->captures->save(new CaptureRecord(
                    $current->captureId, $current->idempotencyKey, $current->requestFingerprint,
                    $current->stage, $current->status, $current->articleId, $current->articleStateToken,
                    $current->assets, $current->context, array_replace($current->diagnostics, ['recovery_audit' => $audit]),
                    $current->phaseReceipts, $current->revision + 1, $current->createdAt, gmdate('Y-m-d H:i:s.u'),
                ));
            }
            $captureProjection = $current instanceof CaptureRecord ? $current->toArray() : (array) ($result['capture'] ?? []);
            return ['capture' => $this->redactRecoveryCapture($captureProjection), 'recovery' => ['status' => (string) ($result['retry']['status'] ?? 'COMPLETED'), 'code' => $result['retry']['code'] ?? null, 'audit_id' => $auditId, 'operation_fingerprint' => $operationFingerprint]];
        } catch (Throwable $error) {
            return $this->recoveryFailure($captureId, $this->code($error), $this->captures->findById($captureId), $auditId, $operationFingerprint);
        }
    }

    /** @return array{capture:array<string,mixed>,addendum:array<string,mixed>} */
    public function execute(array $input): array
    {
        $captureId = trim((string) ($input['capture_id'] ?? ''));
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if (!UuidCodec::isValid($captureId)) throw new \InvalidArgumentException('Capture continuation requires a valid capture_id.');
        if ($key === '') throw new \InvalidArgumentException('Capture addendum idempotency key is required.');
        $fingerprint = $this->fingerprint($input);
        $existing = $this->addenda->findByIdempotencyKey($key);
        $governanceOnlyReplay = $this->isGovernanceOnlyReplay($input);
        if ($existing !== null) {
            // Governance is control-plane input, not a new editorial payload.
            // Bind a control-only replay to the persisted request and restore
            // its payload below so provenance cannot disappear on resume.
            if ($governanceOnlyReplay) $fingerprint = $existing->requestFingerprint;
            if (!$governanceOnlyReplay && strtoupper((string) ($existing->payload['followup_mode'] ?? '')) === 'ATTACH_ASSETS' && (!isset($input['files']) || (array) $input['files'] === []) && (!isset($input['media_ids']) || (array) $input['media_ids'] === [])) $fingerprint = $this->fingerprint($input, $existing);
            if (!hash_equals($existing->requestFingerprint, $fingerprint) || $existing->captureId !== $captureId) return $this->conflict($existing, $captureId, $fingerprint);
            // A governance decision is a continuation of the same addendum,
            // not a new addendum. Re-run the guarded semantic checkpoint with
            // the persisted delta while preserving the original idempotency
            // binding and Capture identity.
            $control = is_array($input['governance'] ?? null) ? $input['governance'] : [];
            $requestedChildren = is_array($existing->payload['resume_children'] ?? null) ? $existing->payload['resume_children'] : [];
            if ($requestedChildren !== []) $control['resume_children'] = $requestedChildren;
            // Replayed addenda must carry the persisted child-selection
            // control back into the coordinator. Without this, a stored
            // video-only resume is reduced to an empty semantic continuation.
            if ($control !== []) $input['governance'] = $control;
            if ($control !== []) {
                $capture = $this->captures->findById($captureId);
                if (!$capture instanceof CaptureRecord) return $this->response(null, $existing);
                $persistedPayload = is_array($existing->payload) ? $existing->payload : [];
                $persistedMetadata = is_array($persistedPayload['metadata'] ?? null) ? $persistedPayload['metadata'] : [];
                $input['metadata'] = $persistedMetadata + (is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
                if (trim((string) ($input['intent'] ?? '')) === '' && isset($persistedPayload['intent'])) $input['intent'] = (string) $persistedPayload['intent'];
                if (strtoupper((string) ($existing->payload['followup_mode'] ?? '')) === 'ATTACH_ASSETS') {
                    $input['followup_mode'] = 'ATTACH_ASSETS';
                    $input['asset_followup_items'] = array_values(array_filter((array) ($existing->payload['asset_manifest']['items'] ?? []), 'is_array'));
                    $input['asset_followup_manifest'] = is_array($existing->payload['asset_manifest'] ?? null) ? $existing->payload['asset_manifest'] : [];
                    $input['visual_context'] = is_array($existing->payload['visual_context'] ?? null) ? $existing->payload['visual_context'] : [];
                    $input['asset_followup_replay'] = true;
                }
                $input['text'] = (($input['metadata']['editorial_replacement'] ?? false) === true) ? trim((string) ($persistedPayload['text'] ?? '')) : '';
                $input['subject_hints'] = (array) ($existing->payload['subject_hints'] ?? []);
                $input['observations'] = is_array($existing->payload['observations'] ?? null) ? $existing->payload['observations'] : [];
                $input['existing_capture_continuation'] = true;
                $input['continuation_idempotency_key'] = $key;
                $input['continuation_delta_text'] = trim((string) ($existing->payload['text'] ?? ''));
                try {
                    $continued = $this->coordinator->continueWithAddendum($capture, $input);
                    return $this->response($continued, $existing);
                } catch (\Throwable $error) {
                    $failed = $this->saveAddendum($existing, 'FAILED', $capture->revision, ['code' => $this->code($error)]);
                    return $this->response($this->captures->findById($captureId), $failed);
                }
            }
            return $this->response($this->captures->findById($captureId), $existing);
        }

        $capture = $this->captures->findById($captureId);
        if (!$capture instanceof CaptureRecord) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_NOT_FOUND', $input);
        if ($capture->stage === CaptureStage::PUBLISHED->value) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_CONTINUATION_NOT_ALLOWED_AFTER_PUBLICATION', $input);
        $assetFollowup = strtoupper(trim((string) ($input['followup_mode'] ?? ''))) === 'ATTACH_ASSETS';
        $hasFiles = isset($input['files']) && (array) $input['files'] !== [];
        $hasMediaIds = isset($input['media_ids']) && (array) $input['media_ids'] !== [];
        if ($hasFiles && $hasMediaIds) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_PHYSICAL_INPUT_AMBIGUOUS', $input);
        if ($assetFollowup && !$hasFiles && !$hasMediaIds) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_ASSET_FOLLOWUP_FILES_REQUIRED', $input);
        if (!$assetFollowup && $hasFiles) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_ADDENDUM_FILES_NOT_ALLOWED', $input);
        if (!$assetFollowup && $hasMediaIds) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_ADDENDUM_MEDIA_IDS_NOT_ALLOWED', $input);
        foreach (['video' => 'CAPTURE_ADDENDUM_VIDEO_NOT_ALLOWED'] as $field => $code) {
            if (isset($input[$field]) && (array) $input[$field] !== []) return $this->failed($captureId, $key, $fingerprint, $code, $input);
        }
        if (!$assetFollowup && isset($input['items']) && (array) $input['items'] !== []) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_ADDENDUM_ITEMS_NOT_ALLOWED', $input);
        $resumeChildren = $input['resume_children'] ?? [];
        if ($resumeChildren !== [] && !is_array($resumeChildren)) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_RESUME_CHILDREN_INVALID', $input);
        $resumeChildren = array_values(array_unique(array_map('strtolower', array_map('strval', (array) $resumeChildren))));
        if (array_diff($resumeChildren, ['video']) !== []) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_RESUME_CHILD_NOT_SUPPORTED', $input);

        $pending = new CaptureAddendumRecord(UuidCodec::newV7(), $captureId, $key, $fingerprint, 'IN_PROGRESS', $this->payload($input), $capture->revision);
        $addendum = $this->addenda->create($pending);
        if ($addendum->addendumId !== $pending->addendumId) {
            if (!hash_equals($addendum->requestFingerprint, $fingerprint) || $addendum->captureId !== $captureId) return $this->conflict($addendum, $captureId, $fingerprint);
            return $this->response($this->captures->findById($captureId), $addendum);
        }
        try {
            // This marker is an application-internal continuation context. It
            // is not part of the addendum payload or its fingerprint.
            $input['existing_capture_continuation'] = true;
            $input['continuation_idempotency_key'] = $key;
            $input['continuation_delta_text'] = trim((string) ($input['text'] ?? $input['content'] ?? ''));
            if ($resumeChildren !== []) $input['governance'] = ['resume_children' => $resumeChildren] + (is_array($input['governance'] ?? null) ? $input['governance'] : []);
            if ($assetFollowup) {
                if (!is_callable($this->assetIngest)) throw new \RuntimeException('CAPTURE_ASSET_FOLLOWUP_INGEST_UNAVAILABLE');
                $manifest = ($this->assetIngest)(['capture_id' => $captureId, 'idempotency_key' => $key . ':assets', 'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [], 'files' => $input['files'] ?? [], 'media_ids' => $input['media_ids'] ?? [], 'items' => is_array($input['items'] ?? null) ? $input['items'] : []]);
                $items = array_values(array_filter((array) ($manifest['items'] ?? []), 'is_array'));
                if ($items === []) throw new \RuntimeException('CAPTURE_ASSET_FOLLOWUP_READBACK_UNAVAILABLE');
                $input['asset_followup_items'] = $items;
                $input['asset_followup_manifest'] = $manifest;
            }
            $continued = $this->coordinator->continueWithAddendum($capture, $input);
            if (in_array($continued->status, ['FAILED_RETRYABLE', 'SYSTEM_BLOCKED'], true)) {
                $failed = $this->saveAddendum($addendum, 'FAILED', $continued->revision, ['code' => (string) ($continued->diagnostics['failure']['code'] ?? 'CAPTURE_CONTINUATION_FAILED')]);
                return $this->response($continued, $failed);
            }
            $context = $continued->context;
            $audit = is_array($context['continuations'] ?? null) ? $context['continuations'] : [];
            $payload = $addendum->payload;
            if ($assetFollowup && is_array($input['asset_followup_manifest'] ?? null)) $payload['asset_manifest'] = $this->safeManifest($input['asset_followup_manifest']);
            $resultingRevision = $continued->revision + 1;
            $audit[] = ['addendum_id' => $addendum->addendumId, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'payload' => $payload, 'capture_revision' => $resultingRevision, 'status' => 'COMPLETED', 'at' => gmdate('c')];
            $continuationState = [
                'raw_input' => trim((string) ($continued->context['continuation_state']['raw_input'] ?? $continued->context['raw_input'] ?? '')),
                'subject_hints' => is_array($continued->context['continuation_state']['subject_hints'] ?? null) ? $continued->context['continuation_state']['subject_hints'] : (array) ($continued->context['subject_hints'] ?? []),
                'observations' => is_array($continued->context['continuation_state']['observations'] ?? null) ? $continued->context['continuation_state']['observations'] : (array) ($continued->context['observations'] ?? []),
            ];
            $replacement = ($payload['metadata']['editorial_replacement'] ?? false) === true;
            $continuationState['raw_input'] = $replacement
                ? trim((string) ($payload['text'] ?? ''))
                : trim(implode("\n\n", array_values(array_filter([$continuationState['raw_input'], (string) ($payload['text'] ?? '')], static fn (string $value): bool => trim($value) !== ''))));
            if (is_array($payload['subject_hints'] ?? null) && $payload['subject_hints'] !== []) $continuationState['subject_hints'] = array_values(array_unique(array_map('strval', $payload['subject_hints'])));
            $continuationState['observations'] = array_merge($continuationState['observations'], is_array($payload['observations'] ?? null) ? $payload['observations'] : []);
            $updatedContext = $context;
            $updatedContext['continuations'] = $audit;
            $updatedContext['continuation_state'] = $continuationState;
            $updated = $this->captures->save(new CaptureRecord($continued->captureId, $continued->idempotencyKey, $continued->requestFingerprint, $continued->stage, $continued->status, $continued->articleId, $continued->articleStateToken, $continued->assets, $updatedContext, $continued->diagnostics, $continued->phaseReceipts, $resultingRevision, $continued->createdAt, gmdate('Y-m-d H:i:s.u')));
            $completed = $this->saveAddendum($addendum, 'COMPLETED', $updated->revision, [], $payload);
            return $this->response($updated, $completed);
        } catch (\Throwable $error) {
            $failed = $this->saveAddendum($addendum, 'FAILED', $capture->revision, ['code' => $this->code($error)]);
            return $this->response($this->captures->findById($captureId), $failed);
        }
    }

    /** @return array<string,mixed> */
    private function payload(array $input): array
    {
        $resumeChildren = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? [])))));
        $payload = ['text' => trim((string) ($input['text'] ?? $input['content'] ?? '')), 'subject_hints' => is_array($input['subject_hints'] ?? null) ? array_values($input['subject_hints']) : [], 'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [], 'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : []];
        if (trim((string) ($input['intent'] ?? '')) !== '') $payload['intent'] = (string) $input['intent'];
        if ($resumeChildren !== []) $payload['resume_children'] = $resumeChildren;
        if (strtoupper(trim((string) ($input['followup_mode'] ?? ''))) === 'ATTACH_ASSETS') {
            $payload['followup_mode'] = 'ATTACH_ASSETS';
            $payload['asset_fingerprints'] = ($input['files'] ?? []) !== [] ? $this->assetFingerprints((array) $input['files']) : array_map(static fn (mixed $id): array => ['media_id' => (string) $id], (array) ($input['media_ids'] ?? []));
            if (($input['media_ids'] ?? []) !== []) $payload['media_ids'] = $this->safeMediaIds((array) $input['media_ids']);
            $payload['items'] = $this->safeItems((array) ($input['items'] ?? []));
            if (is_array($input['visual_context'] ?? null)) $payload['visual_context'] = $input['visual_context'];
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function rehydrateRetryInput(CaptureRecord $capture, array $control): array
    {
        $context = $capture->context;
        $continuation = is_array($context['continuation_state'] ?? null) ? $context['continuation_state'] : [];
        $intent = is_array($context['content_intent'] ?? null) ? $context['content_intent'] : [];
        $original = is_array($context['original_request'] ?? null) ? $context['original_request'] : [];
        $governance = is_array($control['governance'] ?? null) ? $control['governance'] : [];
        $children = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($control['resume_children'] ?? [])))));
        if ($children === []) {
            $children = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($capture->diagnostics['resume_hints']['resume_children'] ?? [])))));
        }
        if ($children === [] && CaptureCurrentOutcomeReducer::supportsCanonicalVideoCompletionRetry($capture)) $children = ['video'];
        if ($children !== []) $governance['resume_children'] = $children;
        return [
            'purpose' => (string) ($context['purpose'] ?? 'EDITORIAL'),
            'idempotency_key' => $capture->idempotencyKey,
            'text' => (string) ($continuation['raw_input'] ?? $context['raw_input'] ?? ''),
            'title' => (string) ($context['title'] ?? ''),
            'excerpt' => (string) ($context['excerpt'] ?? ''),
            'subject_hints' => is_array($continuation['subject_hints'] ?? null) ? array_values($continuation['subject_hints']) : (is_array($context['subject_hints'] ?? null) ? array_values($context['subject_hints']) : []),
            'observations' => is_array($continuation['observations'] ?? null) ? $continuation['observations'] : (is_array($context['observations'] ?? null) ? $context['observations'] : []),
            'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : [],
            'media_bindings' => is_array($context['media_bindings'] ?? null) ? $context['media_bindings'] : [],
            'media_operations' => is_array($context['media_operations'] ?? null) ? $context['media_operations'] : [],
            'intent' => (string) ($intent['intent'] ?? ($original['intent'] ?? '')),
            'publish' => ($original['publish'] ?? false) === true,
            'video' => is_array($original['video'] ?? null) ? $original['video'] : [],
            'documentation_checkpoint' => is_array($control['documentation_checkpoint'] ?? null) ? $control['documentation_checkpoint'] : (is_array($context['documentation_checkpoint'] ?? null) ? $context['documentation_checkpoint'] : []),
            'governance' => $governance,
            '_recovery_mode' => ($control['_recovery_mode'] ?? false) === true,
            'existing_capture_retry' => true,
            'existing_capture_continuation' => true,
            'continuation_idempotency_key' => $capture->idempotencyKey,
        ];
    }

    private function isCompleteOutcome(CaptureRecord $capture): bool
    {
        return $capture->status === 'COMPLETE'
            && (($capture->diagnostics['completion']['complete'] ?? false) === true);
    }

    /** @return array{0:CaptureRecord,1:?string} */
    private function reconcileSubject(CaptureRecord $capture, mixed $selection): array
    {
        if ($capture->status === 'COMPLETE') {
            if (!is_array($selection) || ($selection['confirmed'] ?? false) !== true) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CONFIRMATION_REQUIRED'];
            $candidateId = trim((string) ($selection['candidate_uuid'] ?? ''));
            $persistedCandidate = trim((string) ($capture->diagnostics['subject_reconciliation']['candidate_uuid'] ?? $capture->context['subject_resolution_packet']['canonical_subject_id'] ?? ''));
            if (!UuidCodec::isValid($candidateId) || $persistedCandidate === '' || !hash_equals(strtolower($persistedCandidate), strtolower($candidateId))) {
                return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED'];
            }
            return [$capture, null];
        }
        if (!in_array($capture->status, ['FAILED_RETRYABLE', 'REVIEW_REQUIRED', 'APPLIED'], true)
            && $capture->stage !== CaptureStage::AUTHORITY_APPLIED->value) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_STATUS_NOT_ALLOWED'];
        $intent = strtoupper(trim((string) (($capture->context['content_intent']['intent'] ?? ''))));
        if (!in_array($intent, ['VIDEO', 'IMAGE_ARTICLE', 'TEXT_ARTICLE'], true)) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_INTENT_NOT_SUPPORTED'];
        if (!is_array($selection) || ($selection['confirmed'] ?? false) !== true) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CONFIRMATION_REQUIRED'];
        $candidateId = trim((string) ($selection['candidate_uuid'] ?? ''));
        $persistedPacket = SubjectResolutionPacket::fromArray(
            is_array($capture->context['subject_resolution_packet'] ?? null)
                ? $capture->context['subject_resolution_packet']
                : (is_array($capture->diagnostics['subject_resolution_packet'] ?? null) ? $capture->diagnostics['subject_resolution_packet'] : []),
        );
        if ($persistedPacket?->status === 'resolved' && UuidCodec::isValid($candidateId)) {
            if (!hash_equals(strtolower($persistedPacket->canonicalSubjectId), strtolower($candidateId))) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED'];
            // A resolved server-owned packet is already the canonical handoff.
            // Treat a repeated confirmation as retry control, not a new
            // candidate-selection payload and do not increment Capture state.
            return [$capture, null];
        }
        $authority = strtoupper(trim((string) ($selection['authority'] ?? $selection['source'] ?? '')));
        $serverPacket = ResolvedSubjectReconciliationPacket::fromArray(is_array($selection['reconciliation_packet'] ?? null) ? $selection['reconciliation_packet'] : []);
        if (is_array($selection['reconciliation_packet'] ?? null)) {
            if (!in_array($authority, ['USER_CONFIRMED_SUBJECT_RECONCILIATION', 'GOVERNED_SUBJECT_RECONCILIATION'], true)) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_AUTHORITY_REQUIRED'];
            if ($serverPacket === null) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_PACKET_INVALID'];
            if (!$serverPacket->matches($capture)) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_PACKET_STALE'];
            return [$this->persistResolvedReconciliation($capture, $serverPacket->subject, [
                'status' => 'CONFIRMED',
                'source' => $authority,
                'packet_fingerprint' => $serverPacket->fingerprint(),
                'capture_revision' => $serverPacket->captureRevision,
                'evidence_refs' => $serverPacket->evidenceRefs,
            ]), null];
        }
        $explicitPacket = SubjectResolutionPacket::fromArray(is_array($selection['packet'] ?? null) ? $selection['packet'] : (is_array($selection['subject_resolution_packet'] ?? null) ? $selection['subject_resolution_packet'] : []));
        if (in_array($capture->status, ['REVIEW_REQUIRED', 'APPLIED'], true) && $explicitPacket instanceof SubjectResolutionPacket
            && $explicitPacket->status === 'resolved'
            && in_array($authority, ['USER_CONFIRMED_SUBJECT_RECONCILIATION', 'GOVERNED_SUBJECT_RECONCILIATION'], true)
        ) {
            return [$this->persistResolvedReconciliation($capture, $explicitPacket), null];
        }
        if (!UuidCodec::isValid($candidateId)) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED'];
        $rawPacket = is_array($capture->context['subject_resolution_packet'] ?? null)
            ? $capture->context['subject_resolution_packet']
            : (is_array($capture->diagnostics['subject_resolution_packet'] ?? null) ? $capture->diagnostics['subject_resolution_packet'] : []);
        $packet = SubjectResolutionPacket::fromArray($rawPacket);
        if (!$packet instanceof SubjectResolutionPacket || $packet->status !== 'ambiguous') return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_NOT_AMBIGUOUS'];
        $candidate = $this->candidateById($packet->diagnostics['candidates'] ?? [], $candidateId);
        if ($candidate === null) return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED'];
        $type = trim((string) ($candidate['type'] ?? $candidate['entity_type'] ?? ''));
        $revision = (int) ($candidate['revision'] ?? 0);
        $lifecycle = strtolower(trim((string) ($candidate['status'] ?? $candidate['state'] ?? ($candidate['canonical_readback']['status'] ?? 'active'))));
        if ($type === '' || $revision < 1 || ($candidate['active'] ?? true) !== true || in_array($lifecycle, ['retired', 'inactive', 'missing'], true)) {
            return [$capture, 'CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED'];
        }

        $resolved = new SubjectResolutionPacket(
            'resolved',
            $candidateId,
            $type,
            trim((string) ($candidate['stable_key'] ?? '')),
            trim((string) ($candidate['name'] ?? $candidate['canonical_name'] ?? '')),
            $revision,
            'user_confirmed_candidate',
            [
                'candidates' => $packet->diagnostics['candidates'] ?? [],
                'unresolved' => $packet->diagnostics['unresolved'] ?? [],
                'conflicts' => $packet->diagnostics['conflicts'] ?? [],
                'diagnostics' => ['USER_CONFIRMED_SUBJECT_RECONCILIATION'],
            ],
            'USER_CONFIRMED_SUBJECT_RECONCILIATION',
        );
        return [$this->persistResolvedReconciliation($capture, $resolved, [
            'status' => 'CONFIRMED',
            'candidate_uuid' => $candidateId,
            'source' => 'USER_CONFIRMED_SUBJECT_RECONCILIATION',
        ]), null];
    }

    /** @param array<string,mixed> $metadata */
    private function persistResolvedReconciliation(CaptureRecord $capture, SubjectResolutionPacket $resolved, array $metadata = []): CaptureRecord
    {
        $metadata = $metadata !== [] ? $metadata : ['status' => 'CONFIRMED', 'source' => $resolved->primarySource];
        return $this->subjectBinding->persist($capture, (int) ($capture->articleId ?? 0), $resolved->toResolution(), $metadata);
    }

    /** @param array<string,mixed> $buckets */
    private function candidateById(array $buckets, string $candidateId): ?array
    {
        $walk = function (mixed $value) use (&$walk, $candidateId): ?array {
            if (!is_array($value)) return null;
            if (isset($value['id']) && is_scalar($value['id']) && strcasecmp(trim((string) $value['id']), $candidateId) === 0) return $value;
            foreach ($value as $item) {
                $found = $walk($item);
                if ($found !== null) return $found;
            }
            return null;
        };
        return $walk($buckets);
    }

    /** @return array{capture:array<string,mixed>,retry:array<string,mixed>} */
    private function retryFailure(string $captureId, string $code, ?CaptureRecord $capture = null): array
    {
        return ['capture' => $capture?->toArray() ?? ['capture_id' => $captureId, 'status' => 'unavailable'], 'retry' => ['mode' => 'RETRY', 'status' => 'FAILED', 'code' => $code]];
    }

    /** @return array{capture:array<string,mixed>,recovery:array<string,mixed>} */
    private function recoveryFailure(string $captureId, string $code, ?CaptureRecord $capture = null, string $auditId = '', string $operationFingerprint = ''): array
    {
        return [
            'capture' => $capture instanceof CaptureRecord ? $this->redactRecoveryCapture($capture->toArray()) : ['capture_id' => $captureId, 'status' => 'unavailable'],
            'recovery' => ['status' => 'FAILED', 'code' => $code, 'audit_id' => $auditId !== '' ? $auditId : null, 'operation_fingerprint' => $operationFingerprint !== '' ? $operationFingerprint : null],
        ];
    }

    /** @param array<string,mixed> $capture */
    private function redactRecoveryCapture(array $capture): array
    {
        unset($capture['idempotency_key']);
        return $capture;
    }

    private function promoteDocumentationCheckpoint(CaptureRecord $capture, array $checkpoint): CaptureRecord
    {
        if ($checkpoint === []) return $capture;
        $context = $capture->context;
        $current = is_array($context['documentation_checkpoint'] ?? null) ? $context['documentation_checkpoint'] : [];
        if ($current !== [] && CommandCanonicalizer::canonicalize($current) !== CommandCanonicalizer::canonicalize($checkpoint)) {
            $history = is_array($context['documentation_checkpoint_history'] ?? null) ? $context['documentation_checkpoint_history'] : [];
            $history[] = $current;
            $context['documentation_checkpoint_history'] = array_values($history);
        }
        $context['documentation_checkpoint'] = $checkpoint;
        return new CaptureRecord(
            $capture->captureId,
            $capture->idempotencyKey,
            $capture->requestFingerprint,
            $capture->stage,
            $capture->status,
            $capture->articleId,
            $capture->articleStateToken,
            $capture->assets,
            $context,
            $capture->diagnostics,
            $capture->phaseReceipts,
            $capture->revision,
            $capture->createdAt,
            $capture->updatedAt,
        );
    }

    private function fingerprint(array $input, ?CaptureAddendumRecord $existing = null): string
    {
        $payload = $this->payload($input);
        if ($existing !== null && ($payload['followup_mode'] ?? '') === 'ATTACH_ASSETS' && ($payload['asset_fingerprints'] ?? []) === []) $payload['asset_fingerprints'] = $existing->payload['asset_fingerprints'] ?? [];
        return hash('sha256', CommandCanonicalizer::canonicalize($payload));
    }

    private function retryPayloadError(CaptureRecord $capture, array $input): ?string
    {
        $context = $capture->context;
        $original = is_array($context['original_request'] ?? null) ? $context['original_request'] : [];
        $expected = [
            'text' => (string) ($context['raw_input'] ?? ''),
            'content' => (string) ($context['raw_input'] ?? ''),
            'title' => (string) ($context['title'] ?? ''),
            'excerpt' => (string) ($context['excerpt'] ?? ''),
            'subject_hints' => is_array($context['subject_hints'] ?? null) ? $context['subject_hints'] : [],
            'observations' => is_array($context['observations'] ?? null) ? $context['observations'] : [],
            'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : [],
            'video' => is_array($original['video'] ?? null) ? $original['video'] : [],
        ];
        foreach ($expected as $field => $canonical) {
            if (!array_key_exists($field, $input) || $this->retryValueEmpty($input[$field])) continue;
            if ($field === 'text' && array_key_exists('content', $input) && !$this->retryValueEmpty($input['content'])) continue;
            if ($field === 'content' && array_key_exists('text', $input) && !$this->retryValueEmpty($input['text'])) continue;
            if ($field === 'video') {
                if (!$this->sameVideoIdentity($input[$field], $canonical)) return 'CAPTURE_RETRY_PAYLOAD_NOT_ALLOWED';
                continue;
            }
            if (!$this->sameCanonicalValue($input[$field], $canonical)) return 'CAPTURE_RETRY_PAYLOAD_NOT_ALLOWED';
        }
        foreach (['media', 'items', 'media_ids', 'existing_media_urls', 'media_bindings', 'media_operations', 'publish', 'files', 'followup_mode'] as $field) {
            if (array_key_exists($field, $input) && !$this->retryValueEmpty($input[$field])) return 'CAPTURE_RETRY_PAYLOAD_NOT_ALLOWED';
        }
        return null;
    }

    private function retryValueEmpty(mixed $value): bool
    {
        return is_array($value) ? $value === [] : ($value === false || $value === null || trim((string) $value) === '');
    }

    private function sameCanonicalValue(mixed $left, mixed $right): bool
    {
        if (is_array($left) && is_array($right)) return CommandCanonicalizer::canonicalize($left) === CommandCanonicalizer::canonicalize($right);
        return trim((string) $left) === trim((string) $right);
    }

    private function sameVideoIdentity(mixed $left, mixed $right): bool
    {
        $leftUrl = is_array($left) ? trim((string) ($left['url'] ?? $left['canonical_source_url'] ?? '')) : trim((string) $left);
        $rightUrl = is_array($right) ? trim((string) ($right['url'] ?? $right['canonical_source_url'] ?? '')) : trim((string) $right);
        if ($leftUrl === '' || $rightUrl === '') return $this->sameCanonicalValue($left, $right);
        try {
            $leftIdentity = \NHK\Core\Application\Video\YouTubeUrlNormalizer::normalize($leftUrl);
            $rightIdentity = \NHK\Core\Application\Video\YouTubeUrlNormalizer::normalize($rightUrl);
            return $leftIdentity->platform === $rightIdentity->platform && $leftIdentity->videoId === $rightIdentity->videoId;
        } catch (\Throwable) {
            return $this->sameCanonicalValue($left, $right);
        }
    }

    private function isGovernanceOnlyReplay(array $input): bool
    {
        if (!is_array($input['governance'] ?? null) || $input['governance'] === []) return false;
        foreach (['text', 'content', 'intent', 'subject_hints', 'observations', 'metadata', 'files', 'media_ids', 'items', 'followup_mode', 'resume_children'] as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') return false;
        }
        return true;
    }

    private function saveAddendum(CaptureAddendumRecord $record, string $status, int $captureRevision, array $diagnostics, ?array $payload = null): CaptureAddendumRecord
    {
        return $this->addenda->save(new CaptureAddendumRecord($record->addendumId, $record->captureId, $record->idempotencyKey, $record->requestFingerprint, $status, $payload ?? $record->payload, $captureRevision, $diagnostics, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
    }

    /** @return list<array<string,mixed>> */
    private function assetFingerprints(array $files): array
    {
        $files = isset($files['files']) && is_array($files['files']) ? $files['files'] : $files;
        if (isset($files['tmp_name']) && !is_array($files['tmp_name'])) $files = [$files];
        if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            $normalized = [];
            foreach ($files['tmp_name'] as $index => $path) $normalized[] = ['name' => is_array($files['name'] ?? null) ? (string) ($files['name'][$index] ?? '') : '', 'size' => is_array($files['size'] ?? null) ? (int) ($files['size'][$index] ?? 0) : 0, 'checksum' => is_file((string) $path) ? hash_file('sha256', (string) $path) : null];
            return $normalized;
        }
        return array_values(array_map(static function (mixed $file): array { $file = is_array($file) ? $file : []; $path = (string) ($file['tmp_name'] ?? ''); return ['name' => (string) ($file['name'] ?? ''), 'size' => (int) ($file['size'] ?? 0), 'checksum' => is_file($path) ? hash_file('sha256', $path) : null]; }, $files));
    }

    /** @return list<array<string,mixed>> */
    private function safeItems(array $items): array
    {
        return array_values(array_map(static function (mixed $item): array {
            $item = is_array($item) ? $item : [];
            $safe = ['client_file_id' => $item['client_file_id'] ?? null, 'sort_order' => $item['sort_order'] ?? null, 'title' => $item['title'] ?? null, 'media' => is_array($item['media'] ?? null) ? $item['media'] : null, 'media_context' => is_array($item['media_context'] ?? null) ? $item['media_context'] : null, 'visual_context' => is_array($item['visual_context'] ?? null) ? $item['visual_context'] : null];
            return array_filter($safe, static fn (mixed $value): bool => $value !== null && $value !== '');
        }, $items));
    }

    /** @return list<string> */
    private function safeMediaIds(array $mediaIds): array
    {
        return array_values(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $mediaIds)));
    }

    /** @return array<string,mixed> */
    private function safeManifest(array $manifest): array
    {
        $copy = $manifest;
        unset($copy['tmp_name'], $copy['path'], $copy['file_path']);
        if (is_array($copy['items'] ?? null)) $copy['items'] = array_values(array_map(static function (mixed $item): array { $item = is_array($item) ? $item : []; unset($item['tmp_name'], $item['path'], $item['file_path']); return $item; }, $copy['items']));
        if (is_array($copy['errors'] ?? null)) $copy['errors'] = array_values(array_map(static function (mixed $item): array { $item = is_array($item) ? $item : []; unset($item['tmp_name'], $item['path'], $item['file_path']); return $item; }, $copy['errors']));
        return $copy;
    }

    /** @return array{capture:array<string,mixed>,addendum:array<string,mixed>} */
    private function response(?CaptureRecord $capture, CaptureAddendumRecord $addendum): array
    {
        return ['capture' => $capture?->toArray() ?? ['capture_id' => $addendum->captureId, 'status' => 'unavailable'], 'addendum' => $addendum->toArray()];
    }

    /** @return array{capture:array<string,mixed>,addendum:array<string,mixed>} */
    private function conflict(CaptureAddendumRecord $record, string $captureId, string $fingerprint): array
    {
        $conflict = new CaptureAddendumRecord($record->addendumId, $record->captureId, $record->idempotencyKey, $record->requestFingerprint, 'IDEMPOTENCY_CONFLICT', $record->payload, $record->captureRevision, ['code' => 'CAPTURE_ADDENDUM_IDEMPOTENCY_CONFLICT', 'request_fingerprint' => $fingerprint], $record->revision, $record->createdAt, $record->updatedAt);
        return $this->response($this->captures->findById($captureId), $conflict);
    }

    /** @return array{capture:array<string,mixed>,addendum:array<string,mixed>} */
    private function failed(string $captureId, string $key, string $fingerprint, string $code, array $input = []): array
    {
        $record = $this->addenda->create(new CaptureAddendumRecord(UuidCodec::newV7(), $captureId, $key, $fingerprint, 'FAILED', $this->payload($input), null, ['code' => $code]));
        return $this->response($this->captures->findById($captureId), $record);
    }

    private function code(\Throwable $error): string
    {
        $message = strtoupper(trim($error->getMessage()));
        return $message !== '' ? preg_replace('/[^A-Z0-9_:-]+/', '_', $message) ?? 'CAPTURE_CONTINUATION_FAILED' : 'CAPTURE_CONTINUATION_FAILED';
    }
}
