<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryTermDetector
{
    public function __construct(private ?DictionaryTermNormalizer $normalizer = null)
    {
        $this->normalizer ??= new DictionaryTermNormalizer();
    }

    public function detect(string $text, array $approvedLabels = [], array $hints = []): array
    {
        if (trim($text) === '') return [];
        $out = [];

        foreach ($approvedLabels as $label) $this->addIfPresent($out, $text, (string) $label, 'APPROVED_LABEL', 'STRONG');
        foreach ($hints as $hint) $this->addIfPresent($out, $text, (string) $hint, 'HINT', 'NORMAL');

        if (preg_match_all('/[“"\']([^“”"\']{2,80})[”"\']/u', $text, $quoted)) {
            foreach ($quoted[1] as $value) $this->add($out, (string) $value, 'QUOTED_PHRASE', 'NORMAL');
        }
        if (preg_match_all('/\b[\p{L}]{1,12}[-\/]?\d{1,4}(?:[-\/]\d{1,4})*\b/u', $text, $models)) {
            foreach ($models[0] as $value) $this->add($out, (string) $value, 'TECHNICAL_PATTERN', 'WEAK');
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
        $normalized = $this->normalizer->normalize($term);
        if ($normalized === '' || preg_match('/^\p{P}+$/u', $normalized)) return;
        $out[$normalized] = ['term' => trim($term), 'normalized_term' => $normalized, 'origin' => $origin, 'strength' => $strength];
    }

    private function present(string $text, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu', $text) === 1;
    }
}
