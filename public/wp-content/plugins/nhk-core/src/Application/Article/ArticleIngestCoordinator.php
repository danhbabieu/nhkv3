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
use NHK\Core\Contracts\WordPress\EditorialPostStore;

final class ArticleIngestCoordinator
{
    private const MAX_PROPOSALS_PER_OPERATION = 100;
    private const MAX_APPLIES_PER_REQUEST = 25;
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
        private ?EditorialPostStore $editorialStore = null,
    ) {}

    /** @var array<string,mixed> */
    private array $mediaDiagnostics = [];
    /** @var array<string,int> */
    private array $timings = [];
    private float $startedAt = 0.0;

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
        $started = microtime(true);
        $this->startedAt = $started;
        $this->timings = [];
        $this->mediaDiagnostics = [];
        if (!$this->preflight || !$this->planner || !$this->editorial || !$this->governance || !$this->proposals) return $this->save($receipt, 'preflight', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'ARTICLE_COORDINATOR_DEPENDENCIES_NOT_WIRED']);
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $postId = $receipt->wpPostId;
        if ($postId === null) return $this->save($receipt, 'preflight', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'WP_POST_TARGET_REQUIRED']);
        $state = $this->editorial->read($postId);
        $this->timings['editorial_state_read_ms'] = $this->elapsed($started);
        if ($state === null) return $this->save($receipt, 'preflight', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'WP_POST_UNAVAILABLE']);
        // A prior bounded phase (notably MediaUsage/editorial placement) may
        // have advanced the native token. Refresh and continue from the
        // canonical Post; only a caller-supplied expected token is a hard CAS.
        $expectedToken = is_array($input['expected_editorial_state'] ?? null) ? trim((string) ($input['expected_editorial_state']['state_token'] ?? '')) : '';
        $editorialUpdate = is_array($input['editorial_update'] ?? null) ? $input['editorial_update'] : [];
        $previousUpdate = is_array($receipt->diagnostics['media']['editorial_update'] ?? null)
            ? $receipt->diagnostics['media']['editorial_update'] : [];
        $requestedEditorialFields = $this->editorialFields($editorialUpdate);
        $resumeMatchesNativeDelta = $requestedEditorialFields !== []
            && (string) ($previousUpdate['fields_fingerprint'] ?? '') !== ''
            && hash_equals((string) $previousUpdate['fields_fingerprint'], $this->editorialFingerprint($state, $requestedEditorialFields));
        if ($expectedToken !== '' && !hash_equals($expectedToken, $state->token) && !$resumeMatchesNativeDelta) return $this->save($receipt, 'preflight', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'EXPECTED_EDITORIAL_STATE_MISMATCH'], $state->token);
        if ($editorialUpdate !== []) {
            if ($this->editorialStore === null) return $this->save($receipt, 'editorial_update', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'EDITORIAL_STORE_UNAVAILABLE'], $state->token);
            $alreadyApplied = is_array($previousUpdate)
                && ($resumeMatchesNativeDelta || hash_equals((string) ($previousUpdate['state_token'] ?? ''), $state->token));
            if (!$alreadyApplied) {
                if ($expectedToken === '' || !hash_equals($expectedToken, $state->token)) return $this->save($receipt, 'editorial_update', ArticleIngestOutcome::RECONCILIATION_CONFLICT, false, ['code' => 'EDITORIAL_CAS_REQUIRED'], $state->token);
                $fields = $this->editorialFields($editorialUpdate);
                if ($fields !== []) {
                    try { $state = $this->editorialStore->update($postId, $fields); }
                    catch (\Throwable $error) { return $this->save($receipt, 'editorial_update', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'EDITORIAL_UPDATE_FAILED', 'error' => $error->getMessage()], $state->token); }
                    $this->mediaDiagnostics['editorial_update'] = ['status' => 'APPLIED', 'state_token' => $state->token, 'post_id' => $state->postId, 'fields_fingerprint' => $this->editorialFingerprint($state, $fields)];
                }
            }
        }
        if ($this->articleMedia !== null) {
            try {
                $mediaContext = is_array($input['media_context'] ?? null) ? $input['media_context'] : ['subject' => $state->title, 'planned_title' => $state->title];
                $selected = is_array($input['article_media']['selected'] ?? null) ? array_map('strval', $input['article_media']['selected']) : [];
                $supporting = is_array($input['article_media']['supporting_media_ids'] ?? null) ? array_values(array_map('strval', $input['article_media']['supporting_media_ids'])) : [];
                $this->mediaDiagnostics = $this->articleMedia->ensureForPost($postId, $mediaContext, $selected, $supporting)->toArray();
                $this->timings['media_reconciliation_ms'] = $this->elapsed($started);
            } catch (\Throwable $error) {
                $conflict = str_contains(strtoupper($error->getMessage()), 'EDITORIAL_STATE_CHANGED');
                return $this->save($receipt, 'media', $conflict ? ArticleIngestOutcome::RECONCILIATION_CONFLICT : ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => $conflict ? 'EDITORIAL_STATE_CHANGED' : 'ARTICLE_MEDIA_COORDINATION_FAILED', 'error' => $error->getMessage()], $state->token);
            }
            $state = $this->editorial->read($postId);
            if ($state === null) return $this->save($receipt, 'media', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, ['code' => 'WP_POST_UNAVAILABLE']);
        }
        $commands = is_array($input['semantic_bundle']['commands'] ?? null) ? $input['semantic_bundle']['commands'] : [];
        $preflight = $this->preflight->check($receipt->wpEndpointKey ?? '', 'reconcile', $commands, (string) ($target['endpoint_type'] ?? 'wp_post'));
        $this->timings['semantic_preflight_ms'] = $this->elapsed($started);
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
            $this->timings['relation_plan_ms'] = $this->elapsed($started);
            if (count($planned) > self::MAX_PROPOSALS_PER_OPERATION) {
                return $this->save($receipt, 'preflight', ArticleIngestOutcome::SEMANTIC_PREFLIGHT_REJECTED, true, ['code' => 'ARTICLE_SEMANTIC_PLAN_LIMIT', 'limit' => self::MAX_PROPOSALS_PER_OPERATION, 'planned' => count($planned)], $state->token);
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
        $appliedThisRun = 0;
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
                    $appliedThisRun++;
                    $proposalStates[$proposalId] = ProposalState::APPLIED->value;
                    if (isset($result['attempt_no'])) $applyAttempts[$proposalId] = (int) $result['attempt_no'];
                    if ($appliedThisRun >= self::MAX_APPLIES_PER_REQUEST) {
                        $this->timings['semantic_apply_ms'] = $this->elapsed($started);
                        return $this->save($receipt, 'semantic_apply', ArticleIngestOutcome::GOVERNANCE_PENDING, true, ['code' => 'ARTICLE_APPLY_BATCH_LIMIT_REACHED', 'limit' => self::MAX_APPLIES_PER_REQUEST], $state->token, $receipt->proposalIds, array_values(array_unique($applied)), null, $proposalStates, $applyAttempts);
                    }
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
        $this->timings['verification_ms'] = $this->elapsed($started);
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
        if ($this->timings !== []) $diagnostics['timings_ms'] = $this->timings + ['total_ms' => $this->startedAt > 0 ? $this->elapsed($this->startedAt) : 0];
        $updated = new ArticleOperationReceipt($receipt->operationId, $receipt->idempotencyKey, $receipt->requestFingerprint, $receipt->intent, $receipt->wpEndpointKey, $receipt->wpPostId, $stage, $outcome, $retryable, $proposalIds !== [] ? $proposalIds : $receipt->proposalIds, $applied !== [] ? $applied : $receipt->appliedProposalIds, $failure, $receipt->revision + 1, $receipt->createdAt, $receipt->updatedAt, $token ?? $receipt->wpStateToken, $dependencyMap ?? $receipt->dependencyMap, $proposalStates ?? $receipt->proposalStates, $applyAttempts ?? $receipt->applyAttempts, $diagnostics);
        return $this->receipts->save($updated);
    }

    private function elapsed(float $started): int { return (int) round((microtime(true) - $started) * 1000); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function editorialFields(array $input): array
    {
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : $input;
        $allowed = ['post_title', 'post_content', 'post_excerpt', 'post_name'];
        $result = [];
        foreach ($allowed as $field) if (array_key_exists($field, $fields)) $result[$field] = (string) $fields[$field];
        return $result;
    }

    /** @param array<string,mixed> $fields */
    private function editorialFingerprint(\NHK\Core\Domain\Article\EditorialPostState $state, array $fields): string
    {
        $values = [];
        foreach (['post_title' => $state->title, 'post_content' => $state->content, 'post_excerpt' => $state->excerpt, 'post_name' => $state->slug] as $field => $current) {
            if (array_key_exists($field, $fields)) $values[$field] = (string) $fields[$field];
        }
        return hash('sha256', CommandCanonicalizer::canonicalize($values));
    }
}
