<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\StagingGuard;
use NHK\Core\Domain\Governance\{Proposal, ProposalSubjectBindingValidator};

/**
 * Fail-closed staging boundary. Authorization is expressed by operation and
 * capability, never by a growing list of semantic object IDs.
 */
final class OperationScopedStagingGuard implements StagingGuard
{
    /** @param callable():string $environment @param callable(string):bool $can */
    public function __construct(
        private $environment,
        private $can,
        private OperationCompatibility $operations = new ControlledApplyOperationRegistry(),
        private int $maxPayloadBytes = 1000000,
        private int $maxDependencyCount = 50,
    ) {}

    public function assertAllowed(Proposal $proposal): void
    {
        if (strtolower(trim((string) ($this->environment)())) !== 'staging') return;
        if (!$this->operations->supports($proposal->entityType, $proposal->operation)) throw new \RuntimeException('STAGING_OPERATION_UNREGISTERED');
        foreach ($this->capabilities($proposal) as $capability) {
            if (!(bool) ($this->can)($capability)) throw new \RuntimeException('STAGING_CAPABILITY_REQUIRED:' . $capability);
        }
        if (!ProposalSubjectBindingValidator::isValid($proposal)) throw new \RuntimeException('STAGING_SUBJECT_BINDING_INVALID');
        if (count($proposal->payload) > 0 && strlen(json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) throw new \RuntimeException('STAGING_PAYLOAD_TOO_LARGE');
        $dependencies = $proposal->payload['dependency_ids'] ?? [];
        if (is_array($dependencies) && count($dependencies) > $this->maxDependencyCount) throw new \RuntimeException('STAGING_DEPENDENCY_BOUND_EXCEEDED');
        if (trim($proposal->idempotencyKey) === '') throw new \RuntimeException('STAGING_IDEMPOTENCY_REQUIRED');
    }

    /** @return list<string> */
    private function capabilities(Proposal $proposal): array
    {
        $capabilities = ['nhk_apply_proposals'];
        if ($proposal->entityType === 'media' && $proposal->operation === 'representative_bind') $capabilities[] = 'nhk_internal_content_operations';
        // wp_post relation operations write Graph only. Native editorial
        // publication is separately authorized by OwnerPublicationService and
        // ArticlePublicationGate, never by this semantic apply guard.
        return $capabilities;
    }
}
