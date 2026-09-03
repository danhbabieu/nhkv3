<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PublicIdentity;

final readonly class PublicIdentityMutationResult
{
    public const MALFORMED_OWNER = 'MALFORMED_OWNER';
    public const UNKNOWN_ROUTE = 'UNKNOWN_ROUTE';
    public const CONFLICT = 'CONFLICT';
    public const STALE_REVISION = 'STALE_REVISION';
    public const UNAVAILABLE_STORAGE = 'UNAVAILABLE_STORAGE';
    public const AMBIGUOUS_HISTORY = 'AMBIGUOUS_HISTORY';

    private function __construct(
        public bool $accepted,
        public ?string $code = null,
        public ?PublicIdentity $identity = null,
        public ?HistoricPublicRoute $historicRoute = null,
    ) {}

    public static function accepted(PublicIdentity $identity, ?HistoricPublicRoute $historicRoute = null): self
    {
        return new self(true, null, $identity, $historicRoute);
    }

    public static function rejected(string $code): self
    {
        if (!in_array($code, self::codes(), true)) {
            throw new \InvalidArgumentException('Public identity result code is invalid.');
        }

        return new self(false, $code);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::MALFORMED_OWNER,
            self::UNKNOWN_ROUTE,
            self::CONFLICT,
            self::STALE_REVISION,
            self::UNAVAILABLE_STORAGE,
            self::AMBIGUOUS_HISTORY,
        ];
    }
}
