<?php
declare(strict_types=1);

namespace NHK\Core\Application\Runtime;

final class SemanticWritePolicyResolver
{
    public const CAPABILITY = 'nhk_project_build_semantic';

    /** @param callable():mixed|null $configReader @param callable():mixed|null $environmentReader */
    public function __construct(private $configReader = null, private $environmentReader = null)
    {
        $this->configReader ??= static function (): mixed {
            if (defined('NHK_SEMANTIC_WRITE_POLICY')) return constant('NHK_SEMANTIC_WRITE_POLICY');
            $value = getenv('NHK_SEMANTIC_WRITE_POLICY');
            return $value === false ? null : $value;
        };
        $this->environmentReader ??= static function (): string {
            if (defined('WP_ENVIRONMENT_TYPE')) return strtolower(trim((string) constant('WP_ENVIRONMENT_TYPE')));
            $value = getenv('WP_ENVIRONMENT_TYPE');
            if (is_string($value) && trim($value) !== '') return strtolower(trim($value));
            if (function_exists('wp_get_environment_type')) return strtolower(trim((string) wp_get_environment_type()));
            return 'unknown';
        };
    }

    public function resolve(): SemanticWritePolicy
    {
        return SemanticWritePolicy::fromConfiguration(($this->configReader)());
    }

    public function environment(): string
    {
        $environment = trim(strtolower((string) ($this->environmentReader)()));
        return $environment === '' ? 'unknown' : $environment;
    }

    public function projectBuildEnabled(): bool
    {
        return $this->resolve() === SemanticWritePolicy::PROJECT_BUILD && $this->isAllowedProjectBuildEnvironment($this->environment());
    }

    /** @param callable(string):bool $can @return array{allowed:bool,code:?string,environment:string,semantic_write_policy:string,project_build_enabled:bool} */
    public function decision(bool $authenticated, callable $can): array
    {
        $policy = $this->resolve();
        $environment = $this->environment();
        $enabled = $policy === SemanticWritePolicy::PROJECT_BUILD && $this->isAllowedProjectBuildEnvironment($environment) && $environment !== 'production';
        $base = ['allowed' => false, 'code' => null, 'environment' => $environment, 'semantic_write_policy' => $policy->value, 'project_build_enabled' => $enabled];
        if (!$authenticated) return array_replace($base, ['code' => 'AUTHENTICATION_REQUIRED']);
        if ($policy === SemanticWritePolicy::READ_ONLY) return array_replace($base, ['code' => 'SEMANTIC_WRITE_POLICY_READ_ONLY']);
        if ($policy === SemanticWritePolicy::PROJECT_BUILD) {
            if ($this->isProduction($environment)) return array_replace($base, ['code' => 'PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION']);
            if (!$this->isAllowedProjectBuildEnvironment($environment)) return array_replace($base, ['code' => 'PROJECT_BUILD_ENVIRONMENT_REQUIRED']);
            if (!(bool) $can(self::CAPABILITY)) return array_replace($base, ['code' => 'PROJECT_BUILD_CAPABILITY_REQUIRED']);
        }
        return array_replace($base, ['allowed' => true]);
    }

    private function isProduction(string $environment): bool
    {
        return in_array($environment, ['production', 'prod'], true);
    }

    private function isAllowedProjectBuildEnvironment(string $environment): bool
    {
        return in_array($environment, ['development', 'staging-build', 'staging'], true);
    }
}
