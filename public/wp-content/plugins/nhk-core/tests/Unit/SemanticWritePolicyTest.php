<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Runtime\SemanticWritePolicy;
use NHK\Core\Application\Runtime\SemanticWritePolicyResolver;
use PHPUnit\Framework\TestCase;

final class SemanticWritePolicyTest extends TestCase
{
    public function test_missing_policy_defaults_to_read_only(): void
    {
        $resolver = $this->resolver(null, 'development');

        self::assertSame(SemanticWritePolicy::READ_ONLY, $resolver->resolve());
        self::assertSame('development', $resolver->environment());
    }

    public function test_invalid_policy_defaults_to_read_only(): void
    {
        self::assertSame(SemanticWritePolicy::READ_ONLY, $this->resolver('unsafe', 'staging')->resolve());
    }

    public function test_valid_policy_values_are_normalized_to_runtime_values(): void
    {
        self::assertSame(SemanticWritePolicy::READ_ONLY, $this->resolver('read_only', 'development')->resolve());
        self::assertSame(SemanticWritePolicy::PROJECT_BUILD, $this->resolver('PROJECT_BUILD', 'development')->resolve());
        self::assertSame(SemanticWritePolicy::LOCKED_OPERATIONAL, $this->resolver(' locked_operational ', 'development')->resolve());
    }

    public function test_read_only_blocks_authenticated_semantic_plan(): void
    {
        $decision = $this->resolver('read_only', 'development')->decision(true, static fn (string $capability): bool => true);

        self::assertFalse($decision['allowed']);
        self::assertSame('SEMANTIC_WRITE_POLICY_READ_ONLY', $decision['code']);
    }

    public function test_project_build_requires_its_capability(): void
    {
        $decision = $this->resolver('project_build', 'development')->decision(true, static fn (string $capability): bool => false);

        self::assertFalse($decision['allowed']);
        self::assertSame('PROJECT_BUILD_CAPABILITY_REQUIRED', $decision['code']);
    }

    public function test_project_build_is_allowed_only_in_an_allowlisted_non_production_environment(): void
    {
        $resolver = $this->resolver('project_build', 'staging-build');
        $decision = $resolver->decision(true, static fn (string $capability): bool => $capability === 'nhk_project_build_semantic');

        self::assertTrue($decision['allowed']);
        self::assertTrue($decision['project_build_enabled']);
        self::assertSame('PROJECT_BUILD', $decision['semantic_write_policy']);
    }

    public function test_production_project_build_fails_closed_before_capability_is_sufficient(): void
    {
        $decision = $this->resolver('project_build', 'production')->decision(true, static fn (string $capability): bool => true);

        self::assertFalse($decision['allowed']);
        self::assertSame('PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION', $decision['code']);
        self::assertFalse($decision['project_build_enabled']);
    }

    public function test_unknown_environment_cannot_enable_project_build(): void
    {
        $decision = $this->resolver('project_build', 'qa')->decision(true, static fn (string $capability): bool => true);

        self::assertFalse($decision['allowed']);
        self::assertSame('PROJECT_BUILD_ENVIRONMENT_REQUIRED', $decision['code']);
    }

    public function test_locked_operational_preserves_existing_operational_entry(): void
    {
        $decision = $this->resolver('locked_operational', 'production')->decision(true, static fn (string $capability): bool => false);

        self::assertTrue($decision['allowed']);
        self::assertSame('LOCKED_OPERATIONAL', $decision['semantic_write_policy']);
        self::assertFalse($decision['project_build_enabled']);
    }

    private function resolver(?string $policy, string $environment): SemanticWritePolicyResolver
    {
        return new SemanticWritePolicyResolver(
            static fn (): ?string => $policy,
            static fn (): string => $environment,
        );
    }
}
