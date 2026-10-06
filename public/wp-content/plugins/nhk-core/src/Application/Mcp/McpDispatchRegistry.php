<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/**
 * Canonical execution registry for the NHK MCP transport.
 *
 * Discovery and Ability projection may have different external names, but
 * every canonical tool name must be present here before it can claim to be
 * executable. The registry deliberately contains no WordPress write logic;
 * McpTransport remains the single dispatcher and owns the application
 * services behind these keys.
 */
final class McpDispatchRegistry
{
    /** @var array<string,string> canonical tool name => transport handler key */
    private const HANDLERS = [
        'nhk.documentation.bootstrap' => 'nhk.documentation.bootstrap',
        'nhk.documentation.get' => 'nhk.documentation.get',
        'nhk.documentation.list' => 'nhk.documentation.list',
        'nhk.docs.bootstrap' => 'nhk.docs.bootstrap',
        'nhk.docs.get' => 'nhk.docs.get',
        'nhk.dictionary.search' => 'nhk.dictionary.search',
        'nhk.dictionary.resolve' => 'nhk.dictionary.resolve',
        'nhk.dictionary.concept.get' => 'nhk.dictionary.concept.get',
        'nhk.dictionary.candidate.list' => 'nhk.dictionary.candidate.list',
        'nhk.dictionary.candidate.get' => 'nhk.dictionary.candidate.get',
        'nhk.dictionary.mentions.list' => 'nhk.dictionary.mentions.list',
        'nhk.dictionary.concept.create' => 'nhk.dictionary.concept.create',
        'nhk.dictionary.entry.create-with-sense' => 'nhk.dictionary.entry.create-with-sense',
        'nhk.dictionary.entry.form.add' => 'nhk.dictionary.entry.form.add',
        'nhk.dictionary.entry.sense.add' => 'nhk.dictionary.entry.sense.add',
        'nhk.dictionary.concept.update' => 'nhk.dictionary.concept.update',
        'nhk.dictionary.concept.lifecycle' => 'nhk.dictionary.concept.lifecycle',
        'nhk.dictionary.label.save' => 'nhk.dictionary.label.save',
        'nhk.dictionary.candidate.review' => 'nhk.dictionary.candidate.review',
        'nhk.dictionary.relation.handoff' => 'nhk.dictionary.relation.handoff',
        'nhk.dictionary.backfill.dry_run' => 'nhk.dictionary.backfill.dry_run',
        'nhk.dictionary.profile' => 'nhk.dictionary.profile',
        'nhk.dictionary.enrichment.audit' => 'nhk.dictionary.enrichment.audit',
        'nhk.dictionary.enrichment.plan' => 'nhk.dictionary.enrichment.plan',
        'nhk.dictionary.enrichment.apply' => 'nhk.dictionary.enrichment.apply',
        'nhk.dictionary.materialization.profile' => 'nhk.dictionary.materialization.profile',
        'nhk.dictionary.materialization.plan' => 'nhk.dictionary.materialization.plan',
        'nhk.dictionary.materialization.apply' => 'nhk.dictionary.materialization.apply',
        'nhk.dictionary.semantic-relation.read' => 'nhk.dictionary.semantic-relation.read',
        'nhk.dictionary.semantic-relation.preview' => 'nhk.dictionary.semantic-relation.preview',
        'nhk.dictionary.semantic-relation.apply' => 'nhk.dictionary.semantic-relation.apply',
        'nhk.dictionary.lexical-relation.read' => 'nhk.dictionary.lexical-relation.read',
        'nhk.dictionary.lexical-relation.preview' => 'nhk.dictionary.lexical-relation.preview',
        'nhk.dictionary.lexical-relation.apply' => 'nhk.dictionary.lexical-relation.apply',
        'nhk.search' => 'nhk.search',
        'nhk.canonical.inventory' => 'nhk.canonical.inventory',
        'nhk.graph.inventory' => 'nhk.graph.inventory',
        'nhk.relationship.registry' => 'nhk.relationship.registry',
        'nhk.relationship.list' => 'nhk.relationship.list',
        'nhk.relationship.get' => 'nhk.relationship.get',
        'nhk.relationship.preview' => 'nhk.relationship.preview',
        'nhk.relation.backfill.dry_run' => 'nhk.relation.backfill.dry_run',
        'nhk.relation.backfill.apply' => 'nhk.relation.backfill.apply',
        'nhk.semantic.resolve' => 'nhk.semantic.resolve',
        'nhk.entity.neighborhood' => 'nhk.entity.neighborhood',
        'nhk.article.preflight' => 'nhk.article.preflight',
        'nhk.article.media-legacy-audit' => 'nhk.article.media-legacy-audit',
        'nhk.article.media-legacy-repair-plan' => 'nhk.article.media-legacy-repair-plan',
        'nhk.knowledge.writer.preview' => 'nhk.knowledge.writer.preview',
        'nhk.knowledge.quality-audit' => 'nhk.knowledge.quality-audit',
        'nhk.dictionary.seed-audit' => 'nhk.dictionary.seed-audit',
        'nhk.article.ingest' => 'nhk.article.ingest',
        'nhk.capture.ingest' => 'nhk.capture.ingest',
        'nhk.capture.get' => 'nhk.capture.get',
        'nhk.category.resolve' => 'nhk.category.resolve',
        'nhk.category.create' => 'nhk.category.create',
        'nhk.category.update' => 'nhk.category.update',
        'nhk.category.assign' => 'nhk.category.assign',
        'nhk.category.unassign' => 'nhk.category.unassign',
        'nhk.category.delete' => 'nhk.category.delete',
        'nhk.article.draft.create' => 'nhk.article.draft.create',
        'nhk.article.draft.update' => 'nhk.article.draft.update',
        'nhk.article.publish' => 'nhk.article.publish',
        'nhk.article.publish.review' => 'nhk.article.publish.review',
        'nhk.article.publish.approve' => 'nhk.article.publish.approve',
        'nhk.article.trash' => 'nhk.article.trash',
        'nhk.article.restore' => 'nhk.article.restore',
        'nhk.entity.get' => 'nhk.entity.get',
        'nhk.media.get' => 'nhk.media.get',
        'nhk.media.update' => 'nhk.media.update',
        'nhk.media.binding.get' => 'nhk.media.binding.get',
        'nhk.media.bind' => 'nhk.media.bind',
        'nhk.media.usage' => 'nhk.media.usage',
        'nhk.media.upload-batch' => 'nhk.media.upload-batch',
        'nhk.media.widget-upload' => 'nhk.media.widget-upload',
        'nhk.media.upload-widget.open' => 'nhk.media.upload-widget.open',
        'nhk.media.ingest' => 'nhk.media.ingest',
        'nhk.media.attachment.get' => 'nhk.media.attachment.get',
        'nhk.video.ingest' => 'nhk.video.ingest',
        'nhk.video.source.refresh' => 'nhk.video.source.refresh',
        'nhk.video.get' => 'nhk.video.get',
        'nhk.video.frontend.reconcile' => 'nhk.video.frontend.reconcile',
        'nhk.knowledge.get' => 'nhk.knowledge.get',
        'nhk.source.get' => 'nhk.source.get',
        'nhk.evidence.get' => 'nhk.evidence.get',
        'nhk.knowledge.ingest' => 'nhk.knowledge.ingest',
        'nhk.source.ingest' => 'nhk.source.ingest',
        'nhk.evidence.ingest' => 'nhk.evidence.ingest',
        'nhk.public-url.audit' => 'nhk.public-url.audit',
        'nhk.public-url.reproject' => 'nhk.public-url.reproject',
        'nhk.proposal.create' => 'nhk.proposal.create',
        'nhk.proposal.submit' => 'nhk.proposal.submit',
        'nhk.proposal.review' => 'nhk.proposal.review',
        'nhk.proposal.discover' => 'nhk.proposal.discover',
        'nhk.proposal.approve' => 'nhk.proposal.approve',
        'nhk.proposal.reject' => 'nhk.proposal.reject',
        'nhk.proposal.eligibility' => 'nhk.proposal.eligibility',
        'nhk.proposal.apply' => 'nhk.proposal.apply',
    ];

    public static function handlerKey(string $tool): ?string
    {
        return self::HANDLERS[$tool] ?? null;
    }

    public static function hasHandler(string $tool): bool
    {
        return isset(self::HANDLERS[$tool]);
    }

    /** @return list<string> */
    public static function toolNames(): array
    {
        return array_keys(self::HANDLERS);
    }
}
