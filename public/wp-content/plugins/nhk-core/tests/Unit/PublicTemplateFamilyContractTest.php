<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Presentation\PublicTemplateFamilyContract;
use PHPUnit\Framework\TestCase;

final class PublicTemplateFamilyContractTest extends TestCase
{
    public function test_public_theme_has_no_record_specific_template_files_or_presentation_branches(): void
    {
        $theme = dirname(__DIR__, 3) . '/../../themes/nhk-v3';
        $audit = PublicTemplateFamilyContract::auditTheme($theme);

        self::assertSame([], $audit['forbidden_files']);
        self::assertSame([], $audit['forbidden_references']);
    }

    public function test_neutral_records_share_the_family_entry_template(): void
    {
        self::assertSame('single.php', PublicTemplateFamilyContract::templateFor('article', ['slug' => 'article-a']));
        self::assertSame('single.php', PublicTemplateFamilyContract::templateFor('article', ['slug' => 'article-b']));
        self::assertSame('video.php', PublicTemplateFamilyContract::templateFor('video', ['slug' => 'video-a']));
        self::assertSame('video.php', PublicTemplateFamilyContract::templateFor('video', ['slug' => 'video-b']));
        self::assertSame('dictionary.php', PublicTemplateFamilyContract::templateFor('dictionary', ['term' => 'term-a']));
        self::assertSame('dictionary.php', PublicTemplateFamilyContract::templateFor('dictionary', ['term' => 'term-b']));
        self::assertSame('entity.php', PublicTemplateFamilyContract::templateFor('brand', ['name' => 'Brand A']));
        self::assertSame('entity.php', PublicTemplateFamilyContract::templateFor('model', ['name' => 'Model A']));
        self::assertSame('entity.php', PublicTemplateFamilyContract::templateFor('component', ['name' => 'Component A']));
    }

    public function test_public_routes_reference_family_entry_templates_without_record_interpolation(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [
            $root . '/src/Infrastructure/Http/PublicEntityRoutes.php' => 'entity.php',
            $root . '/src/Infrastructure/Http/PublicDictionaryRoutes.php' => 'dictionary.php',
            $root . '/src/Infrastructure/Http/PublicMediaVideoRoutes.php' => 'video.php',
            $root . '/src/Infrastructure/Http/PublicEditorialRoutes.php' => null,
        ];

        foreach ($sources as $source => $template) {
            $contents = (string) file_get_contents($source);
            if ($template !== null) self::assertStringContainsString("locate_template('{$template}'", $contents, $source);
            self::assertStringNotContainsString("locate_template(\$", $contents, $source);
        }

        $editorial = (string) file_get_contents($root . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString("locate_template('tri-thuc.php'", $editorial);
        self::assertStringContainsString("locate_template('index.php'", $editorial);
    }
}
