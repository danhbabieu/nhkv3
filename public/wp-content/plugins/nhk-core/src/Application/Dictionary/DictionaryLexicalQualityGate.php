<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/**
 * Keeps detector windows lexical without pretending that frequency is proof.
 * The vocabulary is a small Vietnamese grammar boundary, not a domain term list.
 */
final class DictionaryLexicalQualityGate
{
    private const BOUNDARY_WORDS = [
        'mà', 'và', 'hoặc', 'là', 'có', 'cho', 'với', 'của', 'được', 'trong', 'trên',
        'dưới', 'này', 'đó', 'thì', 'khi', 'để', 'từ', 'một', 'những', 'các', 'như',
        'thế', 'nào', 'nằm', 'ở', 'trở', 'nếu', 'vì', 'nên', 'khiến', 'tại', 'bởi',
        'cùng', 'tự', 'thường', 'phổ', 'biến', 'gặp', 'chúng', 'ta', 'họ', 'nó',
        'không', 'đến', 'dùng', 'hiệu', 'hai', 'phần', 'sử', 'theo', 'sau', 'trước', 'vào',
        'cũng', 'khá', 'rất', 'nghe', 'nhìn', 'đặc', 'biệt', 'êm', 'đẹp', 'hay', 'thay', 'cực', 'kỳ',
        'ấn', 'tượng', 'hiếm', 'lực', 'also', 'quite', 'unusual', 'very', 'sounds',
        'beautiful', 'nice', 'impressive', 'rare', 'this', 'that', 'these', 'those',
        'from', 'with', 'for', 'and', 'or', 'to', 'of', 'in', 'on', 'are', 'is', 'was', 'were', 'used',
    ];
    private const BOUNDARY_PHRASES = [
        'thay vì', 'mặc dù', 'bởi vì', 'cho nên', 'vì vậy', 'do đó', 'để mà',
    ];
    private const MODIFIER_PREFIX_WORDS = ['tự'];
    private const LOW_VALUE_FRAGMENT_PATTERNS = [
        '/^(?:đây|tương đối|thuộc tính)$/u',
        '/^nhiều người(?:\s|$)/u',
        '/\byêu thích\b/u',
        '/^(?:giá trị|mức độ)(?:\s+\p{L}+){1,4}$/u',
        '/^cần\s+(?:tách|phân|xác định|lưu ý)(?:\s+\p{L}+){1,4}$/u',
        '/\b(?:luôn|không)\s+đồng nghĩa\b/u',
    ];

    public function isBoundaryWord(string $word): bool
    {
        return in_array($this->word($word), self::BOUNDARY_WORDS, true);
    }

    public function isModifierWord(string $word): bool
    {
        return in_array($this->word($word), self::MODIFIER_PREFIX_WORDS, true);
    }

    public function filter(string $phrase, array $knownLabels = []): ?string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;

        $phrase = $this->isNumericConfiguration($parts)
            ? implode(' ', $parts)
            : $this->trimAtKnownLabelBoundary($parts, $knownLabels);
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;

        foreach ($parts as $index => $part) {
            $word = $this->word($part);
            $nextWord = isset($parts[$index + 1])
                ? $this->word((string) $parts[$index + 1])
                : '';
            if ($nextWord !== '' && in_array($word . ' ' . $nextWord, self::BOUNDARY_PHRASES, true)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
            if ($word !== '' && in_array($word, self::MODIFIER_PREFIX_WORDS, true) && isset($parts[$index + 1])) continue;
            if ($word !== '' && in_array($word, self::BOUNDARY_WORDS, true)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
        }

        while ($parts !== []) {
            $last = $this->word((string) end($parts));
            if ($last === '' || !in_array($last, self::BOUNDARY_WORDS, true)) break;
            array_pop($parts);
        }

        $result = trim(implode(' ', $parts));
        if ($result !== '' && $this->isLowValueFragment($result, $knownLabels)) return null;
        return $result === '' ? null : $result;
    }

    private function isLowValueFragment(string $phrase, array $knownLabels): bool
    {
        $normalized = $this->lower(trim((string) preg_replace('/\s+/u', ' ', $phrase)));
        foreach ($knownLabels as $label) {
            $known = $this->lower(trim((string) preg_replace('/\s+/u', ' ', (string) $label)));
            if ($known !== '' && $known === $normalized) return false;
        }
        foreach (self::LOW_VALUE_FRAGMENT_PATTERNS as $pattern) if (preg_match($pattern, $normalized) === 1) return true;
        return false;
    }

    private function trimAtKnownLabelBoundary(array $parts, array $knownLabels): string
    {
        $normalizedParts = array_map(fn (string $part): string => $this->word($part), $parts);
        $labels = [];
        foreach ($knownLabels as $label) {
            $labelParts = preg_split('/\s+/u', trim((string) $label)) ?: [];
            $labelParts = array_values(array_filter(array_map(fn (string $part): string => $this->word($part), $labelParts)));
            if ($labelParts !== []) $labels[] = $labelParts;
        }
        usort($labels, static fn (array $left, array $right): int => count($right) <=> count($left));

        foreach ($labels as $labelParts) {
            $labelLength = count($labelParts);
            for ($index = 0; $index <= count($normalizedParts) - $labelLength; $index++) {
                if (array_slice($normalizedParts, $index, $labelLength) !== $labelParts) continue;
                if ($index === 0) {
                    if ($labelLength === 1 && isset($parts[$labelLength])) continue;
                    return implode(' ', array_slice($parts, 0, $labelLength));
                }
                return implode(' ', array_slice($parts, 0, $index));
            }
        }

        return implode(' ', $parts);
    }

    private function word(string $part): string
    {
        return $this->lower((string) preg_replace('/[^\p{L}\p{N}\-]/u', '', $part));
    }

    private function isNumericConfiguration(array $parts): bool
    {
        if (count($parts) < 2 || count($parts) % 2 !== 0) return false;
        for ($index = 0; $index < count($parts); $index += 2) {
            if (!preg_match('/^\d{1,3}$/u', $this->word((string) $parts[$index]))) return false;
            if (!preg_match('/^[\p{L}][\p{L}-]*$/u', $this->word((string) $parts[$index + 1]))) return false;
        }
        return true;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
