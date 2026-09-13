<?php
declare(strict_types=1);

namespace NHK\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ClockTypeEcosystemContractTest extends TestCase
{
    public function test_ecosystem_contract_freezes_existing_vocabulary_and_owner_boundaries(): void
    {
        $path = dirname(__DIR__, 6) . '/docs/architecture/CLOCK_TYPE_ECOSYSTEM_CONTRACT.md';
        self::assertFileExists($path);
        $contract = (string) file_get_contents($path);

        foreach (['family      = clock_type', '`subtype_of`', '`classified_as`', '`model_of`', '`variant_of`', '`about`', '`depicts`', 'Graph là relation owner duy nhất', 'Không tạo Brand↔Clock Type shortcut edge', 'Không cấp Public Identity/root route', 'DIRECT_MEDIA', 'DERIVED_MEDIA'] as $required) {
            self::assertStringContainsString($required, $contract, $required);
        }
        foreach (['ClockType', 'brand_types', 'types[]', 'new predicate'] as $forbidden) self::assertStringNotContainsString($forbidden, $contract, $forbidden);
    }
}
