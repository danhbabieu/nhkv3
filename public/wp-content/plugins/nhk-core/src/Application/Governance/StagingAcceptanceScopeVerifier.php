<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\YouTubeSourceSnapshot;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * The single owner of server-issued, Capture-derived staging acceptance.
 *
 * A client may describe intent and exact references, but it cannot mint an
 * approved packet: the packet is admitted by the server, fingerprinted and
 * signed with the staging secret before any governed writer can use it.
 */
final class StagingAcceptanceScopeVerifier
{
    /** @param callable():string $environment @param callable(array<string,mixed>,CaptureRecord,array<string,mixed>,array<int,array<string,mixed>>):bool|null $admission @param callable(string):bool|null $can */
    public function __construct(
        private $environment,
        private ?string $signingSecret = null,
        private $admission = null,
        private int $ttlSeconds = 900,
        private $can = null,
        private ?VideoRepository $videos = null,
    ) {}

    /** @param list<array<string,mixed>> $assets @return array<string,mixed> */
    public function issueForCapture(CaptureRecord $capture, array $input, array $assets): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $intent = strtoupper(trim((string) ($input['intent'] ?? '')));
        if (!in_array($intent, ['IMAGE_ARTICLE', 'TEXT_ARTICLE', 'MEDIA_ENRICHMENT'], true)) throw new \RuntimeException('STAGING_SCOPE_INTENT_INVALID');

