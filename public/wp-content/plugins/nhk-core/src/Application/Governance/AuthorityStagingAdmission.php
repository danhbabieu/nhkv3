<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/**
 * Repository-owned admission for the explicitly approved Atherton Authority
 * plan. The shared verifier remains responsible for expiry and HMAC signing.
 */
final class AuthorityStagingAdmission
{
    private const CAPTURE_ID = '01a0b162-9cd5-7989-aa08-cec3322bd45f';
    private const REQUEST_FINGERPRINT = '06ede91a4097f27c1001f07be919f0f1c01f69a34e4d5f921ac6aa37c19ac142';
    private const PLAN_FINGERPRINT = '6b69927f676867d2023df620149f1c93331ae81b89d622d6bfa20d28fafcb736';
    private const BRAND_UUID = '01a090fd-9a71-7665-af5f-08f6e25b533e';

    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'governed_authority_plan'
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || strtoupper((string) ($scope['intent'] ?? '')) !== 'AUTHORITY'
            || ($scope['capture_id'] ?? '') !== self::CAPTURE_ID
            || ($scope['capture_fingerprint'] ?? '') !== self::REQUEST_FINGERPRINT
            || ($scope['plan_fingerprint'] ?? '') !== self::PLAN_FINGERPRINT
            || $capture->captureId !== self::CAPTURE_ID
            || $capture->requestFingerprint !== self::REQUEST_FINGERPRINT) return false;

        $bindings = array_values(array_filter((array) ($scope['candidate_bindings'] ?? []), 'is_array'));
        usort($bindings, static fn (array $left, array $right): int => strcmp((string) ($left['candidate_id'] ?? ''), (string) ($right['candidate_id'] ?? '')));
        $expected = [
            [
                'candidate_id' => 'candidate-43e3d1452693c18a7119',
                'entity_type' => 'relation',
                'operation' => 'relation_create',
                'subject_id' => '',
                'source_type' => 'model',
                'source_uuid' => '',
                'source_revision' => 1,
                'predicate' => 'model_of',
                'target_type' => 'brand',
                'target_uuid' => self::BRAND_UUID,
                'target_revision' => 2,
                'expected_revision' => null,
            ],
            [
                'candidate_id' => 'candidate-831c785e8e84398ce3c7',
                'entity_type' => 'model',
                'operation' => 'create',
                'subject_id' => 'model',
                'target_uuid' => '',
                'expected_revision' => null,
            ],
        ];
        if ($bindings !== $expected) return false;

        $requests = array_values(array_filter((array) ($input['authority_intent']['requests'] ?? []), 'is_array'));
        if (count($requests) !== 1) return false;
        $request = $requests[0];
        return ($request['entity_type'] ?? '') === 'model'
            && strtolower((string) ($request['operation'] ?? 'create')) === 'create'
            && ($request['name'] ?? '') === 'Atherton'
            && (($request['payload']['brand_uuid'] ?? '') === self::BRAND_UUID);
    }
}
