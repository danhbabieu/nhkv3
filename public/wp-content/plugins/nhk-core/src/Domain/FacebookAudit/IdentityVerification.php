<?php
declare(strict_types=1);

namespace NHK\Core\Domain\FacebookAudit;

final readonly class IdentityVerification
{
    private function __construct(
        public bool $verified,
        public ?string $pageId,
        public ?string $pageName,
        public ?string $canonicalUrl,
        public ?string $reason,
    ) {}

    public static function verified(string $pageId, string $pageName, string $canonicalUrl): self
    {
        return new self(true, trim($pageId), trim($pageName), trim($canonicalUrl), null);
    }

    public static function unverified(string $reason): self
    {
        return new self(false, null, null, null, trim($reason));
    }
}
