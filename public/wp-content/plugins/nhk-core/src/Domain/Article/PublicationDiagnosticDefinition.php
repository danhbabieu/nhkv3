<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class PublicationDiagnosticDefinition
{
    public function __construct(
        public string $code,
        public ArticlePublicationOutcome $classification,
        public string $ownerMessage,
        public string $remediationHint,
        public string $policyVersion,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'classification' => $this->classification->value, 'owner_message' => $this->ownerMessage, 'remediation_hint' => $this->remediationHint, 'policy_version' => $this->policyVersion];
    }
}
