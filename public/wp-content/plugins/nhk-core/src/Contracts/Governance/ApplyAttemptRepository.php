<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Governance;
use NHK\Core\Domain\Governance\ApplyAttempt;
interface ApplyAttemptRepository
{
    public function nextAttemptNumberLocked(string $proposalId): int;
    public function createRunning(ApplyAttempt $attempt): ApplyAttempt;
    public function markSucceeded(string $attemptId, ?string $resultEntityUuid): ApplyAttempt;
    public function persistFailed(ApplyAttempt $attempt): ApplyAttempt;
    /** @return list<ApplyAttempt> */ public function findByProposal(string $proposalId): array;
    public function findSuccessful(string $proposalId): ?ApplyAttempt;
}
