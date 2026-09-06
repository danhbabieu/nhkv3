<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Infrastructure\Admin\AdminWorkbenchRegistry;
use PHPUnit\Framework\TestCase;

final class AdminWorkbenchRegistryTest extends TestCase
{
    public function test_registry_exposes_the_task_first_workbench_in_stable_order(): void
    {
        $registry = new AdminWorkbenchRegistry();
        $sections = $registry->sections();

        self::assertSame(
            ['overview', 'content', 'media', 'video', 'knowledge', 'governance', 'dictionary', 'system', 'advanced'],
            array_column($sections, 'id')
        );
        self::assertCount(count($sections), array_unique(array_column($sections, 'id')));
        self::assertCount(count($sections), array_unique(array_column($sections, 'slug')));
    }

    public function test_registry_keeps_native_editorial_and_attachment_owners_visible(): void
    {
        $registry = new AdminWorkbenchRegistry();

        self::assertSame('WordPress', $registry->section('content')['owner']);
        self::assertSame('edit.php', $registry->section('content')['href']);
        self::assertSame('native', $registry->section('content')['kind']);
        self::assertSame('edit_posts', $registry->section('content')['capability']);

        self::assertSame('Media + WordPress', $registry->section('media')['owner']);
        self::assertSame('upload.php', $registry->section('media')['href']);
        self::assertSame('native', $registry->section('media')['kind']);
        self::assertSame('upload_files', $registry->section('media')['capability']);
    }

    public function test_registry_preserves_governance_and_dictionary_capabilities(): void
    {
        $registry = new AdminWorkbenchRegistry();

        self::assertSame('nhk_view_governance', $registry->section('governance')['capability']);
        self::assertSame('Governance', $registry->section('governance')['owner']);
        self::assertSame('nhk_curate_dictionary', $registry->section('dictionary')['capability']);
        self::assertSame('Dictionary', $registry->section('dictionary')['owner']);
    }

    public function test_every_section_has_human_copy_owner_capability_and_safe_destination(): void
    {
        $registry = new AdminWorkbenchRegistry();

        foreach ($registry->sections() as $section) {
            self::assertNotSame('', trim((string) $section['id']));
            self::assertNotSame('', trim((string) $section['slug']));
            self::assertNotSame('', trim((string) $section['label']));
            self::assertNotSame('', trim((string) $section['description']));
            self::assertNotSame('', trim((string) $section['owner']));
            self::assertNotSame('', trim((string) $section['capability']));
            self::assertNotSame('', trim((string) $section['href']));
            self::assertContains($section['kind'], ['native', 'workbench', 'advanced']);

            $href = (string) $section['href'];
            self::assertFalse(str_contains($href, 'post-new.php?post_type=nhk'));
            self::assertFalse(str_contains($href, 'admin-post.php?action=semantic'));
            self::assertFalse(str_contains($href, '/wp-json/'));
        }
    }

    public function test_unknown_section_returns_null_instead_of_guessing(): void
    {
        self::assertNull((new AdminWorkbenchRegistry())->section('not-registered'));
    }
}