        $bindings = $this->bindingEntries($input, $assets);
        if ($bindings === []) throw new \RuntimeException('STAGING_SCOPE_BINDINGS_REQUIRED');
        $mediaIds = array_values(array_unique(array_map(static fn (array $binding): string => (string) $binding['media_id'], $bindings)));
        $base = [
            'approved' => true,
            'environment' => 'staging',
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
            'operation_family' => 'media_usage_reconciliation',
            'entity_type' => 'media',
            'operation' => 'representative_bind',
            'writer' => 'canonical_media_binding',
            'entrypoint' => 'nhk.capture.ingest',
            'intent' => $intent,
            'media_ids' => $mediaIds,
            'target' => $bindings[0]['target'],
            'bindings' => $bindings,
            'required_capabilities' => ['nhk_internal_content_operations'],
            'request_fingerprint' => $capture->requestFingerprint,
            'payload_fingerprint' => $this->payloadFingerprint($input),
            'issued_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, $input, $assets)) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** Issue one exact scope for an existing Media metadata update. */
    public function issueForMediaMetadataUpdate(CaptureRecord $capture, array $operation, string $mediaId, int $expectedRevision): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        if (!UuidCodec::isValid($mediaId) || $expectedRevision < 1) throw new \RuntimeException('STAGING_MEDIA_METADATA_BINDING_REQUIRED');
        $operation['operation'] = 'update';
        $operation['media'] = ['id' => $mediaId];
        $operation['capture_id'] = $capture->captureId;
        $operation['capture_fingerprint'] = $capture->requestFingerprint;
        $payloadFingerprint = hash('sha256', CommandCanonicalizer::canonicalize($operation));
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'request_fingerprint' => $capture->requestFingerprint,
            'operation_family' => 'media_metadata_reconciliation', 'entity_type' => 'media', 'operation' => 'update',
            'writer' => 'canonical_governed', 'entrypoint' => 'nhk.capture.ingest', 'target_uuid' => $mediaId,
            'expected_revision' => $expectedRevision, 'payload_fingerprint' => $payloadFingerprint,
            'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, ['intent' => 'MEDIA_ENRICHMENT', 'media_operations' => [$operation]], [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @param array{video_id:string,expected_revision:int,expected_source_revision:?int,idempotency_key:string} $request @return array<string,mixed> */
    public function issueForVideoSourceRefresh(CaptureRecord $capture, array $request): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $videoId = trim((string) ($request['video_id'] ?? ''));
        $expectedRevision = (int) ($request['expected_revision'] ?? 0);
        $expectedSourceRevision = !array_key_exists('expected_source_revision', $request) || $request['expected_source_revision'] === null ? null : (int) $request['expected_source_revision'];
        $idempotencyKey = trim((string) ($request['idempotency_key'] ?? ''));
        if (!UuidCodec::isValid($videoId) || $expectedRevision < 1 || $idempotencyKey === '') throw new \RuntimeException('STAGING_VIDEO_SOURCE_REFRESH_BINDING_REQUIRED');
        $requestFingerprint = hash('sha256', CommandCanonicalizer::canonicalize(['video_id' => $videoId, 'expected_revision' => $expectedRevision, 'expected_source_revision' => $expectedSourceRevision, 'idempotency_key' => $idempotencyKey]));
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'request_fingerprint' => $requestFingerprint,
            'operation_family' => 'video_source_refresh', 'entity_type' => 'video', 'operation' => 'source_refresh',
            'writer' => 'canonical_governed', 'entrypoint' => 'nhk.video.source.refresh', 'target_uuid' => $videoId,
            'expected_revision' => $expectedRevision, 'expected_source_revision' => $expectedSourceRevision,
            'idempotency_key' => $idempotencyKey, 'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, $request, [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @param array<string,mixed> $scope */
    public function verifyVideoSourceRefresh(array $scope, string $videoId, int $expectedRevision, ?int $expectedSourceRevision, string $idempotencyKey): bool
    {
        if (!$this->verifyPacket($scope) || ($scope['writer'] ?? '') !== 'canonical_governed') return false;
        $requestFingerprint = hash('sha256', CommandCanonicalizer::canonicalize(['video_id' => $videoId, 'expected_revision' => $expectedRevision, 'expected_source_revision' => $expectedSourceRevision, 'idempotency_key' => $idempotencyKey]));
        return ($scope['operation_family'] ?? '') === 'video_source_refresh'
            && ($scope['entity_type'] ?? '') === 'video' && ($scope['operation'] ?? '') === 'source_refresh'
            && ($scope['target_uuid'] ?? '') === $videoId && (int) ($scope['expected_revision'] ?? -1) === $expectedRevision
            && ($scope['expected_source_revision'] ?? null) === $expectedSourceRevision
            && hash_equals((string) ($scope['idempotency_key'] ?? ''), $idempotencyKey)
            && hash_equals((string) ($scope['request_fingerprint'] ?? ''), $requestFingerprint);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function issueForCaptureDependencyPlan(CaptureRecord $capture, array $plan): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $descriptor = StagingOperationDescriptor::fromPlan($plan, $capture->captureId, $capture->requestFingerprint);
        if (!in_array($descriptor->entityType, ['source', 'knowledge', 'evidence'], true) || !in_array($descriptor->operation, ['ingest', 'create'], true)) throw new \RuntimeException('STAGING_DEPENDENCY_OPERATION_INVALID');
        $family = $descriptor->operationFamily;
        $payloadFingerprint = $descriptor->payloadFingerprint;
        $planFingerprint = hash('sha256', CommandCanonicalizer::canonicalize(StagingOperationDescriptor::withoutAuthorization($plan)));
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'request_fingerprint' => $capture->requestFingerprint,
            'semantic_write_policy' => 'PROJECT_BUILD', 'operation_family' => $family,
            'entity_type' => $descriptor->entityType, 'operation' => $descriptor->operation, 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'canonical_entrypoint' => 'nhk.capture.ingest',
            'subject_id' => $descriptor->subjectId, 'expected_revision' => $descriptor->expectedRevision ?? 0,
            'plan_fingerprint' => $planFingerprint, 'proposal_command_fingerprint' => $payloadFingerprint,
            'payload_fingerprint' => $payloadFingerprint,
            'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, (array) ($capture->context['planning_input'] ?? []), [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** Issue a non-transferable scope for the required Article subject edge. */
    public function issueForCaptureChildRelation(CaptureRecord $capture, array $plan): array
    {
        if ($this->environmentName() !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $resolution = is_array($capture->diagnostics['subject_resolution'] ?? null) ? $capture->diagnostics['subject_resolution'] : [];
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $articleId = (int) ($capture->articleId ?? 0);
        $sourceId = (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1) . ':' . $articleId;
        $sourceRevision = max(1, (int) ($payload['source_revision'] ?? 1));
        $targetRevision = max(1, (int) ($payload['target_revision'] ?? ($primary['revision'] ?? 0)));
        if ($articleId < 1 || ($payload['source_type'] ?? '') !== 'wp_post' || ($payload['source_uuid'] ?? '') !== $sourceId
            || ($payload['predicate'] ?? '') !== 'about' || ($payload['target_type'] ?? '') !== (string) ($primary['type'] ?? '')
            || ($payload['target_uuid'] ?? '') !== (string) ($primary['id'] ?? '') || $targetRevision < 1) {
            throw new \RuntimeException('STAGING_CAPTURE_CHILD_BINDING_INVALID');
        }
        $payload['capture_id'] = $capture->captureId;
        $payload['capture_revision'] = $capture->revision;
        $payload['source_revision'] = $sourceRevision;
        $payload['target_revision'] = $targetRevision;
        $payload = StagingOperationDescriptor::withoutAuthorization($payload);
        $payloadFingerprint = hash('sha256', CommandCanonicalizer::canonicalize($payload));
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'capture_revision' => $capture->revision,
            'purpose' => (string) ($capture->context['purpose'] ?? 'EDITORIAL'),
            'intent' => (string) ($capture->context['content_intent']['intent'] ?? 'IMAGE_ARTICLE'),
            'operation_family' => 'capture_child_relation', 'entity_type' => 'relation', 'operation' => 'relation_create',
            'writer' => 'canonical_governed', 'entrypoint' => 'nhk.capture.ingest',
            'owner_type' => 'article', 'owner_id' => (string) $articleId,
            'source_type' => (string) $payload['source_type'], 'source_id' => $sourceId,
            'predicate' => 'about', 'target_type' => (string) $payload['target_type'], 'target_id' => (string) $payload['target_uuid'],
            'source_revision' => $sourceRevision, 'target_revision' => $targetRevision,
            'expected_revision' => null, 'payload_fingerprint' => $payloadFingerprint,
            'proposal_command_fingerprint' => $payloadFingerprint,
            'idempotency_key' => (string) ($plan['idempotency_key'] ?? ''),
            'proposal_payload' => $payload,
            'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, (array) ($capture->context['planning_input'] ?? []), [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @param array<string,mixed> $plan @param list<string> $candidateIds @return array<string,mixed> */
    public function issueForAuthorityPlan(CaptureRecord $capture, array $plan, array $candidateIds): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $planFingerprint = trim((string) ($plan['plan_fingerprint'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/i', $planFingerprint)) throw new \RuntimeException('STAGING_PLAN_FINGERPRINT_REQUIRED');
        $existing = is_array($capture->context['staging_acceptance'] ?? null) ? $capture->context['staging_acceptance'] : null;
        if ($existing !== null && ($existing['operation_family'] ?? '') === 'governed_authority_plan' && ($existing['plan_fingerprint'] ?? '') === $planFingerprint && $this->verifyPacket($existing)) {
            $known = array_map(static fn (array $binding): string => (string) ($binding['candidate_id'] ?? ''), array_values(array_filter((array) ($existing['candidate_bindings'] ?? []), 'is_array')));
            if (array_diff(array_map('strval', $candidateIds), $known) === []) return $existing;
        }
        $all = [];
        foreach (['reuse', 'create_candidates', 'update_candidates', 'relation_candidates', 'relation_reuse'] as $bucket) foreach ((array) ($plan[$bucket] ?? []) as $candidate) {
            if (!is_array($candidate) || !in_array((string) ($candidate['candidate_id'] ?? ''), $candidateIds, true) || strtoupper((string) ($candidate['action'] ?? 'CREATE')) === 'REUSE') continue;
            $isRelation = isset($candidate['predicate']) || isset($candidate['source_type']);
            $operation = $isRelation ? 'relation_create' : (strtolower((string) ($candidate['action'] ?? 'create')) === 'create' ? 'create' : strtolower((string) ($candidate['action'] ?? 'update')));
            $entityType = $isRelation ? 'relation' : (string) ($candidate['entity_type'] ?? '');
            $subjectId = $isRelation ? (string) ($candidate['source_uuid'] ?? $candidate['source_id'] ?? '') : ($operation === 'create' ? $entityType : (string) ($candidate['canonical_uuid'] ?? $candidate['target_uuid'] ?? ''));
            $targetUuid = !$isRelation && !in_array($operation, ['create', 'ingest'], true) ? trim((string) ($candidate['canonical_uuid'] ?? $candidate['target_uuid'] ?? '')) : '';
            $dependencies = array_values(array_unique(array_filter(array_map('strval', (array) ($candidate['dependencies'] ?? [])))));
            $binding = $isRelation
                ? [
                    'candidate_id' => (string) $candidate['candidate_id'],
                    'entity_type' => 'relation',
                    'operation' => 'relation_create',
                    'subject_id' => $subjectId,
                    'source_type' => (string) ($candidate['source_type'] ?? ''),
                    'source_uuid' => $subjectId,
                    'source_revision' => max(1, (int) ($candidate['source_revision'] ?? 0)),
                    'predicate' => (string) ($candidate['predicate'] ?? ''),
                    'target_type' => (string) ($candidate['target_type'] ?? ''),
                    'target_uuid' => (string) ($candidate['target_uuid'] ?? ''),
                    'target_revision' => max(1, (int) ($candidate['target_revision'] ?? 0)),
                    'expected_revision' => null,
                ]
                : ['candidate_id' => (string) $candidate['candidate_id'], 'entity_type' => $entityType, 'operation' => $operation, 'subject_id' => $subjectId, 'target_uuid' => $targetUuid, 'expected_revision' => !in_array($operation, ['create', 'ingest'], true) ? max(1, (int) ($candidate['expected_revision'] ?? $candidate['canonical_revision'] ?? 1)) : null];
            $binding['dependencies'] = $dependencies;
            $binding['dependency_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize($dependencies));
            $binding['candidate_payload_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize($candidate));
            $binding['binding_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize($binding));
            $all[] = $binding;
        }
        if ($all === []) throw new \RuntimeException('STAGING_CANDIDATE_SCOPE_REQUIRED');
        $selectedIds = array_values(array_map('strval', $candidateIds));
        sort($selectedIds, SORT_STRING);
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'request_fingerprint' => $capture->requestFingerprint,
            'operation_family' => 'governed_authority_plan', 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'intent' => (string) ($capture->context['purpose'] ?? 'AUTHORITY'),
            'plan_fingerprint' => $planFingerprint, 'approved_candidate_ids' => $selectedIds,
            'candidate_bindings' => $all,
            'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize(array_map(static fn (array $binding): array => [$binding['candidate_id'] ?? '', $binding['dependencies'] ?? []], $all))),
            'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        $input = is_array($capture->context['planning_input'] ?? null) ? $capture->context['planning_input'] : [];
        if (!(bool) ($this->admission)($base, $capture, $input, [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function issueForVideoPlan(CaptureRecord $capture, array $plan): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        $this->requireCapability();
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        $descriptor = StagingOperationDescriptor::fromPlan($plan, $capture->captureId, $capture->requestFingerprint);
        $operation = $descriptor->operation;
        $entityType = $descriptor->entityType;
        // Video owner identity and resolved semantic subject are separate
        // bindings. `subject_id` is the Proposal owner UUID; it must never be
        // reused as the Authority/semantic subject locator.
        $targetUuid = trim((string) ($plan['target_uuid'] ?? ''));
        $expectedRevision = $descriptor->expectedRevision ?? 0;
        $planFingerprint = trim((string) ($plan['plan_fingerprint'] ?? $plan['fingerprint'] ?? ''));
        if ($entityType !== 'video' || !in_array($operation, ['ingest', 'update'], true)) throw new \RuntimeException('STAGING_VIDEO_OPERATION_INVALID');
        if (!preg_match('/^[a-f0-9]{64}$/i', $planFingerprint)) throw new \RuntimeException('STAGING_VIDEO_BINDING_REQUIRED');
        $video = $this->videoPayload($capture);
        $metadata = is_array($video['metadata'] ?? null) ? $video['metadata'] : [];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $platform = strtolower(trim((string) ($plan['platform'] ?? $source['platform'] ?? '')));
        $externalId = trim((string) ($plan['external_video_id'] ?? $source['external_video_id'] ?? ''));
        $sourceUrl = trim((string) ($plan['canonical_source_url'] ?? $source['canonical_source_url'] ?? ''));
        if ($platform === 'youtube') {
            try { $sourceSnapshot = YouTubeSourceSnapshot::fromArray(['platform' => $platform, 'external_video_id' => $externalId, 'canonical_source_url' => $sourceUrl]); } catch (\Throwable) { throw new \RuntimeException('STAGING_VIDEO_SOURCE_INVALID'); }
            $platform = $sourceSnapshot->platform;
            $externalId = $sourceSnapshot->externalVideoId;
            $sourceUrl = $sourceSnapshot->canonicalSourceUrl;
        }
        $subjectPacket = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        $subjectId = trim((string) ($subjectPacket['id'] ?? ''));
        $subjectType = strtolower(trim((string) ($subjectPacket['type'] ?? '')));
        $proposedUuid = trim((string) ($plan['proposed_uuid'] ?? $video['canonical_id'] ?? $targetUuid));
        if (!UuidCodec::isValid($proposedUuid) || !UuidCodec::isValid($subjectId) || $subjectType === '') throw new \RuntimeException('STAGING_VIDEO_BINDING_REQUIRED');
        if ($operation === 'update') {
            if (!UuidCodec::isValid($targetUuid) || $expectedRevision < 1) throw new \RuntimeException('STAGING_VIDEO_BINDING_REQUIRED');
            if ($this->videos !== null) {
                $canonical = $this->videos->findByCanonicalId($targetUuid);
                if ($canonical === null || $canonical->revision !== $expectedRevision) throw new \RuntimeException('TARGET_REVISION_CHANGED');
            }
        } else {
            if ($expectedRevision !== 0) throw new \RuntimeException('STAGING_VIDEO_BINDING_REQUIRED');
            if ($this->videos === null) throw new \RuntimeException('STAGING_VIDEO_DUPLICATE_AUDIT_REQUIRED');
            if ($this->videos->findByExternalReference($platform, $externalId) !== null) throw new \RuntimeException('STAGING_VIDEO_DUPLICATE');
        }
        // The command fingerprint must cover the final Video payload (after
        // canonical Evidence read-back and semantic_attachments rebuild), not
        // the surrounding orchestration plan. This is the value the Proposal
        // verifier can recompute immediately before Controlled Apply.
        $proposalCommandFingerprint = $descriptor->payloadFingerprint;
        $base = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'request_fingerprint' => $capture->requestFingerprint,
            'semantic_write_policy' => 'PROJECT_BUILD', 'operation_family' => 'governed_video_plan',
            'entity_type' => 'video', 'operation' => $operation, 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'canonical_entrypoint' => 'nhk.capture.ingest',
            'target_uuid' => $operation === 'update' ? $targetUuid : null, 'proposed_uuid' => $proposedUuid,
            'create_semantics' => $descriptor->createSemantics, 'expected_revision' => $expectedRevision,
            'platform' => $platform, 'external_video_id' => $externalId, 'canonical_source_url' => $sourceUrl,
            'subject' => ['type' => $subjectType, 'uuid' => $subjectId, 'revision' => max(0, (int) ($subjectPacket['revision'] ?? $plan['subject_revision'] ?? 0))],
            'plan_fingerprint' => $planFingerprint, 'proposal_command_fingerprint' => $proposalCommandFingerprint,
            'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        $input = is_array($capture->context['planning_input'] ?? null) ? $capture->context['planning_input'] : [];
        if (!(bool) ($this->admission)($base, $capture, $input, [])) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @return array<string,mixed> */
    private function videoPayload(CaptureRecord $capture): array
    {
        $assets = array_values(array_filter($capture->assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video'));
        if (count($assets) !== 1 || !is_array($assets[0]['video_proposal'] ?? null)) throw new \RuntimeException('STAGING_VIDEO_PLAN_REQUIRED');
        $payload = $assets[0]['video_proposal']['payload'] ?? [];
        if (!is_array($payload)) throw new \RuntimeException('STAGING_VIDEO_PLAN_REQUIRED');
        return $payload;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function withoutAuthorization(array $value): array
    {
        foreach (['staging_acceptance', 'signature', 'fingerprint', 'approved', 'scope_fingerprint', 'proposal_command_fingerprint'] as $key) unset($value[$key]);
        return $value;
    }

    /** @param list<array<string,mixed>> $assets @return array<string,mixed>|null */
    public function forCapture(CaptureRecord $capture, array $input, array $assets): ?array
    {
        if ($this->environmentName() !== 'staging') return null;
        $existing = is_array($capture->context['staging_acceptance'] ?? null) ? $capture->context['staging_acceptance'] : null;
        if ($existing !== null) {
            if (strtoupper(trim((string) ($existing['intent'] ?? ''))) !== strtoupper(trim((string) ($input['intent'] ?? '')))) {
                throw new \RuntimeException('STAGING_SCOPE_REPLAY_MISMATCH');
            }
            $reissue = false;
            foreach ((array) ($input['media_bindings'] ?? []) as $index => $binding) {
                $request = $this->bindingRequest($capture, $binding, $assets, (int) $index, $existing);
                if ($this->verifyBindingRequest($existing, $request)) continue;
                if (!$this->bindingRequestCompatibleWithPersistedScope($existing, $request, (int) $index)) throw new \RuntimeException('STAGING_SCOPE_REPLAY_MISMATCH');
                $reissue = true;
            }
            // The Capture request and exact binding identity are immutable,
            // but the signed scope is ephemeral. Reissue only after the
            // current canonical admission checks the same request again.
            return $reissue ? $this->issueForCapture($capture, $input, $assets) : $existing;
        }
        return $this->issueForCapture($capture, $input, $assets);
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $request */
    private function bindingRequestCompatibleWithPersistedScope(array $scope, array $request, int $index): bool
    {
        if (($scope['capture_id'] ?? '') !== ($request['capture_id'] ?? '')
            || ($scope['capture_fingerprint'] ?? '') !== ($request['capture_fingerprint'] ?? '')
            || ($request['operation'] ?? '') !== 'representative_bind') return false;
        $binding = is_array($scope['bindings'][$index] ?? null) ? $scope['bindings'][$index] : null;
        if ($binding === null) return false;
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        $persistedTarget = is_array($binding['target'] ?? null) ? $binding['target'] : [];
        foreach (['type', 'id', 'stable_key', 'revision'] as $key) {
            if (array_key_exists($key, $persistedTarget) && (string) ($target[$key] ?? '') !== (string) $persistedTarget[$key]) return false;
        }
        return (string) ($request['media']['id'] ?? '') === (string) ($binding['media_id'] ?? '')
            && strtoupper((string) ($request['role'] ?? '')) === strtoupper((string) ($binding['role'] ?? ''))
            && strtoupper((string) ($request['selection_source'] ?? '')) === strtoupper((string) ($binding['selection_source'] ?? ''))
            && strtoupper((string) ($request['selection_policy'] ?? '')) === strtoupper((string) ($binding['selection_policy'] ?? ''));
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $request */
    public function verifyBindingRequest(array $scope, array $request): bool
    {
        if (!$this->verifyPacket($scope) || ($scope['writer'] ?? '') !== 'canonical_media_binding') return false;
        if (!hash_equals((string) $scope['capture_id'], trim((string) ($request['capture_id'] ?? '')))) return false;
        if (!hash_equals((string) ($scope['capture_fingerprint'] ?? ''), trim((string) ($request['capture_fingerprint'] ?? $scope['capture_fingerprint'] ?? '')))) return false;
        if (!isset($scope['payload_fingerprint'], $request['payload_fingerprint']) || !hash_equals((string) $scope['payload_fingerprint'], trim((string) $request['payload_fingerprint']))) return false;
        if (!in_array((string) ($request['operation'] ?? 'representative_bind'), ['representative_bind'], true)) return false;
        $mediaId = trim((string) ($request['media']['id'] ?? ''));
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        foreach ((array) ($scope['bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            if ($mediaId === (string) ($binding['media_id'] ?? '')
                && $this->sameTarget($target, (array) ($binding['target'] ?? []))
                && strtoupper((string) ($request['role'] ?? '')) === strtoupper((string) ($binding['role'] ?? ''))
                && strtoupper((string) ($request['selection_source'] ?? '')) === (string) ($binding['selection_source'] ?? '')
                && strtoupper((string) ($request['selection_policy'] ?? '')) === (string) ($binding['selection_policy'] ?? '')
                && $this->bindingFingerprintMatches($request, $binding)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $scope */
    public function verifyProposal(array $scope, Proposal $proposal): bool
    {
        if (!$this->verifyPacket($scope) || ($scope['writer'] ?? '') !== 'canonical_governed') return false;
        try { StagingAcceptanceScope::assertProposal($proposal, $scope); return true; } catch (\Throwable) { return false; }
    }

    /** @param array<string,mixed> $scope */
    private function verifyPacket(array $scope): bool
    {
        if ($this->environmentName() !== 'staging' || $this->secret() === '' || ($scope['approved'] ?? false) !== true) return false;
        if (($scope['environment'] ?? '') !== 'staging' || !UuidCodec::isValid((string) ($scope['capture_id'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['capture_fingerprint'] ?? ''))) return false;
        $expires = strtotime((string) ($scope['expires_at'] ?? ''));
        if ($expires === false || $expires < time()) return false;
        $fingerprint = (string) ($scope['fingerprint'] ?? '');
        $signature = (string) ($scope['signature'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $fingerprint) || !preg_match('/^[a-f0-9]{64}$/i', $signature)) return false;
        $unsigned = $scope;
        unset($unsigned['fingerprint'], $unsigned['signature']);
        $calculated = hash('sha256', CommandCanonicalizer::canonicalize($unsigned));
        return hash_equals($fingerprint, $calculated) && hash_equals($signature, hash_hmac('sha256', $fingerprint, $this->secret()));
    }

    /** @param array<string,mixed> $binding @param list<array<string,mixed>> $assets @return array<string,mixed> */
    private function bindingRequest(CaptureRecord $capture, array $binding, array $assets, int $index, array $scope): array
    {
        $mediaId = $this->mediaId($binding, $assets);
        $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
        $scoped = is_array($scope['bindings'][$index] ?? null) ? $scope['bindings'][$index] : [];
        return ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'payload_fingerprint' => (string) ($scope['payload_fingerprint'] ?? ''), 'operation' => 'representative_bind', 'media' => ['id' => $mediaId], 'target' => $target, 'role' => (string) ($binding['role'] ?? 'representative'), 'selection_source' => (string) ($binding['selection_source'] ?? 'USER_EXPLICIT'), 'selection_policy' => (string) ($binding['selection_policy'] ?? 'PINNED'), 'scope_binding' => $scoped];
    }

    /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
    private function bindingEntries(array $input, array $assets): array
    {
        $entries = [];
        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) throw new \RuntimeException('STAGING_SCOPE_BINDING_INVALID');
            $source = strtoupper(trim((string) ($binding['selection_source'] ?? 'USER_EXPLICIT')));
            $policy = strtoupper(trim((string) ($binding['selection_policy'] ?? ($source === 'SYSTEM_AUTO' ? 'AUTO' : 'PINNED'))));
            if ($source === 'SYSTEM_AUTO' || $policy === 'AUTO') throw new \RuntimeException('MEDIA_BINDING_GOVERNANCE_REQUIRED');
            if ($source !== 'USER_EXPLICIT' || $policy !== 'PINNED') throw new \RuntimeException('STAGING_SCOPE_SELECTION_INVALID');
            $mediaId = $this->mediaId($binding, $assets);
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            if (!UuidCodec::isValid($mediaId) || !UuidCodec::isValid((string) ($target['id'] ?? '')) || trim((string) ($target['type'] ?? '')) === '') throw new \RuntimeException('STAGING_SCOPE_EXACT_REFERENCE_REQUIRED');
            $entry = ['media_id' => $mediaId, 'target' => ['type' => strtolower(trim((string) $target['type'])), 'id' => (string) $target['id']], 'role' => 'representative', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED'];
            foreach (['stable_key', 'revision'] as $key) if (array_key_exists($key, $target)) $entry['target'][$key] = $key === 'revision' ? max(1, (int) $target[$key]) : trim((string) $target[$key]);
            $entry['binding_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize($entry));
            $entries[] = $entry;
        }
        return $entries;
    }

    /** @param array<string,mixed> $binding @param list<array<string,mixed>> $assets */
    private function mediaId(array $binding, array $assets): string
    {
        $reference = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
        if (trim((string) ($reference['media_id'] ?? '')) !== '') return trim((string) $reference['media_id']);
        $asset = $assets[(int) ($reference['item_index'] ?? -1)] ?? null;
        return is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '';
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameTarget(array $left, array $right): bool
    {
        foreach (['stable_key', 'revision'] as $key) {
            if (array_key_exists($key, $right) && (string) ($left[$key] ?? '') !== (string) $right[$key]) return false;
        }
        return strtolower(trim((string) ($left['type'] ?? ''))) === strtolower(trim((string) ($right['type'] ?? '')))
            && trim((string) ($left['id'] ?? '')) === trim((string) ($right['id'] ?? ''))
            && array_intersect(['name', 'filename', 'url', 'match', 'similarity', 'fuzzy', 'locator'], array_keys($left)) === [];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $binding */
    private function bindingFingerprintMatches(array $request, array $binding): bool
    {
        $expected = (string) ($binding['binding_fingerprint'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $expected)) return false;
        $actual = [
            'media_id' => trim((string) ($request['media']['id'] ?? '')),
            'target' => is_array($request['target'] ?? null) ? $request['target'] : [],
            'role' => strtolower(trim((string) ($request['role'] ?? ''))),
            'selection_source' => strtoupper(trim((string) ($request['selection_source'] ?? ''))),
            'selection_policy' => strtoupper(trim((string) ($request['selection_policy'] ?? ''))),
        ];
        return hash_equals($expected, hash('sha256', CommandCanonicalizer::canonicalize($actual)))
            || hash_equals($expected, hash('sha256', CommandCanonicalizer::canonicalize([
                'media_id' => $actual['media_id'], 'target' => $actual['target'], 'role' => 'representative',
                'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED',
            ])));
    }

    /** @param array<string,mixed> $input */
    private function payloadFingerprint(array $input): string
    {
        unset($input['staging_acceptance']);
        return hash('sha256', CommandCanonicalizer::canonicalize($input));
    }

    private function environmentName(): string { return strtolower(trim((string) ($this->environment)())); }

    private function secret(): string { return trim((string) ($this->signingSecret ?? '')); }

    private function requireCapability(): void
    {
        if (is_callable($this->can) && !(bool) ($this->can)('nhk_internal_content_operations')) throw new \RuntimeException('STAGING_CAPABILITY_REQUIRED:nhk_internal_content_operations');
    }
}
