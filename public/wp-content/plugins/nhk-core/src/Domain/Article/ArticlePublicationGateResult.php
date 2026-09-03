<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticlePublicationGateResult
{
    /** @param list<string> $blockers @param list<string> $warnings */
    public function __construct(public bool $eligible, public array $blockers = [], public array $warnings = []) {}

    public function outcome(): ArticlePublicationOutcome
    {
        return PublicationDiagnosticRegistry::classify($this->blockers);
    }

    public function blockerFingerprint(string $policyVersion = PublicationDiagnosticRegistry::POLICY_VERSION): string
    {
        return $policyVersion === PublicationDiagnosticRegistry::POLICY_VERSION ? PublicationDiagnosticRegistry::fingerprint($this->blockers) : hash('sha256', $policyVersion . ':' . implode('|', $this->blockers));
    }

    /** @return array{eligible:bool,blockers:list<string>,warnings:list<string>} */
    public function toArray(): array
    {
        return ['eligible' => $this->eligible, 'outcome' => $this->outcome()->value, 'blockers' => array_values(array_unique($this->blockers)), 'warnings' => array_values(array_unique($this->warnings)), 'policy_version' => PublicationDiagnosticRegistry::POLICY_VERSION, 'blocker_fingerprint' => $this->blockerFingerprint()];
    }
}
