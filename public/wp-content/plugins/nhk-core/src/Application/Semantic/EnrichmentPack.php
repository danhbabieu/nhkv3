<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Immutable transient result shared by owner/source adapters. */
final readonly class EnrichmentPack
{
    private const BRANCHES = ['content', 'relations', 'knowledge'];

    /** @param array<string,array<string,mixed>> $branches */
    private function __construct(private array $branches)
    {
    }

    /** @param array<string,mixed> $branches */
    public static function fromBranches(array $branches): self
    {
        $unknown = array_diff(array_keys($branches), self::BRANCHES);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown enrichment branch: ' . implode(', ', $unknown));
        }

        $normalized = [];
        foreach (self::BRANCHES as $branch) {
            $value = $branches[$branch] ?? [];
            if (!is_array($value)) throw new \InvalidArgumentException('Enrichment branch must be an array: ' . $branch);
            if (!array_key_exists('status', $value)) throw new \InvalidArgumentException('Enrichment branch status is required: ' . $branch);
            $normalized[$branch] = $value + [
                'diagnostics' => [],
                'readiness' => ['status' => (string) $value['status']],
            ];
        }
        return new self($normalized);
    }

    /** @return array<string,array<string,mixed>> */
    public function toArray(): array
    {
        return $this->branches;
    }
}
