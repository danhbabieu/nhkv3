<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Continues one existing Capture/Post with an idempotent text addendum. */
final class EditorialCaptureContinuationService
{
    public function __construct(private CaptureRepository $captures, private CaptureAddendumRepository $addenda, private EditorialCaptureCoordinator $coordinator) {}

    /** @return array{capture:array<string,mixed>,addendum:array<string,mixed>} */
    public function execute(array $input): array
    {
        $captureId = trim((string) ($input['capture_id'] ?? ''));
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if (!UuidCodec::isValid($captureId)) throw new \InvalidArgumentException('Capture continuation requires a valid capture_id.');
        if ($key === '') throw new \InvalidArgumentException('Capture addendum idempotency key is required.');
        $fingerprint = $this->fingerprint($input);
        $existing = $this->addenda->findByIdempotencyKey($key);
        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint, $fingerprint) || $existing->captureId !== $captureId) return $this->conflict($existing, $captureId, $fingerprint);
            // A governance decision is a continuation of the same addendum,
            // not a new addendum. Re-run the guarded semantic checkpoint with
            // the persisted delta while preserving the original idempotency
            // binding and Capture identity.
            $control = is_array($input['governance'] ?? null) ? $input['governance'] : [];
            if ($control !== []) {
                $capture = $this->captures->findById($captureId);
                if (!$capture instanceof CaptureRecord) return $this->response(null, $existing);
                $input['text'] = '';
                $input['subject_hints'] = (array) ($existing->payload['subject_hints'] ?? []);
                $input['observations'] = [];
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
        if (isset($input['files']) && (array) $input['files'] !== []) return $this->failed($captureId, $key, $fingerprint, 'CAPTURE_ADDENDUM_FILES_NOT_ALLOWED', $input);
        foreach (['video' => 'CAPTURE_ADDENDUM_VIDEO_NOT_ALLOWED', 'items' => 'CAPTURE_ADDENDUM_ITEMS_NOT_ALLOWED'] as $field => $code) {
            if (isset($input[$field]) && (array) $input[$field] !== []) return $this->failed($captureId, $key, $fingerprint, $code, $input);
        }

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
            $continued = $this->coordinator->continueWithAddendum($capture, $input);
            if ($continued->status === 'FAILED_RETRYABLE') {
                $failed = $this->saveAddendum($addendum, 'FAILED', $continued->revision, ['code' => (string) ($continued->diagnostics['failure']['code'] ?? 'CAPTURE_CONTINUATION_FAILED')]);
                return $this->response($continued, $failed);
            }
            $context = $continued->context;
            $audit = is_array($context['continuations'] ?? null) ? $context['continuations'] : [];
            $payload = $addendum->payload;
            $resultingRevision = $continued->revision + 1;
            $audit[] = ['addendum_id' => $addendum->addendumId, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'payload' => $payload, 'capture_revision' => $resultingRevision, 'status' => 'COMPLETED', 'at' => gmdate('c')];
            $continuationState = [
                'raw_input' => trim((string) ($continued->context['continuation_state']['raw_input'] ?? $continued->context['raw_input'] ?? '')),
                'subject_hints' => is_array($continued->context['continuation_state']['subject_hints'] ?? null) ? $continued->context['continuation_state']['subject_hints'] : (array) ($continued->context['subject_hints'] ?? []),
                'observations' => is_array($continued->context['continuation_state']['observations'] ?? null) ? $continued->context['continuation_state']['observations'] : (array) ($continued->context['observations'] ?? []),
            ];
            $continuationState['raw_input'] = trim(implode("\n\n", array_values(array_filter([$continuationState['raw_input'], (string) ($payload['text'] ?? '')], static fn (string $value): bool => trim($value) !== ''))));
            if (is_array($payload['subject_hints'] ?? null) && $payload['subject_hints'] !== []) $continuationState['subject_hints'] = array_values(array_unique(array_map('strval', $payload['subject_hints'])));
            $continuationState['observations'] = array_merge($continuationState['observations'], is_array($payload['observations'] ?? null) ? $payload['observations'] : []);
            $updatedContext = $context;
            $updatedContext['continuations'] = $audit;
            $updatedContext['continuation_state'] = $continuationState;
            $updated = $this->captures->save(new CaptureRecord($continued->captureId, $continued->idempotencyKey, $continued->requestFingerprint, $continued->stage, $continued->status, $continued->articleId, $continued->articleStateToken, $continued->assets, $updatedContext, $continued->diagnostics, $continued->phaseReceipts, $resultingRevision, $continued->createdAt, gmdate('Y-m-d H:i:s.u')));
            $completed = $this->saveAddendum($addendum, 'COMPLETED', $updated->revision, []);
            return $this->response($updated, $completed);
        } catch (\Throwable $error) {
            $failed = $this->saveAddendum($addendum, 'FAILED', $capture->revision, ['code' => $this->code($error)]);
            return $this->response($this->captures->findById($captureId), $failed);
        }
    }

    /** @return array<string,mixed> */
    private function payload(array $input): array
    {
        return ['text' => trim((string) ($input['text'] ?? $input['content'] ?? '')), 'subject_hints' => is_array($input['subject_hints'] ?? null) ? array_values($input['subject_hints']) : [], 'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [], 'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : []];
    }

    private function fingerprint(array $input): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize($this->payload($input)));
    }

    private function saveAddendum(CaptureAddendumRecord $record, string $status, int $captureRevision, array $diagnostics): CaptureAddendumRecord
    {
        return $this->addenda->save(new CaptureAddendumRecord($record->addendumId, $record->captureId, $record->idempotencyKey, $record->requestFingerprint, $status, $record->payload, $captureRevision, $diagnostics, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
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
