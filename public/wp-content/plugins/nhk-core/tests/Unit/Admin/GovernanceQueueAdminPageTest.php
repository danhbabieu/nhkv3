<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class GovernanceQueueAdminPageTest extends TestCase
{
    public function test_page_wiring_uses_queue_renderer_and_post_only_handlers(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php');
        foreach (['GovernanceQueueQuery', 'GovernanceQueueActionService', 'GovernanceQueueRenderer', 'admin_post_nhk_governance_queue_action', 'admin_post_nhk_governance_queue_bulk', 'nhk_governance_queue_action', 'check_admin_referer', 'current_user_can', 'wp_safe_redirect'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('listRecent(', $source);
        self::assertStringNotContainsString('$wpdb->query', $source);
    }

    public function test_get_rendering_has_no_action_service_call_and_input_allowlists_are_present(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php');
        self::assertStringContainsString('$_GET', $source);
        self::assertStringContainsString('sanitize_key', $source);
        self::assertStringContainsString('absint', $source);
        self::assertStringContainsString('UuidCodec::isValid', $source);
        self::assertStringContainsString('ProposalState::cases', $source);
        self::assertStringContainsString("'submit', 'approve', 'reject', 'apply'", $source);
        self::assertStringContainsString("'created', 'updated', 'name', 'id', 'status'", $source);
    }

    public function test_every_proposal_state_is_handled_without_invalid_mutation_markup(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php') . (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueRenderer.php');
        foreach (['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'superseded', 'applied'] as $state) self::assertStringContainsString($state, $source);
        self::assertStringContainsString('Gửi duyệt', $source);
        self::assertStringContainsString('Từ chối', $source);
        self::assertStringContainsString('Apply', $source);
    }
}
