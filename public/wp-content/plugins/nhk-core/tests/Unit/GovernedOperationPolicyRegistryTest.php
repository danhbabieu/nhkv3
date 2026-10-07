<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\{ControlledApplyOperationRegistry, GovernedOperationPolicyRegistry};
use PHPUnit\Framework\TestCase;

final class GovernedOperationPolicyRegistryTest extends TestCase
{
    public function test_every_executor_supported_pair_has_explicit_generic_metadata(): void
    {
        $registry = new GovernedOperationPolicyRegistry();
        $policies = $registry->all();

        self::assertNotEmpty($policies);
        foreach ($policies as $policy) {
            self::assertNotSame('', $policy->entityType);
            self::assertNotSame('', $policy->operation);
            self::assertNotSame('', $policy->operationFamily);
            self::assertContains($policy->lifecycleClass, ['CREATE', 'MUTATE_EXISTING', 'RETIRE', 'REACTIVATE', 'RELATION_MUTATION', 'SPECIAL']);
            self::assertContains($policy->revisionPolicy, ['ZERO', 'CURRENT_REQUIRED', 'NONE']);
            self::assertNotSame('', $policy->targetBinding);
            self::assertIsBool($policy->captureStagingAllowed);
            self::assertIsBool($policy->productionAllowed);
            self::assertIsArray($policy->requiredCapabilities);
        }
    }

    /** @dataProvider lifecycleProvider */
    public function test_lifecycle_policy_is_explicit(string $entity, string $operation, string $family, string $lifecycle, string $revision, bool $staging): void
    {
        $policy = (new GovernedOperationPolicyRegistry())->find($entity, $operation);

        self::assertNotNull($policy);
        self::assertSame($family, $policy->operationFamily);
        self::assertSame($lifecycle, $policy->lifecycleClass);
        self::assertSame($revision, $policy->revisionPolicy);
        self::assertSame($staging, $policy->captureStagingAllowed);
        self::assertTrue($policy->productionAllowed);
    }

    /** @return iterable<string,array{string,string,string,string,string,bool}> */
    public static function lifecycleProvider(): iterable
    {
        yield 'knowledge reactivate' => ['knowledge', 'reactivate', 'knowledge_delta', 'REACTIVATE', 'CURRENT_REQUIRED', true];
        yield 'source reactivate' => ['source', 'reactivate', 'source_evidence_reconciliation', 'REACTIVATE', 'CURRENT_REQUIRED', true];
        yield 'evidence reactivate' => ['evidence', 'reactivate', 'source_evidence_reconciliation', 'REACTIVATE', 'CURRENT_REQUIRED', true];
        yield 'video retire is explicit staging denial' => ['video', 'retire', 'governed_video_plan', 'RETIRE', 'CURRENT_REQUIRED', false];
        yield 'video source refresh is special' => ['video', 'source_refresh', 'video_source_refresh', 'SPECIAL', 'CURRENT_REQUIRED', false];
        yield 'media usage replace is special' => ['media', 'replace', 'media_usage_reconciliation', 'SPECIAL', 'NONE', true];
        yield 'relation replacement is relation mutation' => ['relation', 'relation_replace', 'capture_child_relation', 'RELATION_MUTATION', 'NONE', true];
        yield 'wp post subject binding is special' => ['wp_post', 'subject_bind', 'wp_post_subject_binding', 'SPECIAL', 'NONE', false];
        yield 'authority update keeps owner staging flow' => ['model', 'update', 'governed_authority_plan', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', true];
    }

    public function test_unknown_operation_is_not_supported_or_invented_as_authority(): void
    {
        $registry = new GovernedOperationPolicyRegistry();
        $compatibility = new ControlledApplyOperationRegistry();

        self::assertNull($registry->find('not-an-owner', 'update'));
        self::assertFalse($registry->supports('not-an-owner', 'update'));
        self::assertFalse($compatibility->supports('not-an-owner', 'update'));
        self::assertFalse($compatibility->supports('knowledge', 'not-a-real-operation'));
    }
}
