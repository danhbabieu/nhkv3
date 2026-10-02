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
        'mà', 'và', 'hoặc', 'là', 'có', 'cho', 'với', 'của', 'được', 'trong', 'trên', 'nhưng', 'về',
        'dưới', 'này', 'đó', 'thì', 'khi', 'để', 'từ', 'một', 'những', 'các', 'như',
        'thế', 'nào', 'nằm', 'ở', 'trở', 'nếu', 'vì', 'nên', 'khiến', 'tại', 'bởi',
        'cùng', 'tự', 'thường', 'phổ', 'biến', 'gặp', 'chúng', 'ta', 'họ', 'nó', 'đây', 'điều', 'đầu', 'tiên', 'luôn', 'riêng', 'nghĩa', 'sự', 'chỉ', 'sẽ', 'ấy', 'đã', 'cả', 'rồi', 'vẫn', 'vậy', 'qua', 'còn', 'lại', 'dụng', 'việc', 'chữ', 'mới', 'hơn', 'gần', 'rằng', 'đáng',
        'không', 'đến', 'dùng', 'hiệu', 'hai', 'phần', 'sử', 'theo', 'sau', 'trước', 'vào',
        'cũng', 'khá', 'rất', 'nghe', 'nhìn', 'đặc', 'biệt', 'êm', 'đẹp', 'hay', 'thay', 'cực', 'kỳ',
        'ấn', 'tượng', 'hiếm', 'lực', 'also', 'quite', 'unusual', 'very', 'sounds',
        'beautiful', 'nice', 'impressive', 'rare', 'this', 'that', 'these', 'those',
        'from', 'with', 'for', 'and', 'or', 'to', 'of', 'in', 'on', 'are', 'is', 'was', 'were', 'used', 'the', 'a', 'i', 'yesterday',
    ];
    private const BOUNDARY_PHRASES = [
        'thay vì', 'mặc dù', 'bởi vì', 'cho nên', 'vì vậy', 'do đó', 'để mà', 'đồng thời',
    ];
    private const MODIFIER_PREFIX_WORDS = ['tự'];
    private const NON_CONTINUATION_BOUNDARIES = ['mà', 'và', 'hoặc', 'là', 'có', 'cho', 'với', 'của', 'được', 'trong', 'trên', 'nhưng', 'về', 'này', 'đó', 'thì'];
    /** Lexical heads that can be grammatical boundary words when followed by a continuation. */
    private const COMPOUND_LEAD_WORDS = ['điều', 'nhận'];
    /** Generic predicate/aspect markers, not article-specific discard phrases. */
    private const PREDICATE_WORDS = [
        'bắt', 'chạy', 'chạm', 'đung', 'đưa', 'đứng', 'đọc', 'đặt', 'gặp',
        'ghi', 'giúp', 'giống', 'giải', 'giữ', 'hoạt', 'khảo', 'kể', 'khiến', 'lên', 'mở', 'nghĩ', 'phân', 'tách', 'tham', 'xác', 'lưu', 'chơi', 'thích', 'thấy', 'biết', 'yên',
        'nhận', 'nhìn', 'nói', 'quay', 'sống', 'suy', 'tạo', 'tiếp', 'tinh', 'xoay', 'sang', 'ra', 'đời', 'xuống',
        'tồn', 'tránh', 'trở', 'xem', 'yêu', 'đánh', 'đi', 'đến', 'dùng', 'bảo', 'truyền', 'động', 'mô', 'tả',
        'dễ', 'hãy', 'đừng', 'phải', 'muốn',
    ];
    private const NON_LEXICAL_SINGLE_WORDS = [
        'bác', 'bài', 'cả', 'các', 'câu', 'chẳng', 'chúng', 'đây', 'điều', 'độ', 'giá', 'họ', 'khách', 'một', 'tên',
        'người', 'nó', 'những', 'sự', 'ta', 'thể', 'vậy', 'vì', 'với', 'cần',
        'mức', 'phần', 'trang', 'thuộc', 'tính', 'đồng', 'dòng', 'lặng', 'việc', 'bên', 'ông', 'gần', 'rằng', 'tưởng', 'chính', 'chiếc', 'dấu', 'rộng', 'ngay', 'xuyên', 'lâu', 'động', 'khu',
    ];

    public function isBoundaryWord(string $word): bool
    {
        return in_array($this->word($word), self::BOUNDARY_WORDS, true);
    }

    public function isModifierWord(string $word): bool
    {
        return in_array($this->word($word), self::MODIFIER_PREFIX_WORDS, true);
    }

    public function isPredicateWord(string $word): bool
    {
        return in_array($this->word($word), self::PREDICATE_WORDS, true);
    }

    public function isPredicateBoundary(string $word, string $nextWord = ''): bool
    {
        $normalized = $this->word($word);
        if ($normalized === 'truyền') return true;
        return $this->isPredicateWord($normalized);
    }

    public function isLexicalContinuationBoundary(string $word, string $nextWord, int $remainingTokens, string $nextAfter = ''): bool
    {
        if ($remainingTokens !== 1 && !$this->isBoundaryWord($nextAfter)) return false;
        $word = $this->word($word);
        $nextWord = $this->word($nextWord);
        if ($word === '' || $nextWord === '') return false;
        if (in_array($word, self::NON_CONTINUATION_BOUNDARIES, true)) return false;
        if ($word === 'truyền') return true;
        if (!$this->isLexicalContinuation($nextWord) && !($this->isBoundaryWord($word) && $this->isBoundaryWord($nextWord))) return false;
        return $this->isPredicateBoundary($word, $nextWord)
            || ($word === 'từ' && $this->isBoundaryWord($nextWord));
    }

    public function isLexicalContinuationTail(string $previousWord, string $word): bool
    {
        if ($this->isPredicateWord($word) && ($this->word($previousWord) === 'truyền' || $this->isModifierWord($previousWord))) return true;
        if ($this->word($previousWord) === 'truyền' && $this->isBoundaryWord($word)) return true;
        return $this->word($previousWord) === 'từ'
            && !in_array($this->word($word), self::NON_CONTINUATION_BOUNDARIES, true)
            && $this->isBoundaryWord($word);
    }

    public function isTechnicalCompound(string $phrase): bool
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (count($parts) < 2 || count($parts) > 6) return false;
        $normalized = array_map(fn (string $part): string => $this->word($part), $parts);
        if (in_array('mô', $normalized, true) || in_array('tả', $normalized, true)) return false;
        foreach ($normalized as $index => $word) {
            $next = $normalized[$index + 1] ?? '';
            $remaining = count($normalized) - $index - 1;
            if ($index === count($normalized) - 1 && $index > 0 && $this->isBoundaryWord($word) && ($this->isBoundaryWord($normalized[$index - 1]) || $this->isPredicateWord($normalized[$index - 1]) || $this->isModifierWord($normalized[$index - 1]))) continue;
            if ($this->isModifierWord($word)) continue;
            if ($this->isBoundaryWord($word) && !$this->isCompoundLead($word, $next) && !$this->isLexicalContinuationBoundary($word, $next, $remaining)) return false;
            if ($this->isPredicateWord($word) && !($this->word($normalized[$index - 1] ?? '') === 'truyền' || $this->isModifierWord($normalized[$index - 1] ?? '')) && !$this->isLexicalContinuationBoundary($word, $next, $remaining)) return false;
        }
        $asciiCompound = count($normalized) === 2
            && preg_match('/^[a-z][a-z-]*$/', (string) $normalized[0]) === 1
            && preg_match('/^[a-z][a-z-]*$/', (string) $normalized[1]) === 1;
        return count($normalized) >= 3 || $this->isCompoundLead((string) ($normalized[0] ?? ''), (string) ($normalized[1] ?? ''))
            || $asciiCompound
            || in_array((string) ($normalized[0] ?? ''), ['bộ', 'cụm', 'hệ', 'van', 'côn', 'màng', 'trục', 'cần', 'mặt', 'đồng'], true);
    }

    public function isWeakDiscourseBoundary(string $word): bool
    {
        return in_array($this->word($word), ['ấy', 'đã', 'rồi', 'vẫn', 'còn', 'vậy', 'chính', 'lại', 'sẽ', 'hơn', 'gần'], true);
    }

    public function isDiscourseStart(string $word): bool
    {
        return in_array($this->word($word), ['bác', 'chưa', 'nhầm', 'sao', 'tới'], true);
    }

    public function isBoundaryPhrase(string $word, string $nextWord): bool
    {
        return in_array($this->word($word) . ' ' . $this->word($nextWord), self::BOUNDARY_PHRASES, true);
    }

    public function isNonLexicalSingleWord(string $word): bool
    {
        return in_array($this->word($word), self::NON_LEXICAL_SINGLE_WORDS, true);
    }

    public function isCompoundLead(string $word, string $nextWord = ''): bool
    {
        $normalized = $this->word($word);
        return $nextWord !== ''
            && $this->isLexicalContinuation($nextWord)
            && in_array($normalized, self::COMPOUND_LEAD_WORDS, true);
    }

    public function isLexicalContinuation(string $word): bool
    {
        $normalized = $this->word($word);
        return $normalized !== ''
            && preg_match('/^[\p{L}][\p{L}-]*$/u', $normalized) === 1
            && !$this->isBoundaryWord($normalized)
            && !$this->isPredicateWord($normalized);
    }

    public function isStandaloneLexicalWord(string $word): bool
    {
        $normalized = $this->word($word);
        return $normalized !== ''
            && preg_match('/^[\p{L}][\p{L}-]{2,}$/u', $normalized) === 1
            && !$this->isBoundaryWord($normalized)
            && !$this->isPredicateWord($normalized)
            && !in_array($normalized, self::NON_LEXICAL_SINGLE_WORDS, true);
    }

    public function filter(string $phrase, array $knownLabels = []): ?string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;
        if ($this->isDiscourseStart((string) ($parts[0] ?? ''))) return null;
        $normalizedInput = $this->lower(trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts))));
        foreach ($knownLabels as $knownLabel) {
            $normalizedKnown = $this->lower(trim((string) preg_replace('/\s+/u', ' ', (string) $knownLabel)));
            if ($normalizedKnown !== '' && $normalizedKnown === $normalizedInput) return implode(' ', $parts);
        }
        if (count($parts) >= 2 && $this->isCompoundLead((string) $parts[0], (string) $parts[1])) {
            return implode(' ', $parts);
        }

        $phrase = $this->isNumericConfiguration($parts)
            ? implode(' ', $parts)
            : $this->trimAtKnownLabelBoundary($parts, $knownLabels);
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;

        $compoundPrefixLength = count($parts) >= 2 && $this->isCompoundLead((string) $parts[0], (string) $parts[1]) ? 2 : 0;
        foreach ($parts as $index => $part) {
            if ($index < $compoundPrefixLength) continue;
            $word = $this->word($part);
            $nextWord = isset($parts[$index + 1])
                ? $this->word((string) $parts[$index + 1])
                : '';
            $nextAfterWord = isset($parts[$index + 2]) ? $this->word((string) $parts[$index + 2]) : '';
            $previousWord = $index > 0 ? $this->word((string) $parts[$index - 1]) : '';
            if ($nextWord !== '' && in_array($word . ' ' . $nextWord, self::BOUNDARY_PHRASES, true)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
            if ($this->isCompoundLead($word, $nextWord)) continue;
            if ($word === 'từ' && $nextWord === 'trở') continue;
            // “từ trở” is a lexical technical construction; “trở” is only a
            // clause predicate when it is not attached to that construction.
            if ($word === 'trở' && $previousWord === 'từ') continue;
            if ($word === 'cần' && $this->isPredicateWord($nextWord)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
            if ($this->isLexicalContinuationBoundary($word, $nextWord, count($parts) - $index - 1, $nextAfterWord)) continue;
            if ($this->isLexicalContinuationTail($previousWord, $word)) continue;
            if ($index === count($parts) - 1 && $this->isBoundaryWord($word) && ($this->isBoundaryWord($previousWord) || $this->isPredicateWord($previousWord) || $this->isModifierWord($previousWord))) continue;
            if ($word !== '' && $this->isPredicateBoundary($word, $nextWord)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
            if ($word !== '' && in_array($word, self::MODIFIER_PREFIX_WORDS, true) && isset($parts[$index + 1])) continue;
            if ($word !== '' && in_array($word, self::BOUNDARY_WORDS, true)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
            if ($word !== '' && $this->isDiscourseStart($word)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
        }

        while ($parts !== []) {
            $last = $this->word((string) end($parts));
            $previous = count($parts) > 1 ? $this->word((string) $parts[count($parts) - 2]) : '';
            if ($last === 'trở' && count($parts) > 1 && $this->word((string) $parts[count($parts) - 2]) === 'từ') break;
            if ($this->isBoundaryWord($last) && ($this->isBoundaryWord($previous) || $this->isPredicateWord($previous))) break;
            if ($last === '' || !in_array($last, self::BOUNDARY_WORDS, true)) break;
            array_pop($parts);
        }

        if ($parts !== [] && $this->word((string) $parts[0]) === 'diện' && isset($parts[1]) && preg_match('/^\p{Lu}/u', (string) $parts[1]) === 1) return null;
        if ($parts !== [] && in_array($this->word((string) $parts[0]), ['bài', 'giá', 'mức', 'nhiều', 'tương', 'thuộc', 'người', 'số'], true)) return null;
        $result = trim(implode(' ', $parts));
        return $result === '' ? null : $result;
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
