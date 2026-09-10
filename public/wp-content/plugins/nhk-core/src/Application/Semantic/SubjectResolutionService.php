<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Canonical subject resolver adapter. Ambiguity and absence remain explicit. */
final class SubjectResolutionService
{
    /** @param callable(string):array $resolver */
    public function __construct(private $resolver) {}

    /** @param list<string> $hints @return array<string,mixed> */
    public function resolve(array $hints): array
    {
        $resolved = [];
        $candidates = [];
        $unresolved = [];
        $hints = array_values(array_unique(array_filter(array_map('trim', $hints), static fn (string $hint): bool => $hint !== '')));
        $explicitUuid = array_values(array_filter($hints, static fn (string $hint): bool => UuidCodec::isValid($hint)));
        if ($explicitUuid !== []) $hints = [$explicitUuid[0]];
        foreach ($hints as $hint) {
            $matches = ($this->resolver)($hint);
            $matches = is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
            if (count($matches) === 1) {
                $item = $matches[0];
                $key = (string) (($item['type'] ?? '') . ':' . ($item['id'] ?? ''));
                if (($item['type'] ?? '') !== '' && ($item['id'] ?? '') !== '') {
                    if (!isset($resolved[$key]) || $this->matchRank($item) > $this->matchRank($resolved[$key])) $resolved[$key] = $item;
                }
            } elseif (count($matches) > 1) {
                $candidates[$hint] = $matches;
            } else {
                $unresolved[] = $hint;
            }
        }
        $ordered = array_values($resolved);
        usort($ordered, function (array $left, array $right): int {
            $score = $this->primaryRank($right) <=> $this->primaryRank($left);
            return $score !== 0 ? $score : strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });
        $status = $candidates !== [] ? 'ambiguous' : ($ordered !== [] ? 'resolved' : 'unresolved');
        return [
            'status' => $status,
            'primary' => $ordered[0] ?? null,
            'subjects' => $ordered,
            'resolved' => $ordered,
            'candidates' => $candidates,
            'unresolved' => $unresolved,
            'diagnostics' => $candidates !== [] ? ['AMBIGUOUS_SUBJECT_REVIEW'] : ($unresolved !== [] ? ['SUBJECT_NOT_FOUND'] : []),
        ];
    }

    private function primaryRank(array $subject): int
    {
        $matchRank = match ((string) ($subject['match'] ?? '')) {
            'uuid_exact' => 10000,
            'stable_key_exact' => 9000,
            'exact_variant_reference', 'exact_variant_name_reference' => 1200,
            'exact_name_or_alias' => 1000,
            default => 0,
        };
        $typeRank = match ((string) ($subject['type'] ?? '')) {
            'specimen' => 500,
            'variant' => 400,
            'model' => 300,
            'movement' => 200,
            'brand' => 100,
            default => 0,
        };
        return $matchRank + $typeRank;
    }

    private function matchRank(array $subject): int
    {
        return match ((string) ($subject['match'] ?? '')) {
            'uuid_exact' => 3,
            'stable_key_exact' => 2,
            default => 1,
        };
    }
}
