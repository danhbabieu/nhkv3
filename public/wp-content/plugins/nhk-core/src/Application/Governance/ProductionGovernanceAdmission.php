<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\StagingGuard;
use NHK\Core\Domain\Governance\{Proposal, ProposalSubjectBindingValidator};

/**
 * Production continuation admission. Production never accepts a staging
 * packet; it still requires the exact governed proposal boundary before the
 * canonical executor is reached.
 */
final class ProductionGovernanceAdmission implements StagingGuard
{
    public function __construct(
        private $environment,
        private $can,
        private OperationCompatibility $operations = new ControlledApplyOperationRegistry(),
        private int $maxPayloadBytes = 1000000,
        private int $maxDependencyCount = 50,
    ) {}

    public function assertAllowed(Proposal $proposal): void
    {
        $environment = strtolower(trim((string) ($this->environment)()));
        if (!in_array($environment, ['production', 'prod'], true)) return;
        if (!$this->operations->supports($proposal->entityType, $proposal->operation)) throw new \RuntimeException('PRODUCTION_OPERATION_UNREGISTERED');
        foreach ($this->capabilities($proposal) as $capability) {
            if (!(bool) ($this->can)($capability)) throw new \RuntimeException('PRODUCTION_CAPABILITY_REQUIRED:' . $capability);
        }
        if (!ProposalSubjectBindingValidator::isValid($proposal)) throw new \RuntimeException('PRODUCTION_SUBJECT_BINDING_INVALID');
        if (strlen(json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) throw new \RuntimeException('PRODUCTION_PAYLOAD_TOO_LARGE');
        $dependencies = $proposal->payload['dependency_ids'] ?? [];
        if (is_array($dependencies) && count($dependencies) > $this->maxDependencyCount) throw new \RuntimeException('PRODUCTION_DEPENDENCY_BOUND_EXCEEDED');
        if (trim($proposal->idempotencyKey) === '') throw new \RuntimeException('PRODUCTION_IDEMPOTENCY_REQUIRED');
    }

    /** @return list<string> */
    private function capabilities(Proposal $proposal): array
    {
        $capabilities = ['nhk_apply_proposals'];
        if ($proposal->entityType === 'video' && $proposal->operation === 'source_refresh') $capabilities[] = 'nhk_create_proposals';
        if ($proposal->entityType === 'media' && in_array($proposal->operation, ['representative_bind', 'add', 'replace', 'remove'], true)) $capabilities[] = 'nhk_internal_content_operations';
        return $capabilities;
    }
}
