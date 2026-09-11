<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;
use NHK\Core\Infrastructure\Governance\WpOptionConversationalAuthorityPolicyStorage;
use PHPUnit\Framework\TestCase;

final class ConversationalAuthorityAdminPolicyTest extends TestCase
{
    public function test_default_is_review_required_and_write_is_closed(): void
    {
        $value = null;
        $storage = new WpOptionConversationalAuthorityPolicyStorage(
            static fn (string $name, string $default): string => $default,
            static function (string $name, string $policy) use (&$value): bool { $value = $policy; return true; },
        );

        self::assertSame(ConversationalAuthorityPolicy::REVIEW_REQUIRED, $storage->read());
        $storage->write(ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION);
        self::assertSame('AUTO_APPROVE_AFTER_OWNER_CONFIRMATION', $value);
    }

    public function test_invalid_stored_policy_fails_closed(): void
    {
        $storage = new WpOptionConversationalAuthorityPolicyStorage(static fn (): string => 'AUTO_PUBLISH');
        self::assertSame(ConversationalAuthorityPolicy::REVIEW_REQUIRED, $storage->read());
    }
}
