<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\SemanticRekeyMediaIsolation;
use PHPUnit\Framework\TestCase;

final class SemanticRekeyMediaIsolationTest extends TestCase
{
    public function test_semantic_rekey_plan_contains_no_wordpress_path_mutation(): void
    {
        self::assertSame(['old_stable_key' => 'nhk:component:o-do.x', 'new_stable_key' => 'nhk:component:odo.x'], SemanticRekeyMediaIsolation::plan('nhk:component:o-do.x', 'nhk:component:odo.x'));
    }

    public function test_media_path_fields_are_rejected_from_semantic_rekey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SemanticRekeyMediaIsolation::assertSemanticOnly(['file_path' => '/uploads/o-do-x.webp']);
    }
}
