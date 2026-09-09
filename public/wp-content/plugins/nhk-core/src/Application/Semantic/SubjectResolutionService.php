<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

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
        foreach (array_values(array_unique(array_filter(array_map('trim', $hints), static fn (string $hint): bool => $hint !== ''))) as $hint) {
            $matches = ($this->resolver)($hint);
            $matches = is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
            if (count($matches) === 1) {
                $item = $matches[0];
                $key = (string) (($item['type'] ?? '') . ':' . ($item['id'] ?? ''));
                if (($item['type'] ?? '') !== '' && ($item['id'] ?? '') !== '' && !isset($resolved[$key])) $resolved[$key] = $item;
            } elseif (count($matches) > 1) {
                $candidates[$hint] = $matches;
            } else {
                $unresolved[] = $hint;
            }
        }
        $status = $candidates !== [] ? 'ambiguous' : ($resolved !== [] ? 'resolved' : 'unresolved');
        return [
            'status' => $status,
            'primary' => array_values($resolved)[0] ?? null,
            'subjects' => array_values($resolved),
            'resolved' => array_values($resolved),
            'candidates' => $candidates,
            'unresolved' => $unresolved,
            'diagnostics' => $candidates !== [] ? ['AMBIGUOUS_SUBJECT_REVIEW'] : ($unresolved !== [] ? ['SUBJECT_NOT_FOUND'] : []),
        ];
    }
}
