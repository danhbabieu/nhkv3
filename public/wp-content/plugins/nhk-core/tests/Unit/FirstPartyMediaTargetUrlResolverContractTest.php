<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Contracts\Media\FirstPartyMediaTargetUrlResolver;
use PHPUnit\Framework\TestCase;

final class FirstPartyMediaTargetUrlResolverContractTest extends TestCase
{
    public function test_contract_exposes_a_single_canonical_url_locator_method(): void
    {
        self::assertTrue(interface_exists(FirstPartyMediaTargetUrlResolver::class));
        self::assertTrue(method_exists(FirstPartyMediaTargetUrlResolver::class, 'resolve'));
        self::assertSame(1, count((new \ReflectionClass(FirstPartyMediaTargetUrlResolver::class))->getMethods()));
    }
}
