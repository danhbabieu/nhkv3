<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticlePublicationGateResult
{
    /** @param list<string> $blockers @param list<string> $warnings @param list<string> $missingEnrichments @param list<string> $deferredRepairs */
    public function __construct(public bool $eligible, public array $blockers = [], public array $warnings = [], public array $missingEnrichments = [], public array $deferredRepairs = []) {}

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
        return ['eligible' => $this->eligible, 'outcome' => $this->outcome()->value, 'publication_ready' => $this->eligible && $this->outcome() === ArticlePublicationOutcome::PASS, 'enrichment_complete' => $this->missingEnrichments === [] && $this->deferredRepairs === [], 'blockers' => array_values(array_unique($this->blockers)), 'warnings' => array_values(array_unique($this->warnings)), 'missing_enrichments' => array_values(array_unique($this->missingEnrichments)), 'deferred_repairs' => array_values(array_unique($this->deferredRepairs)), 'policy_version' => PublicationDiagnosticRegistry::POLICY_VERSION, 'blocker_fingerprint' => $this->blockerFingerprint()];
    }
}
