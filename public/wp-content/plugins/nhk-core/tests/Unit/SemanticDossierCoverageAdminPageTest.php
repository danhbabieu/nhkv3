<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SemanticDossierCoverageAdminPageTest extends TestCase
{
    public function test_admin_page_is_read_only_and_exposes_coverage_dimensions(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Infrastructure/Admin/SemanticDossierCoverageAdminPage.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("add_submenu_page('nhk-v3'", $source);
        self::assertStringContainsString('Semantic dossier coverage', $source);
        foreach (['Graph', 'Knowledge', 'Evidence', 'Images', 'Video', 'Articles', 'Gaps'] as $label) self::assertStringContainsString($label, $source);
        foreach (['proposal-create', 'proposal-apply', 'relation_create', '->create(', '->update(', '->retire('] as $mutation) self::assertStringNotContainsString($mutation, $source);
    }
}
