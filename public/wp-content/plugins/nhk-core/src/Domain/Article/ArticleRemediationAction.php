<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

/** A machine-actionable, owner-routed repair decision. */
final readonly class ArticleRemediationAction
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $code,
        public string $owner,
        public string $action,
        public bool $autoRepairSafe,
        public mixed $currentState,
        public mixed $desiredState,
        public array $dependencies = [],
        public string $reason = '',
    ) {
        if ($this->code === '' || $this->owner === '' || $this->action === '') throw new \InvalidArgumentException('Article remediation action identity is required.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return ['code' => $this->code, 'owner' => $this->owner, 'action' => $this->action, 'auto_repair_safe' => $this->autoRepairSafe, 'current_state' => $this->currentState, 'desired_state' => $this->desiredState, 'dependencies' => array_values($this->dependencies), 'reason' => $this->reason]; }
}
