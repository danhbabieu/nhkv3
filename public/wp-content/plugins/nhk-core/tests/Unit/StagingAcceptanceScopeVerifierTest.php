<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Domain\Capture\CaptureRecord;
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

    /** @return array{0:CaptureRecord,1:array<string,mixed>,2:list<array<string,mixed>>} */
    private function fixture(): array
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'dynamic-scope', hash('sha256', 'dynamic-scope'), 'ASSETS_STORED', 'IN_PROGRESS');
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $input = ['intent' => 'MEDIA_ENRICHMENT', 'media_bindings' => [[
            'media_ref' => ['media_id' => $mediaId],
            'target' => ['type' => 'classification', 'id' => $targetId],
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
        return ['capture_id' => $capture->captureId, 'operation' => 'representative_bind', 'media' => ['id' => $binding['media_ref']['media_id']], 'target' => $binding['target'], 'role' => $binding['role'], 'selection_source' => $binding['selection_source'], 'selection_policy' => $binding['selection_policy'], 'staging_acceptance' => $packet];
    }
}
