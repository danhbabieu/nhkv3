<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/**
 * Conservative interpreter for an explicitly ordered multi-image sentence.
 * It returns no mapping when the number of unambiguous descriptions differs
 * from the number of files; the caller must then retain only batch context.
 */
final class OrderedMediaDescriptionParser
{
    /** @return list<string>|null */
    public function map(string $text, int $count): ?array
    {
        if ($count < 2) return null;
        $trimmed = trim($text);
        if (preg_match_all('/(?:^|\n)\s*Ảnh\s*(\d+)\s*:\s*(.+?)(?=\n\s*Ảnh\s*\d+\s*:|$)/iu', $trimmed, $numbered, PREG_SET_ORDER)) {
            $mapped = array_fill(0, $count, '');
            foreach ($numbered as $match) {
                $ordinal = (int) ($match[1] ?? 0) - 1;
                if ($ordinal < 0 || $ordinal >= $count || $mapped[$ordinal] !== '') return null;
                $mapped[$ordinal] = trim((string) ($match[2] ?? ''), " \t\n\r.,;:!");
            }
            return in_array('', $mapped, true) ? null : $mapped;
        }
        if (!preg_match('/\blần\s+lượt\s+(?:là\s+)?(.+)/iu', $trimmed, $matches)) return null;
        $body = trim((string) ($matches[1] ?? ''), " \t\n\r.,;:!");
        if ($body === '') return null;
        $parts = preg_split('/\s*(?:,|;|\n|\s+và\s+)\s*/iu', $body, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) !== $count) return null;
        $parts = array_values(array_map(static fn (string $part): string => trim($part, " \t\n\r.,;:!"), $parts));
        return in_array('', $parts, true) ? null : $parts;
    }
}
