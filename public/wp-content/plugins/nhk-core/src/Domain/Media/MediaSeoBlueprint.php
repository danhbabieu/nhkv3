<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final readonly class MediaSeoBlueprint
{
    /** @param list<string> $keywordGroups @param array<string,mixed> $subjectContext */
    public function __construct(
        public int $postId,
        public string $slot,
        public array $subjectContext,
        public ?string $preferredView,
        public array $keywordGroups,
        public string $plannedTitle,
        public string $plannedFilenameStem,
        public string $plannedAltIntent,
        public string $preferredAspect,
        public int $minimumWidth,
        public int $minimumHeight,
        public bool $focalPointExpected,
        public string $state,
        public int $revision = 1,
    ) {
        if ($postId < 1 || !in_array($slot, MediaUsageRoleRegistry::mandatoryArticleRoles(), true)) throw new InvalidArgumentException('Article Media Blueprint identity is invalid.');
        foreach ($keywordGroups as $group) SeoKeywordGroupRegistry::assertKnown((string) $group);
        if ($plannedTitle === '' || $plannedFilenameStem === '' || $plannedAltIntent === '' || $preferredAspect === '' || $minimumWidth < 1 || $minimumHeight < 1 || $revision < 1) throw new InvalidArgumentException('Article Media Blueprint is invalid.');
        if ($preferredView !== null) MediaDetailTypeRegistry::assertKnown($preferredView);
        MediaSeoStateRegistry::assertKnown($state);
    }

    /** @param array<string,mixed> $context */
    public static function forPost(int $postId, string $slot, array $context = [], string $state = MediaSeoStateRegistry::PLACEHOLDER): self
    {
        $subject = trim((string) ($context['subject'] ?? $context['title'] ?? 'Bài viết'));
        $view = isset($context['preferred_view']) && $context['preferred_view'] !== '' ? (string) $context['preferred_view'] : null;
        $groups = is_array($context['keyword_groups'] ?? null) ? array_values(array_unique(array_map('strval', $context['keyword_groups']))) : ['subject', 'view', 'content_intent'];
        $stem = self::slug($subject) . '-' . ($view !== null ? self::slug($view) : ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'featured' : 'inline'));
        return new self(
            $postId,
            $slot,
            is_array($context['subject_context'] ?? null) ? $context['subject_context'] : ['subject' => $subject],
            $view,
            $groups,
            trim((string) ($context['planned_title'] ?? $subject)),
            trim((string) ($context['planned_filename_stem'] ?? $stem)),
            trim((string) ($context['planned_alt_intent'] ?? $subject . ($view !== null ? ' — ' . $view : ''))),
            trim((string) ($context['preferred_aspect'] ?? '16:9')),
            max(1, (int) ($context['minimum_width'] ?? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 1200 : 800))),
            max(1, (int) ($context['minimum_height'] ?? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 675 : 450))),
            (bool) ($context['focal_point_expected'] ?? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY)),
            $state,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'post_id' => $this->postId,
            'slot' => $this->slot,
            'subject_context' => $this->subjectContext,
            'preferred_view' => $this->preferredView,
            'keyword_groups' => $this->keywordGroups,
            'planned_title' => $this->plannedTitle,
            'planned_filename_stem' => $this->plannedFilenameStem,
            'planned_alt_intent' => $this->plannedAltIntent,
            'preferred_aspect' => $this->preferredAspect,
            'minimum_width' => $this->minimumWidth,
            'minimum_height' => $this->minimumHeight,
            'focal_point_expected' => $this->focalPointExpected,
            'state' => $this->state,
            'revision' => $this->revision,
        ];
    }

    private static function slug(string $value): string
    {
        $value = function_exists('remove_accents') ? remove_accents($value) : (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
        $value = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));
        return trim($value, '-') ?: 'media';
    }
}
