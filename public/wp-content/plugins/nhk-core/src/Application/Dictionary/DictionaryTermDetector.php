<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryTermDetector
{
    private const STOP_WORDS = ['và','hoặc','là','có','cho','với','của','được','trong','trên','dưới','này','đó','thì','khi','để','từ','một','những','các'];

    public function __construct(private ?DictionaryTermNormalizer $normalizer = null)
    {
        $this->normalizer ??= new DictionaryTermNormalizer();
    }

    public function detect(string $text, array $approvedLabels = [], array $hints = []): array
    {
        if (trim($text) === '') return [];
        $out = [];

        foreach ($approvedLabels as $label) $this->addIfPresent($out, $text, (string) $label, 'KNOWN_LABEL', 'STRONG');
        foreach ($hints as $hint) $this->addIfPresent($out, $text, (string) $hint, 'HINT', 'NORMAL');

        if (preg_match_all('/[“"\']([^“”"\']{2,80})[”"\']/u', $text, $quoted)) {
            foreach ($quoted[1] as $value) $this->add($out, (string) $value, 'QUOTED_PHRASE', 'NORMAL');
        }
        if (preg_match_all('/\b[\p{L}]{1,12}[-\/]?\d{1,4}(?:[-\/]\d{1,4})*\b/u', $text, $models)) {
            foreach ($models[0] as $value) $this->add($out, (string) $value, 'TECHNICAL_PATTERN', 'WEAK');
        }

        $word = '[\p{L}\p{N}][\p{L}\p{N}\-]*';
        $patterns = [
            '/\b(côn(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(ngắt\s+chuông(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(điểm\s+(?:giờ|chuông)(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(quả\s+lắc(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(dây\s+tóc(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(khóa\s+ngựa(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(bánh\s+thoát(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(bộ\s+thoát(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(hộp\s+cộng\s+hưởng(?:\s+' . $word . '){0,2})\b/iu',
            '/\b(mặt\s+số(?:\s+' . $word . '){1,3})\b/iu',
            '/\b(gông(?:\s+' . $word . '){1,3})\b/iu',
            '/\b(búa(?:\s+' . $word . '){1,2})\b/iu',
            '/\b(cọc(?:\s+' . $word . '){1,2})\b/iu',
            '/\b(vách(?:\s+' . $word . '){1,3})\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches)) continue;
            foreach ($matches[1] as $value) {
                $phrase = $this->trimStopWords((string) $value);
                if ($phrase !== '') $this->add($out, $phrase, 'DOMAIN_PHRASE', 'NORMAL');
            }
        }

        if (preg_match_all('/\bbản\s+nhạc\s+(' . $word . '(?:\s+' . $word . '){0,3})\b/iu', $text, $music)) {
            foreach ($music[1] as $value) {
                $phrase = $this->trimStopWords((string) $value);
                if ($phrase !== '') $this->add($out, $phrase, 'MUSIC_NAME', 'NORMAL');
            }
        }

        return array_values($out);
    }

    private function addIfPresent(array &$out, string $text, string $term, string $origin, string $strength): void
    {
        $term = trim($term);
        if ($term === '' || !$this->present($text, $term)) return;
        $this->add($out, $term, $origin, $strength);
    }

    private function add(array &$out, string $term, string $origin, string $strength): void
    {
        $term = trim($term, " \t\n\r\0\x0B,.;:!?()[]{}\"");
        $normalized = $this->normalizer->normalize($term);
        if ($normalized === '' || preg_match('/^\p{P}+$/u', $normalized)) return;
        $out[$normalized] = ['term' => $term, 'normalized_term' => $normalized, 'origin' => $origin, 'strength' => $strength];
    }

    private function present(string $text, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu', $text) === 1;
    }

    private function trimStopWords(string $phrase): string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        while ($parts !== []) {
            $last = $this->lower((string) end($parts));
            if (!in_array($last, self::STOP_WORDS, true)) break;
            array_pop($parts);
        }
        return trim(implode(' ', $parts));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
