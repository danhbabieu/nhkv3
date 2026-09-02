<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

/**
 * Keeps confirmed public brand aliases on the single approved display spelling.
 * This is a presentation policy; it does not mutate editorial or semantic data.
 */
final class PublicBrandNamePolicy
{
    /** @var array<string, string> */
    private const ALIASES = [
        '/(?<![\p{L}\p{N}])(?:ô[\s-]*đ[oô]|o[\s-]*do|odo)(?![\p{L}\p{N}])/iu' => 'Odo',
        '/(?<![\p{L}\p{N}])(?:vê[\s-]*đét|ve[\s-]*det|vedet(?:te)?)(?![\p{L}\p{N}])/iu' => 'Vedette',
        '/(?<![\p{L}\p{N}])(?:junghans|jun[\s-]*han(?:s)?|junhan)(?![\p{L}\p{N}])/iu' => 'Junghans',
    ];

    public static function normalizeText(string $text): string
    {
        foreach (self::ALIASES as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $text);
            if (is_string($normalized)) $text = $normalized;
        }

        return $text;
    }

    /**
     * Normalizes visible text nodes while preserving HTML tags and attributes.
     * JSON-LD is public metadata and is intentionally normalized; executable
     * scripts, styles and textarea values are left untouched.
     */
    public static function normalizeHtml(string $html): string
    {
        $parts = function_exists('wp_html_split')
            ? wp_html_split($html)
            : preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return self::normalizeText($html);

        $protected = null;
        foreach ($parts as $index => $part) {
            if (!is_string($part) || $part === '') continue;
            if ($part[0] === '<') {
                if (preg_match('/^<\s*script\b[^>]*type=(?:["\'])application\/ld\+json(?:["\'])[^>]*>/iu', $part)) {
                    $protected = 'jsonld';
                } elseif (preg_match('/^<\s*(script|style|textarea)\b/iu', $part, $opening)) {
                    $protected = strtolower((string) $opening[1]);
                } elseif (preg_match('/^<\s*\/\s*(script|style|textarea)\b/iu', $part)) {
                    $protected = null;
                }
                continue;
            }
            if (!in_array($protected, ['script', 'style', 'textarea'], true)) {
                $parts[$index] = self::normalizeText($part);
            }
        }

        return implode('', $parts);
    }
}
