<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use InvalidArgumentException;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Contracts\Governance\AutomationPolicyStorage;
use NHK\Core\Domain\Governance\AutomationMode;
use PHPUnit\Framework\TestCase;

final class GovernanceAutomationPolicyTest extends TestCase
{
    public function test_resolver_defaults_registered_types_to_review_required(): void
    {
        $resolver = new GovernanceAutomationPolicyResolver(
            ['video', 'media', 'wp_post', 'knowledge'],
            new class implements AutomationPolicyStorage {
                public function read(): array { return []; }
                public function write(array $policies): void {}
            },
        );

        self::assertSame(AutomationMode::REVIEW_REQUIRED, $resolver->resolve('video'));
        self::assertSame(AutomationMode::REVIEW_REQUIRED, $resolver->resolve('knowledge'));
    }

    public function test_resolver_reads_independent_modes_for_registered_types(): void
    {
        $resolver = new GovernanceAutomationPolicyResolver(
            ['video', 'media', 'wp_post', 'knowledge'],
            new class implements AutomationPolicyStorage {
                public function read(): array { return ['video' => 'AUTO_PUBLISH', 'media' => 'AUTO_APPROVE']; }
                public function write(array $policies): void {}
            },
        );

        self::assertSame(AutomationMode::AUTO_PUBLISH, $resolver->resolve('video'));
        self::assertSame(AutomationMode::AUTO_APPROVE, $resolver->resolve('media'));
        self::assertSame(AutomationMode::REVIEW_REQUIRED, $resolver->resolve('wp_post'));
    }

    public function test_resolver_rejects_unknown_type_and_invalid_mode(): void
    {
        $resolver = new GovernanceAutomationPolicyResolver(
            ['video'],
            new class implements AutomationPolicyStorage {
                public function read(): array { return ['video' => 'INVALID']; }
                public function write(array $policies): void {}
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('video');
    }

    public function test_resolver_rejects_unknown_registered_type(): void
    {
        $resolver = new GovernanceAutomationPolicyResolver(
            ['video'],
            new class implements AutomationPolicyStorage {
                public function read(): array { return []; }
                public function write(array $policies): void {}
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('note');
    }
}
