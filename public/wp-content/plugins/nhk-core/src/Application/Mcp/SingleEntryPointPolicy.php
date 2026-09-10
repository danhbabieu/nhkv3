<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/**
 * Runtime policy for the one-entry-point law.
 *
 * Capture is the only normal submission boundary. The remaining mutation
 * tools are retained for bounded admin/lifecycle compatibility, but are never
 * an operator-facing path for new content.
 */
final class SingleEntryPointPolicy
{
    public const CANONICAL_TOOL = 'nhk.capture.ingest';
    public const INTERNAL_CAPABILITY = 'nhk_internal_content_operations';

    /** @var list<string> */
    private const INTERNAL_ONLY_TOOLS = [
        'nhk.article.ingest',
        'nhk.category.create',
        'nhk.category.update',
        'nhk.category.assign',
        'nhk.category.unassign',
        'nhk.category.delete',
        'nhk.article.draft.create',
        'nhk.article.draft.update',
        'nhk.article.publish',
        'nhk.article.publish.review',
        'nhk.article.publish.approve',
        'nhk.article.trash',
        'nhk.article.restore',
        'nhk.media.upload-batch',
        'nhk.media.ingest',
        'nhk.video.ingest',
        'nhk.knowledge.ingest',
        'nhk.source.ingest',
        'nhk.evidence.ingest',
        'nhk.public-url.reproject',
        'nhk.proposal.create',
        'nhk.proposal.submit',
        'nhk.proposal.approve',
        'nhk.proposal.reject',
        'nhk.proposal.apply',
        'nhk.relation.backfill.apply',
    ];

    /** Lifecycle continuation for an existing Capture-owned Article draft. */
    private const PUBLICATION_CONTINUATION_TOOLS = [
        'nhk.article.publish.review',
        'nhk.article.publish.approve',
        'nhk.article.publish',
    ];

    /** Old names remain callable only as read-only compatibility aliases. */
    private const DEPRECATED_TOOLS = ['nhk.docs.bootstrap', 'nhk.docs.get'];

    public static function isCanonical(string $tool): bool
    {
        return $tool === self::CANONICAL_TOOL;
    }

    public static function isInternalOnly(string $tool): bool
    {
        return in_array($tool, self::INTERNAL_ONLY_TOOLS, true);
    }

    public static function isPublicationContinuation(string $tool): bool
    {
        return in_array($tool, self::PUBLICATION_CONTINUATION_TOOLS, true);
    }

    /** @return list<string> */
    public static function publicationContinuationTools(): array
    {
        return self::PUBLICATION_CONTINUATION_TOOLS;
    }

    public static function isDeprecated(string $tool): bool
    {
        return in_array($tool, self::DEPRECATED_TOOLS, true);
    }

    /** @return list<string> */
    public static function internalOnlyTools(): array
    {
        return self::INTERNAL_ONLY_TOOLS;
    }

    public static function surface(string $tool): string
    {
        return self::isCanonical($tool) ? 'canonical' : (self::isPublicationContinuation($tool) ? 'governed_publication_continuation' : (self::isInternalOnly($tool) ? 'internal_admin_only' : (self::isDeprecated($tool) ? 'deprecated' : 'read_only')));
    }

    /** @param callable(string):bool|null $can */
    public static function guard(string $tool, ?callable $can): void
    {
        if (!self::isInternalOnly($tool)) return;
        if ($can !== null && (bool) $can(self::INTERNAL_CAPABILITY)) return;

        throw new SingleEntryPointViolation(
            'DIRECT_WRITE_BLOCKED',
            'New content must use nhk.capture.ingest. This mutation is reserved for an authenticated internal/admin boundary.',
            $tool,
        );
    }
}

final class SingleEntryPointViolation extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly string $tool)
    {
        parent::__construct($message);
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'code' => $this->reasonCode,
            'reason' => 'USE_CANONICAL_CAPTURE_FLOW',
            'tool' => $this->tool,
            'canonical_entry_point' => SingleEntryPointPolicy::CANONICAL_TOOL,
        ];
    }
}
