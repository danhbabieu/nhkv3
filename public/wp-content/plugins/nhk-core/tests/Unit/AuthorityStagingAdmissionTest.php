<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\AuthorityStagingAdmission;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use PHPUnit\Framework\TestCase;

final class AuthorityStagingAdmissionTest extends TestCase
{
    public function test_exact_atherton_scope_is_admitted(): void
    {
        [$scope, $capture, $input] = $this->fixture();
        self::assertTrue((new AuthorityStagingAdmission())(false, $scope, $capture, $input, []));
    }

    public function test_fresh_current_plan_fingerprint_is_admitted_for_the_same_exact_intent(): void
    {
        [$scope, $capture, $input] = $this->fixture();
        $scope['plan_fingerprint'] = str_repeat('c', 64);

        self::assertTrue((new AuthorityStagingAdmission())(false, $scope, $capture, $input, []));
    }

    public function test_admission_is_not_an_atherton_or_capture_allowlist(): void
    {
        [$scope, $capture, $input] = $this->fixture();
        $capture = new CaptureRecord(
            '01a0b259-27d6-7466-8646-5afa50d1bf20',
            'atmos',
            str_repeat('b', 64),
            'AUTHORITY_PLANNED',
            'IN_PROGRESS',
            context: ['purpose' => 'AUTHORITY']
        );
        $scope['capture_id'] = $capture->captureId;
        $scope['capture_fingerprint'] = $capture->requestFingerprint;
        $scope['request_fingerprint'] = $capture->requestFingerprint;
        $scope['candidate_bindings'][0]['candidate_id'] = 'candidate-atmos-relation';
        $scope['candidate_bindings'][1]['candidate_id'] = 'candidate-atmos-model';
        $scope['approved_candidate_ids'] = ['candidate-atmos-relation', 'candidate-atmos-model'];
        foreach ($scope['candidate_bindings'] as &$binding) $binding['binding_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize(array_diff_key($binding, ['binding_fingerprint' => true])));
        unset($binding);
        $scope['dependency_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize(array_map(static fn (array $binding): array => [$binding['candidate_id'], $binding['dependencies']], $scope['candidate_bindings'])));
        $input['authority_intent']['requests'][0]['name'] = 'Atmos';

        self::assertTrue((new AuthorityStagingAdmission())(false, $scope, $capture, $input, []));
    }

    /** @dataProvider tamperProvider */
    public function test_scope_fails_closed_for_any_non_exact_value(string $field): void
    {
        [$scope, $capture, $input] = $this->fixture();
        if ($field === 'capture_id') $scope['capture_id'] = '01a0b162-9cd5-7989-aa08-cec3322bd450';
        if ($field === 'request_fingerprint') $scope['capture_fingerprint'] = str_repeat('a', 64);
        if ($field === 'plan_fingerprint') $scope['plan_fingerprint'] = str_repeat('z', 64);
        if ($field === 'candidate') $scope['candidate_bindings'][0]['candidate_id'] = $scope['candidate_bindings'][1]['candidate_id'];
        if ($field === 'target') $scope['candidate_bindings'][0]['target_uuid'] = '01a090fd-9a71-7665-af5f-08f6e25b533f';
        if ($field === 'operation') $scope['candidate_bindings'][0]['operation'] = 'not_registered';
        if ($field === 'production') $scope['environment'] = 'production';
        if ($field === 'wildcard') $scope['candidate_bindings'][0]['candidate_id'] = '*';

        self::assertFalse((new AuthorityStagingAdmission())(false, $scope, $capture, $input, []));
    }

    public static function tamperProvider(): array
    {
        return array_map(static fn (string $name): array => [$name], ['capture_id', 'request_fingerprint', 'plan_fingerprint', 'candidate', 'target', 'operation', 'production', 'wildcard']);
    }

    /** @return array{0:array<string,mixed>,1:CaptureRecord,2:array<string,mixed>} */
    private function fixture(): array
    {
        $capture = new CaptureRecord('01a0b162-9cd5-7989-aa08-cec3322bd45f', 'atherton', '06ede91a4097f27c1001f07be919f0f1c01f69a34e4d5f921ac6aa37c19ac142', 'AUTHORITY_PLANNED', 'IN_PROGRESS', context: ['purpose' => 'AUTHORITY']);
        $scope = [
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'operation_family' => 'governed_authority_plan',
            'request_fingerprint' => $capture->requestFingerprint,
            'writer' => 'canonical_governed', 'entrypoint' => 'nhk.capture.ingest', 'intent' => 'AUTHORITY',
            'plan_fingerprint' => '6b69927f676867d2023df620149f1c93331ae81b89d622d6bfa20d28fafcb736',
            'candidate_bindings' => [
                ['candidate_id' => 'candidate-831c785e8e84398ce3c7', 'entity_type' => 'model', 'operation' => 'create', 'subject_id' => 'model', 'target_uuid' => '', 'expected_revision' => null, 'dependencies' => [], 'candidate_payload_fingerprint' => str_repeat('a', 64), 'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize([]))],
                ['candidate_id' => 'candidate-43e3d1452693c18a7119', 'entity_type' => 'relation', 'operation' => 'relation_create', 'subject_id' => '', 'source_type' => 'model', 'source_uuid' => '', 'source_revision' => 1, 'predicate' => 'model_of', 'target_type' => 'brand', 'target_uuid' => '01a090fd-9a71-7665-af5f-08f6e25b533e', 'target_revision' => 2, 'expected_revision' => null, 'dependencies' => [], 'candidate_payload_fingerprint' => str_repeat('b', 64), 'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize([]))],
            ],
            'approved_candidate_ids' => ['candidate-831c785e8e84398ce3c7', 'candidate-43e3d1452693c18a7119'],
            'dependency_fingerprint' => '',
        ];
        foreach ($scope['candidate_bindings'] as &$binding) {
            $binding['binding_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize($binding));
        }
        unset($binding);
        $scope['dependency_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize(array_map(static fn (array $binding): array => [$binding['candidate_id'], $binding['dependencies']], $scope['candidate_bindings'])));
        return [$scope, $capture, ['authority_intent' => ['requests' => [['entity_type' => 'model', 'operation' => 'create', 'name' => 'Atherton', 'payload' => ['brand_uuid' => '01a090fd-9a71-7665-af5f-08f6e25b533e']]]]]];
    }
}
