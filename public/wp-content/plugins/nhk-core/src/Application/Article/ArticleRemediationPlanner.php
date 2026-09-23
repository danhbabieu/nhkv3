<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\ArticleRemediationAction;

/** Converts publication diagnostics into bounded, owner-routed repair work. */
final class ArticleRemediationPlanner
{
    /** @param array<string,mixed> $state @param list<string> $diagnostics @return list<ArticleRemediationAction> */
    public function plan(array $state, array $diagnostics): array
    {
        $actions = [];
        $map = [
            'MEDIAUSAGE_INCOMPLETE' => ['media', 'RECONCILE_INLINE_MEDIA', true],
            'ARTICLE_MEDIA_FEATURED_MISSING' => ['media', 'REPLACE_FEATURED_MEDIA', true],
            'ARTICLE_MEDIA_INLINE_MISSING' => ['media', 'RECONCILE_INLINE_MEDIA', true],
            'SUBJECT_NOT_PERSISTED' => ['graph', 'RESOLVE_PRIMARY_SUBJECT', true],
            'SUBJECT_UNRESOLVED' => ['graph', 'REVIEW_REQUIRED', false],
            'PUBLIC_ROUTE_NOT_READY' => ['wordpress', 'ALLOCATE_SLUG', true],
            'SEMANTIC_READBACK_UNVERIFIED' => ['canonical', 'VERIFY_SEMANTIC_READBACK', true],
            'MEDIA_USAGE_SEMANTIC_MISMATCH' => ['media', 'REPLACE_FEATURED_MEDIA', true],
            'RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE' => ['wordpress', 'READBACK_VERIFY', true],
            'SUBJECT_PACKET_SUPERSESSION' => ['capture', 'SUPERSEDE_SUBJECT_PACKET', true],
            'ARTICLE_ABOUT_RELATION_MISMATCH' => ['graph', 'CONVERGE_PRIMARY_ABOUT', true],
        ];
        foreach (array_values(array_unique(array_map('strval', $diagnostics))) as $code) {
            [$owner, $action, $safe] = $map[$code] ?? ['system', 'SYSTEM_BLOCKED', false];
            $actions[] = new ArticleRemediationAction($code, $owner, $action, $safe, $this->currentState($state, $code), $this->desiredState($state, $code), $this->dependencies($code), $this->reason($code));
        }
        return $actions;
    }

    private function currentState(array $state, string $code): mixed { return match ($code) { 'PUBLIC_ROUTE_NOT_READY' => ['slug' => (string) ($state['slug'] ?? ''), 'permalink' => (string) ($state['permalink'] ?? '')], 'MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'ARTICLE_MEDIA_INLINE_MISSING', 'MEDIA_USAGE_SEMANTIC_MISMATCH' => $state['media'] ?? [], default => $state['subject'] ?? null }; }
    private function desiredState(array $state, string $code): mixed { return match ($code) { 'PUBLIC_ROUTE_NOT_READY' => ['slug' => 'title-derived', 'permalink' => 'native-wordpress-readback'], 'MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'ARTICLE_MEDIA_INLINE_MISSING', 'MEDIA_USAGE_SEMANTIC_MISMATCH' => $state['desired_media'] ?? [], default => $state['subject_packet'] ?? null }; }
    /** @return list<string> */
    private function dependencies(string $code): array { $dependencies = []; if (in_array($code, ['MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'ARTICLE_MEDIA_INLINE_MISSING', 'MEDIA_USAGE_SEMANTIC_MISMATCH', 'SUBJECT_PACKET_SUPERSESSION', 'ARTICLE_ABOUT_RELATION_MISMATCH'], true)) $dependencies[] = 'capture-child-admission'; if (in_array($code, ['SUBJECT_NOT_PERSISTED', 'SEMANTIC_READBACK_UNVERIFIED', 'ARTICLE_ABOUT_RELATION_MISMATCH'], true)) $dependencies[] = 'governed-proposal'; return $dependencies; }
    private function reason(string $code): string { return match ($code) { 'PUBLIC_ROUTE_NOT_READY' => 'Native WordPress slug/permalink is incomplete and must be repaired through the editorial owner.', 'SUBJECT_UNRESOLVED' => 'The precedence policy did not produce one canonical subject.', default => 'Publication diagnostics require the registered owner workflow.' }; }
}
