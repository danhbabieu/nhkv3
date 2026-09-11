<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityPlanFingerprint;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class AuthorityPlanFingerprintTest extends TestCase
{
    public function test_same_plan_and_execution_contract_is_stable(): void
    {
        $id = UuidCodec::newV7();
        $plan = ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-1', 'entity_type' => 'brand', 'proposed_canonical_name' => 'Hermle']]];
        $contract = ['documentation_version' => str_repeat('a', 64), 'manifest_hash' => str_repeat('b', 64), 'predicate_registry_fingerprint' => 'predicate-v1'];

        self::assertSame(AuthorityPlanFingerprint::compute($id, 2, $plan, $contract), AuthorityPlanFingerprint::compute($id, 2, $plan, $contract));
    }

    public function test_contract_change_invalidates_the_plan(): void
    {
        $id = UuidCodec::newV7();
        $plan = ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-1', 'entity_type' => 'brand', 'proposed_canonical_name' => 'Hermle']]];
        $base = ['documentation_version' => str_repeat('a', 64), 'manifest_hash' => str_repeat('b', 64), 'predicate_registry_fingerprint' => 'predicate-v1', 'effective_governance_policy' => 'REVIEW_REQUIRED'];

        $changed = $base;
        $changed['predicate_registry_fingerprint'] = 'predicate-v2';
        self::assertNotSame(AuthorityPlanFingerprint::compute($id, 2, $plan, $base), AuthorityPlanFingerprint::compute($id, 2, $plan, $changed));

        $changed = $base;
        $changed['effective_governance_policy'] = 'OFF';
        self::assertNotSame(AuthorityPlanFingerprint::compute($id, 2, $plan, $base), AuthorityPlanFingerprint::compute($id, 2, $plan, $changed));
    }
}
