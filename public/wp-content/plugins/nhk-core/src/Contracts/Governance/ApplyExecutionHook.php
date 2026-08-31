<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Governance;
interface ApplyExecutionHook { public function afterAttemptStarted(): void; public function afterAuthorityMutation(): void; public function beforeProposalApplied(): void; public function beforeCommit(): void; }
