<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class VideoRelationAdminUiTest extends TestCase
{
    public function test_video_workspace_exposes_canonical_guided_flow_and_blockers(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/admin/admin-workbench.js');

        foreach (['nhk-video-id', 'nhk-video-proposal-id', 'nhk-video-target-type', 'nhk-video-target-id', 'nhk-video-evidence-id', 'relation_create', 'EXPLICIT_USER_RELATION', 'EVIDENCE_REFS_REQUIRED', 'Controlled Apply'] as $needle) {
            self::assertStringContainsString($needle, $page . $script);
        }
        self::assertStringContainsString('/admin/video-relation/context/', $script);
        self::assertStringContainsString('/admin/video-relation', $script);
        self::assertStringContainsString('Relation hiện có', $script);
    }
}
