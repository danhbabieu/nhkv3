<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final readonly class VideoDecisionTrace
{
    private function __construct(private array $value) {}

    public static function record(array $statement, string $classification, array $support, array $scope, float $confidence, string $action, string $reason): self
    {
        if ($confidence < 0.0 || $confidence > 1.0) throw new \InvalidArgumentException('Video decision confidence is invalid.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Video decision reason is required.');
        return new self([
            'statement' => $statement,
            'classification' => VideoStatementClassification::assert($classification),
            'support' => $support,
            'scope' => $scope,
            'confidence' => $confidence,
            'action' => VideoEditorialAction::assert($action),
            'reason' => $reason,
        ]);
    }

    public static function fromArray(array $value): ?self
    {
        try {
            return self::record(
                is_array($value['statement'] ?? null) ? $value['statement'] : [],
                (string) ($value['classification'] ?? ''),
                is_array($value['support'] ?? null) ? $value['support'] : [],
                is_array($value['scope'] ?? null) ? $value['scope'] : [],
                (float) ($value['confidence'] ?? -1),
                (string) ($value['action'] ?? ''),
                (string) ($value['reason'] ?? ''),
            );
        } catch (\Throwable) { return null; }
    }

    public function toArray(): array { return $this->value; }
}
