<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Seo\LivingKnowledgeSeoStabilityGuard;
use PHPUnit\Framework\TestCase;
final class LivingKnowledgeSeoStabilityGuardTest extends TestCase
{
    public function test_low_content_enrichment_preserves_stable_core(): void
    {
        $result = (new LivingKnowledgeSeoStabilityGuard())->evaluate(['url' => '/odo/','h1' => 'Odo 62','title' => 'Odo 62','canonical' => '/odo/','indexable' => true], ['url' => '/odo/','h1' => 'Odo 62','title' => 'Odo 62','canonical' => '/odo/','indexable' => true, 'content_changed' => true]);
        self::assertSame('LOW', $result['risk']); self::assertTrue($result['allowed']);
    }
    public function test_high_identity_change_requires_human_gate(): void
    {
        $result = (new LivingKnowledgeSeoStabilityGuard())->evaluate(['url' => '/odo/','h1' => 'Odo 62','title' => 'Odo 62','canonical' => '/odo/','indexable' => true], ['url' => '/odo-62/','h1' => 'Odo 62','title' => 'Odo 62','canonical' => '/odo-62/','indexable' => true]);
        self::assertSame('HIGH', $result['risk']); self::assertFalse($result['allowed']); self::assertSame('HUMAN_GATE_REQUIRED', $result['diagnostic']);
    }
}
