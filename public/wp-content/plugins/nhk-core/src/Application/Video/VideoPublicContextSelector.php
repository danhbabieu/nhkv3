<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoPublicContextSelector
{
    /** @var list<string> */
    private const SOURCES = ['variant', 'model', 'brand', 'music', 'editorial_context', 'user_hint'];

    /** @param array<string,mixed> $context @return array{source:string,value:string}|null */
    public function select(array $context): ?array
    {
        $governed = is_array($context['governed_context'] ?? null) ? $context['governed_context'] : [];
        foreach (self::SOURCES as $source) {
            $value = $this->value($context[$source] ?? $governed[$source] ?? null);
            if ($value !== '') return ['source' => $source, 'value' => $value];
        }
        return null;
    }

    private function value(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['canonical_name', 'name', 'title', 'value'] as $key) {
                if (isset($value[$key]) && trim((string) $value[$key]) !== '') return trim((string) $value[$key]);
            }
            return '';
        }
        return is_string($value) ? trim($value) : '';
    }
}
