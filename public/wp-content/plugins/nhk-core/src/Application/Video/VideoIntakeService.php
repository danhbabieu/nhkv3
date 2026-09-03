<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{VideoIntakePreview, VideoSourceRights};
use NHK\Core\Shared\Uuid\UuidCodec;

final class VideoIntakeService
{
    public function __construct(
        private YouTubeSourceAdapter $source,
        private VideoRepository $videos,
        private VideoHubClassifier $classifier,
        private VideoRelationCandidatePlanner $relations,
        private VideoEditorialGenerator $editorial,
        private VideoCompletenessPolicy $completeness,
        private VideoSeoProjection $seo,
        private ?VideoInternalSemanticResearcher $researcher = null,
    ) {
    }

    /** @param list<array<string,mixed>> $intendedRelations */
    public function preview(string $url, string $userHint = '', ?string $intendedCategory = null, array $intendedRelations = [], string $editorialInstruction = ''): VideoIntakePreview
    {
        $resolution = $this->source->resolve($url);
        $snapshot = $resolution->snapshot->toArray();
        $chapters = (new VideoChapterParser())->parse((string) ($snapshot['source_description'] ?? ''), isset($snapshot['duration_seconds']) ? (int) $snapshot['duration_seconds'] : null);
        $existing = $this->videos->findByExternalReference($snapshot['platform'], $snapshot['external_video_id']);
        $videoId = $existing?->canonicalId ?? UuidCodec::newV7();
        $research = $this->researcher?->research(implode("\n", array_filter([$snapshot['source_title'] ?? '', $snapshot['source_description'] ?? '', $userHint]))) ?? ['resolved' => [], 'ambiguous' => [], 'missing' => []];
        $relations = $intendedRelations;
        foreach ($research['resolved'] as $match) {
            if (!is_array($match['evidence_refs'] ?? null) || $match['evidence_refs'] === []) continue;
            $relations[] = [
                'target_id' => $match['id'], 'target_type' => $match['type'], 'predicate' => 'about',
                'origin' => $userHint !== '' && str_contains($this->lower($userHint), $this->lower((string) $match['name'])) ? 'EXPLICIT_USER_RELATION' : 'INFERRED_RELATION',
                'evidence_refs' => array_values($match['evidence_refs']),
                'reason' => 'Resolved against existing NHK canonical identity.', 'confidence' => $userHint !== '' ? 0.9 : 0.7,
            ];
        }
        $candidateObjects = $this->relations->plan($videoId, $relations);
        $candidatePayloads = array_map(static fn (\NHK\Core\Domain\Video\VideoRelationCandidate $candidate): array => $candidate->toProposalPayload(), $candidateObjects);
        $category = $this->classifier->classify(['source_title' => $snapshot['source_title'] ?? '', 'source_description' => $snapshot['source_description'] ?? '', 'tags' => $snapshot['tags'] ?? [], 'user_hint' => $userHint]);
        if ($intendedCategory !== null && isset(VideoHubClassifier::hubs()[$intendedCategory])) {
            $category['primary'] = ['key' => $intendedCategory, 'label' => VideoHubClassifier::hubs()[$intendedCategory], 'primary' => true, 'score' => 0];
            $category['categories'] = [$category['primary']];
        }
        $editorial = $this->editorial->generate($snapshot, $userHint, $editorialInstruction);
        $seoData = ['title' => $editorial['title'], 'description' => $editorial['summary']];
        $package = [
            'intake_version' => 1,
            'source' => array_merge($snapshot, ['identity_valid' => true, 'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'locator' => $snapshot['canonical_source_url']]]),
            'transcript_policy' => $resolution->transcript->kind,
            'editorial' => $editorial,
            'category' => $category,
            'semantic_attachments' => $candidatePayloads,
            'seo' => $seoData,
            'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $snapshot['external_video_id'],
            'provenance' => ['source_url' => $snapshot['canonical_source_url'], 'user_hint' => $userHint !== '' ? ['value' => $userHint, 'kind' => 'USER_HINT'] : null],
            'source_rights' => VideoSourceRights::PUBLIC_EXTERNAL_REFERENCE,
            'chapters' => $chapters,
        ];
        $complete = $this->completeness->evaluate($package);
        $package['completeness'] = ['publishable' => $complete->publishable, 'blockers' => $complete->blockers, 'warnings' => $complete->warnings];
        $watchPath = PublicRouteResolver::videoPath((string) $editorial['title'], (string) $snapshot['external_video_id']) ?? '/video/' . strtolower((string) $snapshot['external_video_id']) . '/';
        $package['seo_projection'] = $this->seo->project($package, $watchPath);
        $warnings = array_values(array_unique(array_merge($complete->blockers, $complete->warnings, $category['warnings'] ?? [])));
        return new VideoIntakePreview($videoId, $existing === null ? 'ingest' : 'update', $existing?->revision ?? 1, $package, $warnings, $research['ambiguous']);
    }

    /** @return array<string,mixed> */
    public function proposalArguments(VideoIntakePreview $preview, ?string $idempotencyKey = null): array
    {
        $package = $preview->package;
        return [
            'operation' => $preview->operation,
            'entity_type' => 'video',
            'subject_id' => $preview->videoId,
            'target_uuid' => $preview->operation === 'update' ? $preview->videoId : null,
            'expected_revision' => $preview->expectedRevision,
            'idempotency_key' => $idempotencyKey ?: 'video-intake-' . hash('sha256', (string) ($package['source']['canonical_source_url'] ?? '') . '|' . json_encode($package['provenance'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload' => [
                'canonical_id' => $preview->videoId,
                'url' => (string) ($package['source']['canonical_source_url'] ?? ''),
                'title' => (string) ($package['editorial']['title'] ?? ''),
                'metadata' => $package,
                'thumbnail_media_id' => '',
            ],
        ];
    }

    private function lower(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value); }
}
