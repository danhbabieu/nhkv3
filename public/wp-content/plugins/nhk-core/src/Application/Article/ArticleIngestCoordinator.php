<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Contracts\Article\{ArticleApplyService, ArticleOperationReceiptRepository};
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt};
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Contracts\Governance\{DependencyRepository, ProposalRepository};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Media\ArticleMediaCoordinator;

final class ArticleIngestCoordinator
{
    public function __construct(
        private ArticleOperationReceiptRepository $receipts,
        private ?ArticleIngestPreflight $preflight = null,
        private ?SemanticProposalPlanner $planner = null,
        private ?EditorialStateReader $editorial = null,
        private ?GovernanceService $governance = null,
        private ?ArticleApplyService $apply = null,
        private ?ProposalRepository $proposals = null,
        private ?DependencyRepository $dependencies = null,
        private ?ArticleVerificationReader $verification = null,
        private ?ArticleMediaCoordinator $articleMedia = null,
    ) {}

    /** @var array<string,mixed> */
    private array $mediaDiagnostics = [];

    /** @param array<string,mixed> $input */
    public function execute(array $input): ArticleOperationReceipt
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') throw new \InvalidArgumentException('Article idempotency key is required.');
        $fingerprintInput = $input;
        unset($fingerprintInput['operation_id']);
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($fingerprintInput));
        $existing = $this->receipts->findByIdempotencyKey($key);
        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint, $fingerprint)) return $this->idempotencyConflict($existing, $key, $fingerprint, $input);
            if (!$existing->retryable || $existing->outcome === ArticleIngestOutcome::COMPLETED) return $existing;
            return $this->resume($existing, $input);
        }
        $operationId = (string) ($input['operation_id'] ?? UuidCodec::newV7());
        $intent = (string) ($input['intent'] ?? '');
        if ($intent !== 'reconcile') return $this->receipts->create(new ArticleOperationReceipt($operationId, $key, $fingerprint, in_array($intent, ['create', 'update'], true) ? $intent : 'update', null, null, 'complete', ArticleIngestOutcome::UNSUPPORTED_OPERATION, false));
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $endpoint = (string) ($target['endpoint_key'] ?? '');
        $postId = preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpoint, $match) === 1 ? (int) $match[1] : null;
        $receipt = $this->receipts->create(new ArticleOperationReceipt($operationId, $key, $fingerprint, 'reconcile', $endpoint !== '' ? $endpoint : null, $postId, 'receipt', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, [], [], ['code' => 'ARTICLE_COORDINATOR_RECEIPT_RESERVED']));
        if (!hash_equals($receipt->requestFingerprint, $fingerprint)) return $this->idempotencyConflict($receipt, $key, $fingerprint, $input);
        return $this->resume($receipt, $input);
    }

    /** @param array<string,mixed> $input */
    private function idempotencyConflict(ArticleOperationReceipt $existing, string $key, string $fingerprint, array $input): ArticleOperationReceipt
    {
        $intent = in_array((string) ($input['intent'] ?? ''), ['reconcile', 'create', 'update'], true) ? (string) $input['intent'] : 'update';
        $operationId = (string) ($input['operation_id'] ?? UuidCodec::newV7());
        return new ArticleOperationReceipt($operationId, $key, $fingerprint, $intent, $existing->wpEndpointKey, $existing->wpPostId, 'complete', ArticleIngestOutcome::IDEMPOTENCY_CONFLICT, false, $existing->proposalIds, $existing->appliedProposalIds, ['code' => 'ARTICLE_IDEMPOTENCY_KEY_REUSED', 'original_operation_id' => $existing->operationId]);
    }

    /** @param array<string,mixed> $input */
    private function resume(ArticleOperationReceipt $receipt, array $input): ArticleOperationReceipt
    {
        if (!$this->preflight || !$this->planner || !$this->editorial || !$this->governance || !$this->proposals) return $this->save($receipt, 'preflight', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'ARTICLE_COORDINATOR_DEPENDENCIES_NOT_WIRED']);
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $postId = $receipt->wpPostId;
        if ($postId === null) return $this->save($receipt, 'preflight', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'WP_POST_TARGET_REQUIRED']);
        $state = $this->editorial->read($postId);
        if ($state === null) return $this->save($receipt, 'preflight', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'WP_POST_UNAVAILABLE']);
        if ($this->articleMedia !== null) {
            try {
                $mediaContext = is_array($input['media_context'] ?? null) ? $input['media_context'] : ['subject' => $state->title, 'planned_title' => $state->title];
                $selected = is_array($input['article_media']['selected'] ?? null) ? array_map('strval', $input['article_media']['selected']) : [];
                $supporting = is_array($input['article_media']['supporting_media_ids'] ?? null) ? array_values(array_map('strval', $input['article_media']['supporting_media_ids'])) : [];
                $this->mediaDiagnostics = $this->articleMedia->ensureForPost($postId, $mediaContext, $selected, $supporting)->toArray();
            } catch (\Throwable $error) {
                return $this->save($receipt, 'media', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'ARTICLE_MEDIA_COORDINATION_FAILED', 'error' => $error->getMessage()], $state->token);
            }
        }
        if ($receipt->wpStateToken !== null && $receipt->wpStateToken !== $state->token) return $this->save($receipt, 'preflight', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'EDITORIAL_STATE_CHANGED'], $state->token);
        $expectedToken = is_array($input['expected_editorial_state'] ?? null) ? trim((string) ($input['expected_editorial_state']['state_token'] ?? '')) : '';
        if ($expectedToken !== '' && !hash_equals($expectedToken, $state->token)) return $this->save($receipt, 'preflight', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'EXPECTED_EDITORIAL_STATE_MISMATCH'], $state->token);
        $commands = is_array($input['semantic_bundle']['commands'] ?? null) ? $input['semantic_bundle']['commands'] : [];
        $preflight = $this->preflight->check($receipt->wpEndpointKey ?? '', 'reconcile', $commands, (string) ($target['endpoint_type'] ?? 'wp_post'));
        if (!$preflight->accepted) {
            $outcome = in_array('UNSUPPORTED_OPERATION', $preflight->reasons, true) ? ArticleIngestOutcome::UNSUPPORTED_OPERATION : ArticleIngestOutcome::SEMANTIC_PREFLIGHT_REJECTED;
            return $this->save($receipt, 'preflight', $outcome, false, ['reasons' => $preflight->reasons], $state->token);
        }
        if ($receipt->proposalIds === []) {
            try {
                $planned = $this->planner->plan($receipt->operationId, $commands);
            } catch (\InvalidArgumentException $error) {
                return $this->save($receipt, 'preflight', ArticleIngestOutcome::SEMANTIC_PREFLIGHT_REJECTED, false, ['code' => 'ARTICLE_PROPOSAL_PLAN_INVALID', 'error' => $error->getMessage()], $state->token);
            }
            if ($planned === []) {
                if ($this->verification === null) return $this->save($receipt, 'verification', ArticleIngestOutcome::VERIFICATION_FAILED, true, ['code' => 'ARTICLE_VERIFICATION_NOT_WIRED'], $state->token);
                $current = $this->editorial->read($postId);
                if ($current === null) return $this->save($receipt, 'verification', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'WP_POST_UNAVAILABLE'], $state->token);
                $verified = $this->verification->verify($state, $current, [], []);
                return $verified->verified
                    ? $this->save($receipt, 'complete', ArticleIngestOutcome::COMPLETED, false, [], $state->token)
                    : $this->save($receipt, 'verification', ArticleIngestOutcome::VERIFICATION_FAILED, true, ['reasons' => $verified->reasons], $state->token);
            }
            $idsBySlot = [];
            $dependencyMap = [];
            try {
                foreach ($planned as $command) {
                    $actor = function_exists('get_current_user_id') ? (string) get_current_user_id() : '0';
                    $proposal = new Proposal(\NHK\Core\Shared\Uuid\UuidCodec::newV7(), $command->subjectId, $command->operation, $command->payload, $command->contentFingerprint, $command->expectedRevision, $command->dependencyFingerprint, ProposalState::DRAFT, $actor, null, null, $command->idempotencyKey, 1, null, null, $command->targetUuid, $command->entityType);
                    $saved = $this->governance->create($proposal);
                    $idsBySlot[$command->slot] = $saved->id;
                }
            } catch (\Throwable $error) {
                return $this->save($receipt, 'governance', ArticleIngestOutcome::GOVERNANCE_REJECTED, false, ['code' => (string) $error->getCode(), 'error' => $error->getMessage()], $state->token);
            }
            foreach ($planned as $command) {
                $proposalId = $idsBySlot[$command->slot];
                foreach ($command->dependencySlots as $dependencySlot) {
                    if (!$this->dependencies || !isset($idsBySlot[$dependencySlot])) return $this->save($receipt, 'governance', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'DEPENDENCY_REPOSITORY_UNAVAILABLE', 'slot' => $dependencySlot], $state->token, array_values($idsBySlot), [], $dependencyMap);
                    $this->dependencies->add($proposalId, $idsBySlot[$dependencySlot]);
                    $dependencyMap[$proposalId][] = $idsBySlot[$dependencySlot];
                }
                $proposal = $this->proposals->find($proposalId);
                if ($proposal?->state === ProposalState::DRAFT) $this->governance->submit($proposalId);
            }
            $proposalStates = [];
            foreach ($idsBySlot as $proposalId) $proposalStates[$proposalId] = ProposalState::SUBMITTED->value;
            return $this->save($receipt, 'governance', ArticleIngestOutcome::GOVERNANCE_PENDING, true, ['code' => 'APPROVAL_MISSING'], $state->token, array_values($idsBySlot), [], $dependencyMap, $proposalStates);
        }
        $pending = [];
        $applied = $receipt->appliedProposalIds;
        $proposalStates = $receipt->proposalStates;
        $applyAttempts = $receipt->applyAttempts;
        if ($this->apply === null) return $this->save($receipt, 'semantic_apply', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'CONTROLLED_APPLY_UNAVAILABLE'], $state->token, $receipt->proposalIds, $applied, null, $proposalStates, $applyAttempts);
        foreach ($receipt->proposalIds as $proposalId) {
            if (in_array($proposalId, $applied, true)) continue;
            $proposal = $this->proposals->find($proposalId);
            if ($proposal === null) return $this->save($receipt, 'governance', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'PROPOSAL_NOT_FOUND', 'proposal_id' => $proposalId], $state->token, $receipt->proposalIds, $applied, null, $proposalStates, $applyAttempts);
            $proposalStates[$proposalId] = $proposal->state->value;
            if ($proposal->state === ProposalState::REJECTED) return $this->save($receipt, 'governance', ArticleIngestOutcome::GOVERNANCE_REJECTED, false, ['proposal_id' => $proposalId], $state->token, $receipt->proposalIds, $applied, null, $proposalStates, $applyAttempts);
            if ($proposal->state !== ProposalState::APPROVED && $proposal->state !== ProposalState::APPLIED) { $pending[] = $proposalId; continue; }
            if ($proposal->state === ProposalState::APPROVED) {
                try {
                    $result = $this->apply->apply($proposalId);
                    $applied[] = $proposalId;
                    $proposalStates[$proposalId] = ProposalState::APPLIED->value;
                    if (isset($result['attempt_no'])) $applyAttempts[$proposalId] = (int) $result['attempt_no'];
                } catch (\Throwable $error) {
                    $message = strtolower($error->getMessage());
                    $outcome = str_contains($message, 'revision') ? ArticleIngestOutcome::STALE_SEMANTIC_REVISION : ArticleIngestOutcome::SEMANTIC_APPLY_FAILED;
                    return $this->save($receipt, 'semantic_apply', $outcome, true, ['proposal_id' => $proposalId, 'error_code' => (string) $error->getCode(), 'error' => $error->getMessage()], $state->token, $receipt->proposalIds, $applied, null, $proposalStates, $applyAttempts);
                }
            } else {
                $applied[] = $proposalId;
                $proposalStates[$proposalId] = ProposalState::APPLIED->value;
            }
        }
        if ($pending !== []) return $this->save($receipt, 'governance', ArticleIngestOutcome::GOVERNANCE_PENDING, true, ['code' => 'APPROVAL_MISSING', 'proposal_ids' => $pending], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts);
        if ($this->verification === null) return $this->save($receipt, 'verification', ArticleIngestOutcome::VERIFICATION_FAILED, true, ['code' => 'ARTICLE_VERIFICATION_NOT_WIRED'], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts);
        $current = $this->editorial->read($postId);
        if ($current === null) return $this->save($receipt, 'verification', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'WP_POST_UNAVAILABLE'], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts);
        $verified = $this->verification->verify($state, $current, $receipt->proposalIds, array_values(array_unique($applied)));
        return $verified->verified
            ? $this->save($receipt, 'complete', ArticleIngestOutcome::COMPLETED, false, [], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts)
            : $this->save($receipt, 'verification', ArticleIngestOutcome::VERIFICATION_FAILED, true, ['reasons' => $verified->reasons], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts);
    }

    /** @param array<string,mixed> $failure @param list<string> $proposalIds @param list<string> $applied */
    /** @param array<string,list<string>>|null $dependencyMap @param array<string,string>|null $proposalStates @param array<string,int>|null $applyAttempts */
    private function save(ArticleOperationReceipt $receipt, string $stage, ArticleIngestOutcome $outcome, bool $retryable, array $failure, ?string $token = null, array $proposalIds = [], array $applied = [], ?array $dependencyMap = null, ?array $proposalStates = null, ?array $applyAttempts = null): ArticleOperationReceipt
    {
        $diagnostics = $receipt->diagnostics;
        if ($this->mediaDiagnostics !== []) $diagnostics['media'] = $this->mediaDiagnostics;
        $updated = new ArticleOperationReceipt($receipt->operationId, $receipt->idempotencyKey, $receipt->requestFingerprint, $receipt->intent, $receipt->wpEndpointKey, $receipt->wpPostId, $stage, $outcome, $retryable, $proposalIds !== [] ? $proposalIds : $receipt->proposalIds, $applied !== [] ? $applied : $receipt->appliedProposalIds, $failure, $receipt->revision + 1, $receipt->createdAt, $receipt->updatedAt, $token ?? $receipt->wpStateToken, $dependencyMap ?? $receipt->dependencyMap, $proposalStates ?? $receipt->proposalStates, $applyAttempts ?? $receipt->applyAttempts, $diagnostics);
        return $this->receipts->save($updated);
    }
}
