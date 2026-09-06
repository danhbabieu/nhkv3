<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Presentation-only state stack. Domain lifecycles remain owned by their
 * bounded contexts; these tones exist only to render independent state rows.
 */
final class AdminWorkbenchState
{
    /** @var list<array{label:string,value:string,tone:string}> */
    private array $rows = [];

    /** @param list<array{label?:mixed,value?:mixed,tone?:mixed}> $rows */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label === '' || $value === '') continue;

            $tone = strtolower(trim((string) ($row['tone'] ?? 'neutral')));
            if (!in_array($tone, ['ready', 'attention', 'blocked', 'neutral'], true)) $tone = 'neutral';

            $this->rows[] = ['label' => $label, 'value' => $value, 'tone' => $tone];
        }
    }

    /** @return list<array{label:string,value:string,tone:string}> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function blockerCount(): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['tone'] === 'blocked'));
    }
}
