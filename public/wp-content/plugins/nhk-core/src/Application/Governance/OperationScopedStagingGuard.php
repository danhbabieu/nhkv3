<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\StagingGuard;
use NHK\Core\Domain\Governance\{Proposal, ProposalSubjectBindingValidator};

/**
 * Fail-closed staging boundary. Operation/capability authorization is paired
 * with a verified, exact Capture acceptance scope before staging apply.
 */
final class OperationScopedStagingGuard implements StagingGuard
{
    /** @param callable():string $environment @param callable(string):bool $can @param callable(array<string,mixed>,Proposal):bool|null $scopeVerifier */
    public function __construct(
        private $environment,
        private $can,
        private OperationCompatibility $operations = new ControlledApplyOperationRegistry(),
        private int $maxPayloadBytes = 1000000,
        private int $maxDependencyCount = 50,
        private $scopeVerifier = null,
    ) {}

    public function assertAllowed(Proposal $proposal): void
    {
        $environment = strtolower(trim((string) ($this->environment)()));
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') return;
        if (!$this->operations->supports($proposal->entityType, $proposal->operation)) throw new \RuntimeException('STAGING_OPERATION_UNREGISTERED');
        foreach ($this->capabilities($proposal) as $capability) {
            if (!(bool) ($this->can)($capability)) throw new \RuntimeException('STAGING_CAPABILITY_REQUIRED:' . $capability);
        }
        if (!ProposalSubjectBindingValidator::isValid($proposal)) throw new \RuntimeException('STAGING_SUBJECT_BINDING_INVALID');
        if (count($proposal->payload) > 0 && strlen(json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) throw new \RuntimeException('STAGING_PAYLOAD_TOO_LARGE');
        $dependencies = $proposal->payload['dependency_ids'] ?? [];
        if (is_array($dependencies) && count($dependencies) > $this->maxDependencyCount) throw new \RuntimeException('STAGING_DEPENDENCY_BOUND_EXCEEDED');
        if (trim($proposal->idempotencyKey) === '') throw new \RuntimeException('STAGING_IDEMPOTENCY_REQUIRED');
        $scope = $proposal->payload['staging_acceptance'] ?? null;
        if (!is_array($scope)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
        if (!is_callable($this->scopeVerifier)) throw new \RuntimeException('STAGING_SCOPE_VERIFIER_REQUIRED');
        if (!(bool) ($this->scopeVerifier)($scope, $proposal)) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
        StagingAcceptanceScope::assertProposal($proposal, $scope);
    }

    /** @return list<string> */
    private function capabilities(Proposal $proposal): array
    {
        $capabilities = ['nhk_apply_proposals'];
        if ($proposal->entityType === 'media' && in_array($proposal->operation, ['representative_bind', 'add', 'replace', 'remove'], true)) $capabilities[] = 'nhk_internal_content_operations';
        // wp_post relation operations write Graph only. Native editorial
        // publication is separately authorized by OwnerPublicationService and
        // ArticlePublicationGate, never by this semantic apply guard.
        return $capabilities;
    }
}
