<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

use InvalidArgumentException;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class ArticleOperationReceipt
{
    /** @param list<string> $proposalIds @param list<string> $appliedProposalIds @param array<string,mixed> $failure */
    public function __construct(
        public string $operationId,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public string $intent,
        public ?string $wpEndpointKey,
        public ?int $wpPostId,
        public string $stage,
        public ArticleIngestOutcome $outcome,
        public bool $retryable,
        public array $proposalIds = [],
        public array $appliedProposalIds = [],
        public array $failure = [],
        public int $revision = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $wpStateToken = null,
        /** @var array<string,list<string>> */
        public array $dependencyMap = [],
        /** @var array<string,string> */
        public array $proposalStates = [],
        /** @var array<string,int> */
        public array $applyAttempts = [],
    ) {
        if (!UuidCodec::isValid($operationId) || $idempotencyKey === '' || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint)) {
            throw new InvalidArgumentException('Article operation identity and fingerprint are invalid.');
        }
        if (!in_array($intent, ['reconcile', 'create', 'update'], true) || $stage === '' || $revision < 1) {
            throw new InvalidArgumentException('Article operation state is invalid.');
        }
        if ($wpPostId !== null && $wpPostId < 1) throw new InvalidArgumentException('WordPress Post ID must be positive.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'idempotency_key' => $this->idempotencyKey,
            'request_fingerprint' => $this->requestFingerprint,
            'intent' => $this->intent,
            'wp_endpoint_key' => $this->wpEndpointKey,
            'wp_post_id' => $this->wpPostId,
            'wp_state_token' => $this->wpStateToken,
            'dependency_map' => $this->dependencyMap,
            'proposal_states' => $this->proposalStates,
            'apply_attempts' => $this->applyAttempts,
            'stage' => $this->stage,
            'outcome' => $this->outcome->value,
            'retryable' => $this->retryable,
            'proposal_ids' => $this->proposalIds,
            'applied_proposal_ids' => $this->appliedProposalIds,
            'failure' => $this->failure,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
