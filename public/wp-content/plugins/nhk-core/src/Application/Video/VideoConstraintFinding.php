<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final readonly class VideoConstraintFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $scope,
        public ?string $claimId,
        public string $repair,
        public string $reason,
    ) {
        VideoConstraintSeverity::assert($severity);
        VideoEditorialAction::assert($repair);
        if (!in_array($scope, ['claim', 'artifact'], true)) throw new \InvalidArgumentException('Video constraint scope is invalid.');
        if ($scope === 'claim' && ($claimId === null || trim($claimId) === '')) throw new \InvalidArgumentException('Claim-local finding requires claim ID.');
        if (trim($code) === '' || trim($reason) === '') throw new \InvalidArgumentException('Video constraint code and reason are required.');
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'scope' => $this->scope, 'claim_id' => $this->claimId, 'repair' => $this->repair, 'reason' => $this->reason];
    }
}
