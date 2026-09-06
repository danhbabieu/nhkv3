<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Admin\AdminDiagnosticPresenter;
use PHPUnit\Framework\TestCase;

final class AdminDiagnosticPresenterTest extends TestCase
{
    public function test_unknown_diagnostic_is_system_blocked_and_not_overridable(): void
    {
        $result = AdminDiagnosticPresenter::present('UNREGISTERED_DIAGNOSTIC');

        self::assertSame('system_blocked', $result['severity']);
        self::assertFalse($result['overridable']);
    }

    public function test_registered_publication_diagnostic_preserves_owner_review_policy(): void
    {
        $result = AdminDiagnosticPresenter::present('REAL_IMAGE_INCOMPLETE');

        self::assertSame('owner_review_required', $result['severity']);
        self::assertSame('Ảnh thật chưa hoàn tất.', $result['message']);
        self::assertSame('Bổ sung ảnh thật phù hợp.', $result['remediation']);
        self::assertTrue($result['overridable']);
    }

    public function test_proposal_state_hint_is_presentation_only_and_fail_closed(): void
    {
        self::assertSame('uncertain', AdminDiagnosticPresenter::forProposalState('approved')['severity']);
        self::assertSame('success', AdminDiagnosticPresenter::forProposalState('applied')['severity']);
        self::assertSame('blocked', AdminDiagnosticPresenter::forProposalState('submitted')['severity']);
        self::assertSame('system_blocked', AdminDiagnosticPresenter::forProposalState('unexpected')['severity']);
    }
}
