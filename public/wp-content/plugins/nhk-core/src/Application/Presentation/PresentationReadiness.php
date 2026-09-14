<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/**
 * Read-only distinction between semantic activity and public presentation
 * readiness. It derives readiness from route/content projection inputs and
 * never changes the semantic entity state.
 */
final readonly class PresentationReadiness
{
    public const SEMANTIC_ACTIVE = 'SEMANTIC_ACTIVE';
    public const SEMANTIC_INACTIVE = 'SEMANTIC_INACTIVE';

    /** @param list<string> $reasons */
    private function __construct(private string $semanticState, private string $presentationStatus, private array $reasons = []) {}

    /** @param array<string,mixed> $semantic @param array<string,mixed> $projection */
    public static function evaluate(array $semantic, array $projection): self
    {
        $semanticActive = ($semantic['active'] ?? $semantic['semantic_active'] ?? false) === true;
        $semanticState = $semanticActive ? self::SEMANTIC_ACTIVE : self::SEMANTIC_INACTIVE;
        if (!$semanticActive) return new self($semanticState, 'BLOCKED', ['SEMANTIC_INACTIVE']);
        if (($projection['public_eligible'] ?? true) !== true) return new self($semanticState, 'BLOCKED', ['PUBLIC_ELIGIBILITY_BLOCKED']);

        $routeStatus = strtolower(trim((string) ($projection['route_status'] ?? '')));
        $route = trim((string) ($projection['route'] ?? ''));
        if ($routeStatus === 'unavailable') return new self($semanticState, 'UNAVAILABLE', ['PUBLIC_ROUTE_UNAVAILABLE']);
        if ($route === '' || $route[0] !== '/') return new self($semanticState, 'INCOMPLETE', ['PUBLIC_ROUTE_MISSING']);

        $contentStatus = strtolower(trim((string) ($projection['content_status'] ?? '')));
        if ($contentStatus === 'unavailable') return new self($semanticState, 'UNAVAILABLE', ['PRESENTATION_CONTENT_UNAVAILABLE']);
        $content = $projection['content'] ?? null;
        $hasContent = self::hasContent($content);
        if ($contentStatus === 'empty' || !$hasContent) return new self($semanticState, 'INCOMPLETE', ['PRESENTATION_CONTENT_MISSING']);

        return new self($semanticState, 'READY');
    }

    public function semanticState(): string { return $this->semanticState; }
    public function presentationStatus(): string { return $this->presentationStatus; }
    /** @return list<string> */
    public function reasons(): array { return $this->reasons; }
    /** @return array{semantic_state:string,presentation_status:string,reasons:list<string>} */
    public function toArray(): array { return ['semantic_state' => $this->semanticState, 'presentation_status' => $this->presentationStatus, 'reasons' => $this->reasons]; }

    private static function hasContent(mixed $content): bool
    {
        if (is_array($content)) {
            foreach ($content as $value) if (self::hasContent($value)) return true;
            return false;
        }
        if (is_bool($content)) return $content;
        return $content !== null && trim((string) $content) !== '';
    }
}
