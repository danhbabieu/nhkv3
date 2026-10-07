<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicPresentationComponentsTest extends TestCase
{
    public function test_shared_section_and_empty_state_components_are_used_by_public_families(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['media.php', 'knowledge.php', 'tri-thuc.php', 'index.php'] as $template) {
            $source = (string) file_get_contents($theme . '/' . $template);
            self::assertStringContainsString('template-parts/presentation/empty-state', $source, $template);
        }
        self::assertStringContainsString('template-parts/presentation/section-header', (string) file_get_contents($theme . '/template-parts/presentation/contextual-discovery.php'));
    }

    public function test_public_presentation_keeps_one_entry_file_per_approved_family(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['entity.php', 'dictionary.php', 'video.php', 'media.php', 'knowledge.php', 'single.php', 'front-page.php', 'comparison.php'] as $template) {
            self::assertFileExists($theme . '/' . $template);
        }
        foreach (['brand.php', 'model.php', 'movement.php', 'component.php', 'clock-type.php', 'specimen.php', 'product.php', 'media-detail.php', 'knowledge-detail.php'] as $forbidden) {
            self::assertFileDoesNotExist($theme . '/' . $forbidden);
        }
    }
}
