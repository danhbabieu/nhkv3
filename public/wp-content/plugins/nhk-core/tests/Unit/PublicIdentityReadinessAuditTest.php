<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Migration\PublicIdentityReadinessAudit;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;
final class PublicIdentityReadinessAuditTest extends TestCase
{
    public function test_empty_audit_is_successful_and_non_mutating(): void { $result = (new PublicIdentityReadinessAudit())->run([]); self::assertSame('READY_FOR_REVIEW', $result['status']); self::assertSame(0, $result['mutation_count']); self::assertSame([], $result['items']); }
    public function test_unavailable_runtime_is_distinct_and_non_mutating(): void { $result = (new PublicIdentityReadinessAudit())->run([], false); self::assertSame('ENVIRONMENT_BLOCKED', $result['status']); self::assertSame(0, $result['mutation_count']); }
    public function test_malformed_and_ineligible_rows_are_reported_without_repair(): void { $result = (new PublicIdentityReadinessAudit())->run([['owner_id' => 'not-a-uuid', 'current_slug' => ''], ['owner_id' => UuidCodec::newV7(), 'current_slug' => 'odo', 'hydrated' => false, 'eligible' => false]]); self::assertSame(['INVALID_SLUG' => 1, 'INELIGIBLE' => 1], $result['counts']); self::assertSame(0, $result['mutation_count']); self::assertTrue($result['canary']['classified']); }

    public function test_variant_without_unique_model_scope_reports_explicit_scope_blockers(): void
    {
        $result = (new PublicIdentityReadinessAudit())->run([[
            'owner_id' => UuidCodec::newV7(),
            'route_type' => 'variant',
            'current_slug' => 'junghans-8-con',
            'required_route_scope' => 'model',
            'model_parent_id' => null,
        ]]);

        self::assertSame('INELIGIBLE', $result['items'][0]['status']);
        self::assertContains('MISSING_REQUIRED_ROUTE_SCOPE', $result['items'][0]['reasons']);
        self::assertContains('MISSING_VARIANT_MODEL_PARENT', $result['items'][0]['reasons']);
    }
}
