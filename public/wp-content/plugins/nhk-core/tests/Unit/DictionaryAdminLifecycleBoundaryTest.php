<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryAdminLifecycleBoundaryTest extends TestCase
{
    public function test_admin_exposes_lexical_edit_and_retire_reactivate_without_delete(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Admin/DictionaryAdminPage.php');

        self::assertStringContainsString("admin_post_nhk_dictionary_edit", $source);
        self::assertStringContainsString("admin_post_nhk_dictionary_lifecycle", $source);
        self::assertStringContainsString("->mutation()->updateConcept", $source);
        self::assertStringContainsString("->mutation()->setConceptStatus", $source);
        self::assertStringNotContainsString("admin_post_nhk_dictionary_delete", $source);
        self::assertStringNotContainsString("deleteConcept", $source);
    }
}
