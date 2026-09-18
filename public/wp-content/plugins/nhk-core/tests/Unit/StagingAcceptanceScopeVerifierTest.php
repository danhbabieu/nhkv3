<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class StagingAcceptanceScopeVerifierTest extends TestCase
{
    public function test_dynamic_scope_is_derived_from_the_capture_request_without_static_media_ids(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $verifier = $this->verifier();
        $packet = $verifier->issueForCapture($capture, $input, $assets);

        self::assertSame($capture->captureId, $packet['capture_id']);
        self::assertSame($capture->requestFingerprint, $packet['capture_fingerprint']);
        self::assertSame([$input['media_bindings'][0]['media_ref']['media_id']], $packet['media_ids']);
        self::assertSame($input['media_bindings'][0]['target'], $packet['target']);
        self::assertArrayNotHasKey('allowed_media_ids', $packet);
        self::assertTrue($verifier->verifyBindingRequest($packet, $this->bindingRequest($capture, $input, $packet)));
    }

    public function test_dynamic_scope_binds_target_stable_key_and_revision(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $input['media_bindings'][0]['target']['stable_key'] = 'nhk:classification:clock-type.random-a';
        $input['media_bindings'][0]['target']['revision'] = 7;
        $packet = $this->verifier()->issueForCapture($capture, $input, $assets);

        self::assertSame('nhk:classification:clock-type.random-a', $packet['target']['stable_key']);
        self::assertSame(7, $packet['target']['revision']);

        $request = $this->bindingRequest($capture, $input, $packet);
        $request['target']['stable_key'] = 'nhk:classification:clock-type.tampered';
        self::assertFalse($this->verifier()->verifyBindingRequest($packet, $request));
    }

    public function test_dynamic_scope_rejects_changed_payload_fingerprint(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $packet = $this->verifier()->issueForCapture($capture, $input, $assets);
        self::assertArrayHasKey('request_fingerprint', $packet);
        self::assertArrayHasKey('payload_fingerprint', $packet);

        $request = $this->bindingRequest($capture, $input, $packet);
        $request['selection_policy'] = 'AUTO';
        self::assertFalse($this->verifier()->verifyBindingRequest($packet, $request));
    }

    public function test_client_supplied_approved_scope_without_server_signature_is_rejected(): void
    {
        [$capture, $input] = $this->fixture();
        $request = $this->bindingRequest($capture, $input, [
            'approved' => true,
            'environment' => 'staging',
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
        ]);

        self::assertFalse($this->verifier()->verifyBindingRequest($request['staging_acceptance'], $request));
    }

    public function test_scope_issuance_requires_authenticated_internal_capability(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static fn (): bool => true,
            can: static fn (string $capability): bool => false,
        );

        $this->expectExceptionMessage('STAGING_CAPABILITY_REQUIRED:nhk_internal_content_operations');
        $verifier->issueForCapture($capture, $input, $assets);
    }

    public function test_scope_fingerprint_is_immutable_and_wrong_media_target_operation_capture_are_blocked(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $verifier = $this->verifier();
        $packet = $verifier->issueForCapture($capture, $input, $assets);
        $cases = [
            static function (array $request): array { $request['staging_acceptance']['media_ids'][0] = UuidCodec::newV7(); return $request; },
            static function (array $request): array { $request['media']['id'] = UuidCodec::newV7(); return $request; },
            static function (array $request): array { $request['target']['id'] = UuidCodec::newV7(); return $request; },
            static function (array $request): array { $request['operation'] = 'media_ingest'; return $request; },
            static function (array $request): array { $request['capture_id'] = UuidCodec::newV7(); return $request; },
        ];
        foreach ($cases as $mutate) {
            $request = $mutate($this->bindingRequest($capture, $input, $packet));
            self::assertFalse($verifier->verifyBindingRequest($request['staging_acceptance'], $request));
        }
    }

    public function test_production_and_unadmitted_scope_are_blocked(): void
    {
        [$capture, $input, $assets] = $this->fixture();
        $production = new StagingAcceptanceScopeVerifier(static fn (): string => 'production', 'test-secret', static fn (): bool => true);
        $this->expectExceptionMessage('STAGING_PRODUCTION_FORBIDDEN');
        $production->issueForCapture($capture, $input, $assets);
    }

    public function test_authority_plan_scope_is_server_issued_and_binds_exact_candidate(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'authority-scope', hash('sha256', 'authority-scope'), 'AUTHORITY_PLANNED', 'PLANNED', null, null, [], ['purpose' => 'AUTHORITY', 'planning_input' => ['purpose' => 'AUTHORITY', 'authority_intent' => ['mode' => 'PLAN']]], [], []);
        $candidateId = 'brand-jaeger';
        $plan = ['plan_fingerprint' => hash('sha256', 'plan'), 'create_candidates' => [['candidate_id' => $candidateId, 'action' => 'CREATE', 'entity_type' => 'brand']]];
        $scope = (new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true))->issueForAuthorityPlan($capture, $plan, [$candidateId]);
        $proposal = new Proposal(UuidCodec::newV7(), 'brand', 'create', ['candidate_id' => $candidateId, 'project_build_audit' => ['capture_id' => $capture->captureId, 'plan_fingerprint' => $plan['plan_fingerprint']], 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'authority-scope', entityType: 'brand');

        self::assertTrue((new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true))->verifyProposal($scope, $proposal));
        $wrongPayload = $proposal->payload;
        $wrongPayload['candidate_id'] = 'other-candidate';
        $wrongProposal = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $wrongPayload, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, entityType: $proposal->entityType);
        self::assertFalse((new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true))->verifyProposal($scope, $wrongProposal));
    }

    public function test_relation_scope_binds_both_endpoints_and_revisions(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'relation-scope', hash('sha256', 'relation-scope'), 'AUTHORITY_PLANNED', 'PLANNED', null, null, [], ['purpose' => 'AUTHORITY', 'planning_input' => []], [], []);
        $candidateId = 'relation-candidate-exact';
        $source = UuidCodec::newV7();
        $target = UuidCodec::newV7();
        $plan = ['plan_fingerprint' => hash('sha256', 'relation-plan'), 'relation_candidates' => [[
            'candidate_id' => $candidateId,
            'action' => 'CREATE',
            'entity_type' => 'relation',
            'source_type' => 'classification',
            'source_uuid' => $source,
            'source_revision' => 1,
            'predicate' => 'about',
            'target_type' => 'knowledge',
            'target_uuid' => $target,
            'target_revision' => 1,
        ]]];
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true);
        $scope = $verifier->issueForAuthorityPlan($capture, $plan, [$candidateId]);
        self::assertSame($source, $scope['candidate_bindings'][0]['source_uuid']);
        self::assertSame($target, $scope['candidate_bindings'][0]['target_uuid']);
        self::assertSame(1, $scope['candidate_bindings'][0]['source_revision']);
        self::assertSame(1, $scope['candidate_bindings'][0]['target_revision']);
        $proposal = new Proposal(UuidCodec::newV7(), $source, 'relation_create', [
            'candidate_id' => $candidateId,
            'source_type' => 'classification', 'source_uuid' => $source,
            'predicate' => 'about', 'target_type' => 'knowledge', 'target_uuid' => $target,
            'source_revision' => 1, 'target_revision' => 1,
            'project_build_audit' => ['capture_id' => $capture->captureId, 'plan_fingerprint' => $plan['plan_fingerprint']],
            'staging_acceptance' => $scope,
        ], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'relation-scope', entityType: 'relation');
        self::assertTrue($verifier->verifyProposal($scope, $proposal));
    }

    public function test_video_update_scope_binds_capture_target_operation_and_revision(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'video-scope', hash('sha256', 'video-scope'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS');
        $videoId = UuidCodec::newV7();
        $plan = ['entity_type' => 'video', 'operation' => 'update', 'subject_id' => $videoId, 'target_uuid' => $videoId, 'expected_revision' => 5, 'fingerprint' => hash('sha256', 'video-plan')];
        $verifier = $this->verifier();
        $scope = $verifier->issueForVideoPlan($capture, $plan);
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'update', [
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
            'canonical_id' => $videoId,
            'staging_acceptance' => $scope,
        ], 'content', 5, 'dependency', ProposalState::APPROVED, idempotencyKey: 'video-scope', targetUuid: $videoId, entityType: 'video');

        self::assertTrue($verifier->verifyProposal($scope, $proposal));
        $tampered = $proposal->payload;
        $tampered['capture_fingerprint'] = hash('sha256', 'wrong-capture');
        $wrong = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $tampered, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: $proposal->entityType);
        self::assertFalse($verifier->verifyProposal($scope, $wrong));
    }

    public function test_video_ingest_scope_binds_exact_source_subject_and_create_semantics(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $capture = new CaptureRecord($captureId, 'video-ingest', hash('sha256', 'video-ingest'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [[
            'kind' => 'video',
            'video_proposal' => ['payload' => [
                'canonical_id' => $videoId,
                'metadata' => [
                    'source' => [
                        'platform' => 'youtube',
                        'external_video_id' => '2EMuIG2RfTg',
                        'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg',
                    ],
                    'subject_resolution_packet' => ['status' => 'RESOLVED', 'type' => 'classification', 'id' => $subjectId, 'revision' => 1],
                ],
            ]],
        ]], ['purpose' => 'VIDEO'], [], []);
        $plan = [
            'entity_type' => 'video',
            'operation' => 'ingest',
            'subject_id' => $videoId,
            'fingerprint' => hash('sha256', 'video-ingest-plan'),
            'proposal_command_fingerprint' => hash('sha256', 'video-ingest-command'),
        ];

        $scope = $this->verifier()->issueForVideoPlan($capture, $plan);

        self::assertSame('nhk.capture.ingest', $scope['canonical_entrypoint']);
        self::assertSame('ingest', $scope['operation']);
        self::assertSame('ingest', $scope['create_semantics']);
        self::assertSame('youtube', $scope['platform']);
        self::assertSame('2EMuIG2RfTg', $scope['external_video_id']);
        self::assertSame($videoId, $scope['proposed_uuid']);
        self::assertSame($subjectId, $scope['subject']['uuid']);
        self::assertArrayHasKey('proposal_command_fingerprint', $scope);
        self::assertArrayHasKey('signature', $scope);
    }

    /** @return array{0:CaptureRecord,1:array<string,mixed>,2:list<array<string,mixed>>} */
    private function fixture(): array
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'dynamic-scope', hash('sha256', 'dynamic-scope'), 'ASSETS_STORED', 'IN_PROGRESS');
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $input = ['intent' => 'MEDIA_ENRICHMENT', 'media_bindings' => [[
            'media_ref' => ['media_id' => $mediaId],
            'target' => ['type' => 'classification', 'id' => $targetId, 'stable_key' => 'nhk:classification:clock-type'],
            'role' => 'representative',
            'selection_source' => 'USER_EXPLICIT',
            'selection_policy' => 'PINNED',
        ]]];
        return [$capture, $input, [['media_id' => $mediaId, 'attachment_id' => 576, 'attachment_readback_status' => 'verified']]];
    }

    private function verifier(): StagingAcceptanceScopeVerifier
    {
        return new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $packet @return array<string,mixed> */
    private function bindingRequest(CaptureRecord $capture, array $input, array $packet): array
    {
        $binding = $input['media_bindings'][0];
        return ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'payload_fingerprint' => $packet['payload_fingerprint'] ?? '', 'operation' => 'representative_bind', 'media' => ['id' => $binding['media_ref']['media_id']], 'target' => $binding['target'], 'role' => $binding['role'], 'selection_source' => $binding['selection_source'], 'selection_policy' => $binding['selection_policy'], 'staging_acceptance' => $packet];
    }
}
