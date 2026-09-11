<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Media\VisualSupportRequirement;
use NHK\Core\Infrastructure\Media\WpdbVisualSupportRequirementRepository;
use PHPUnit\Framework\TestCase;

final class WpdbVisualSupportRequirementRepositoryTest extends TestCase
{
    public function test_repository_implements_bounded_ledger_contract(): void
    {
        self::assertInstanceOf(VisualSupportRequirementRepository::class, new WpdbVisualSupportRequirementRepository(new class { public string $prefix = 'wp_'; }));
        self::assertTrue(method_exists(WpdbVisualSupportRequirementRepository::class, 'findCandidatesForMedia'));
        self::assertTrue(method_exists(WpdbVisualSupportRequirementRepository::class, 'listForAdmin'));
        self::assertSame(VisualSupportRequirement::class, (new \ReflectionMethod(WpdbVisualSupportRequirementRepository::class, 'save'))->getParameters()[0]->getType()->getName());
    }
}
