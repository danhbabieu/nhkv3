<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Governance;
use NHK\Core\Contracts\Governance\ApplyExecutionHook;
final class NoOpApplyExecutionHook implements ApplyExecutionHook { public function afterAttemptStarted():void{} public function afterAuthorityMutation():void{} public function beforeProposalApplied():void{} public function beforeCommit():void{} }
