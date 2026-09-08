<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

use InvalidArgumentException;

final readonly class ClaimProjectionScope
{
    public const DIRECT = 'direct';
    public const RELATED = 'related';

    /** @param list<array<string,mixed>> $graphPath */
    public function __construct(
        public string $nodeUuid,
        public string $canonicalSubjectUuid,
        public string $scope = self::DIRECT,
        public int $graphDistance = 0,
        public array $graphPath = [],
    ) {
        if (trim($nodeUuid) === '' || trim($canonicalSubjectUuid) === '') throw new InvalidArgumentException('Projection scope identity is required.');
        if (!in_array($scope, [self::DIRECT, self::RELATED], true)) throw new InvalidArgumentException('Projection scope is invalid.');
        if ($graphDistance < 0 || $graphDistance > 2 || ($scope === self::DIRECT && $graphDistance !== 0) || ($scope === self::RELATED && $graphDistance < 1)) throw new InvalidArgumentException('Projection graph distance is invalid.');
    }

    public function isDirect(): bool { return $this->scope === self::DIRECT; }
}
