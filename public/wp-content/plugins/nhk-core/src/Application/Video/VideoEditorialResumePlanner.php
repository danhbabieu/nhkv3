<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Video\Video;

/** Builds a governed update for an existing Video without changing identity. */
final class VideoEditorialResumePlanner
{
    public const POLICY_VERSION = 'video-editorial-resume-1';

    public function __construct(
        private VideoRepository $videos,
        private VideoEditorialGenerator $editorial,
        private VideoSeoProjection $seo,
        private ?PublicEditorialCopyGuard $publicCopyGuard = null,
        /** @var callable(array<string,mixed>):array<string,mixed>|null */
        private $knowledgeEnrichment = null,
        private ?VideoEditorialAdapter $sharedEditorial = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function plan(array $videoProposal, array $context): array
    {
        $payload = is_array($videoProposal['payload'] ?? null) ? $videoProposal['payload'] : [];
        $videoId = trim((string) ($payload['canonical_id'] ?? $videoProposal['target_uuid'] ?? $videoProposal['subject_id'] ?? ''));
        $video = $videoId !== '' ? $this->videos->findByCanonicalId($videoId) : null;
        $proposalSource = $this->sourceFromProposal($payload);
        $external = $this->externalVideo($proposalSource);
        $externalVideo = $external === null ? null : $this->videos->findByExternalReference($external['platform'], $external['external_video_id']);

        // An immutable child UUID is planned identity, not proof that the
        // governed create reached Controlled Apply. Reconcile both
        // authoritative identities before choosing update versus create.
        if ($video instanceof Video && $externalVideo instanceof Video && $externalVideo->canonicalId !== $video->canonicalId) {
            throw new \RuntimeException('VIDEO_IDENTITY_CONFLICT');
        }
        if (!$video instanceof Video) {
            if ($externalVideo instanceof Video) throw new \RuntimeException('VIDEO_IDENTITY_CONFLICT');
            if ($videoId === '' || $external === null) throw new \RuntimeException('VIDEO_SOURCE_IDENTITY_UNAVAILABLE');
            return $this->preApplyCreatePlan($videoProposal, $payload, $videoId, $external, $context);
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $proposalMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $previousSubjectId = trim((string) (($metadata['subject_resolution_packet']['id'] ?? '')));
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $source = array_merge($source, [
            'platform' => $video->platform,
            'external_video_id' => $video->externalVideoId,
            'canonical_source_url' => $video->canonicalUrl,
        ]);
        $subject = $this->subject($context, $metadata);
        $claims = $this->claims($context);
        $delta = trim((string) ($context['continuation_delta_text'] ?? ''));
        $fingerprint = $this->fingerprint($video, $source, $subject, $claims, $delta);
        $existingFingerprint = trim((string) ($metadata['editorial_input_fingerprint'] ?? ''));
        $desired = null;
        $staleEditorialReplay = false;
        if ($this->sharedEditorial !== null && $existingFingerprint !== '' && hash_equals($existingFingerprint, $fingerprint)) {
            // The persisted canonical package is sufficient for an exact
            // fingerprint replay. Do not invoke the shared composer merely
            // because the resume endpoint was called again.
            if (is_array($metadata['editorial'] ?? null)
                && is_array($metadata['seo'] ?? null)
                && is_array($metadata['seo_projection'] ?? null)
            ) return $this->reuse($video, $fingerprint);
        }
        if ($this->sharedEditorial === null && $existingFingerprint !== '' && hash_equals($existingFingerprint, $fingerprint)) {
            $desired = $this->desiredPackage($video, $source, $subject, $delta, $proposalMetadata);
            if ($this->canonicalEditorialPayloadMatches($video, $desired, $fingerprint)) {
                return $this->reuse($video, $fingerprint, $desired);
            }
            $staleEditorialReplay = true;
        }
        // Legacy Videos may not carry a fingerprint. An explicit resume with
        // no editorial input is still a no-op; do not mint an unnecessary
        // revision merely to add bookkeeping.
        if ($existingFingerprint === '' && $delta === '') return $this->reuse($video, $fingerprint);

        $shared = $this->shared($source, $subject, $delta, $context, $metadata);
        if ($shared !== null) {
            $claims = $shared['fingerprint_claims'];
            $fingerprint = $this->fingerprint($video, $source, $subject, $claims, $delta);
            if ($existingFingerprint !== '' && hash_equals($existingFingerprint, $fingerprint)
                && $this->sharedEditorial !== null
                && is_array($metadata['editorial'] ?? null)
                && is_array($metadata['seo'] ?? null)
                && is_array($metadata['seo_projection'] ?? null)
            ) return $this->reuse($video, $fingerprint);
        }

        $desired ??= $this->desiredPackage($video, $source, $subject, $delta, null, $shared);
        $enrichment = $desired['enrichment'];
        $editorial = $desired['editorial'];
        $seo = $desired['seo'];
        $package = $desired['package'];
        $metadata['editorial'] = $editorial;
        $metadata['seo'] = $seo;
        $metadata['subject_resolution_packet'] = $subject;
        if ($subject !== null && $previousSubjectId !== '' && $previousSubjectId !== (string) ($subject['id'] ?? '')) {
            // A subject correction cannot carry the old target forward by
            // accident. A replacement relation must arrive with fresh
            // governed evidence; otherwise completeness remains blocked.
            $metadata['semantic_attachments'] = array_values(array_filter(
                (array) ($metadata['semantic_attachments'] ?? []),
                static fn (mixed $attachment): bool => !is_array($attachment)
                    || strtolower(trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''))) !== strtolower($previousSubjectId),
            ));
            $metadata['semantic_reconciliation_requested'] = true;
        }
        $metadata['enrichment_context'] = $enrichment;
        $metadata['seo_projection'] = $desired['seo_projection'];
        $metadata['editorial_input_fingerprint'] = $fingerprint;
        $metadata['editorial_reconciliation'] = [
            'status' => 'REBUILD_EDITORIAL',
            'fingerprint' => $fingerprint,
            'policy_version' => self::POLICY_VERSION,
        ] + ($staleEditorialReplay ? ['diagnostic' => 'STALE_EDITORIAL_REPLAY'] : []);

        return [
            'status' => 'REBUILD_EDITORIAL',
            'operation' => 'update',
            'entity_type' => 'video',
            'subject_id' => $video->canonicalId,
            'target_uuid' => $video->canonicalId,
            'expected_revision' => $video->revision,
            // The canonical revision is part of the governed command identity.
            // A prior proposal for the same semantic fingerprint may carry an
            // obsolete CAS revision; never let that proposal be reused for a
            // newer canonical read.
            'idempotency_key' => 'capture:' . (string) ($context['capture_id'] ?? $video->canonicalId) . ':video-editorial:revision:' . $video->revision . ':' . $fingerprint,
            'fingerprint' => $fingerprint,
            'payload' => [
                'canonical_id' => $video->canonicalId,
                'url' => $video->canonicalUrl,
                // Keep the canonical title aligned with the desired editorial
                // label; source-title provenance remains in metadata.source.
                'title' => (string) ($editorial['title'] ?? $video->title),
                'metadata' => $metadata,
                'thumbnail_media_id' => $video->thumbnailMediaId ?? '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function preApplyCreatePlan(array $videoProposal, array $payload, string $videoId, array $external, array $context): array
    {
        // A planned UUID and source snapshot are immutable Capture inputs; the
        // rest of a legacy proposal is a historical derived projection. Do
        // not carry that projection into a new ingest after contracts change.
        $persistedMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $source = is_array($persistedMetadata['source'] ?? null)
            ? $persistedMetadata['source']
            : (is_array($persistedMetadata['source_snapshot'] ?? null) ? $persistedMetadata['source_snapshot'] : []);
        $source = array_merge($source, [
            'platform' => (string) ($source['platform'] ?? $external['platform']),
            'external_video_id' => (string) ($source['external_video_id'] ?? $external['external_video_id']),
            'canonical_source_url' => (string) ($source['canonical_source_url'] ?? $external['canonical_source_url']),
        ]);
        $subject = $this->subject($context, $persistedMetadata);
        $shared = $this->shared($source, $subject, trim((string) ($context['continuation_delta_text'] ?? $context['user_hint'] ?? '')), $context, $persistedMetadata);
        $userHint = trim((string) ($context['user_hint'] ?? ($persistedMetadata['provenance']['user_hint']['value'] ?? '')));
        $enrichmentContext = [
            'source_facts' => trim((string) ($source['source_title'] ?? '')) !== '' ? [['text' => (string) $source['source_title']]] : [],
            'canonical_context' => $subject === null ? [] : [[
                'text' => (string) ($subject['name'] ?? ''),
                'entity_id' => (string) ($subject['id'] ?? ''),
                'entity_type' => (string) ($subject['type'] ?? ''),
            ]],
        ];
        $metadata = [
            // Source/snapshot data is the only persisted package data reused
            // here. It is not regenerated and therefore preserves its hash.
            'source' => $source,
            'provenance' => is_array($persistedMetadata['provenance'] ?? null) ? $persistedMetadata['provenance'] : [],
            'source_rights' => $persistedMetadata['source_rights'] ?? null,
            'transcript_policy' => $persistedMetadata['transcript_policy'] ?? null,
        ];
        $metadata = array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== []);
        $metadata['subject_resolution_packet'] = $subject;
        $metadata = $this->refreshDerivedEnrichment($metadata, $external, $context);
        $desired = $shared !== null ? $this->sharedPackage($shared, $source, $subject, $videoId) : null;
        $editorial = $desired['editorial'] ?? $this->editorial->generate(
            $source,
            $userHint,
            trim((string) ($context['editorial_instruction'] ?? '')),
            $subject,
            trim((string) ($context['editorial_title'] ?? '')),
            trim((string) ($context['compliance_note'] ?? '')),
            $enrichmentContext,
        );
        ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
        $metadata['editorial'] = $editorial;
        $metadata['seo'] = ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
        $metadata['seo_projection'] = $this->seo->project([
            'source' => $source,
            'editorial' => $editorial,
            'seo' => $metadata['seo'],
            'subject_resolution_packet' => $subject,
        ], (string) ($source['canonical_source_url'] ?? ''));
        // Candidate relations/completeness/diagnostics must be produced by
        // the current governed continuation, never inherited from preview.
        $metadata['semantic_attachments'] = [];
        $metadata['completeness'] = ['publishable' => false, 'blockers' => ['GOVERNED_RECONCILIATION_REQUIRED'], 'warnings' => []];
        $metadata['diagnostics'] = ['REBUILT_FROM_IMMUTABLE_CAPTURE_INPUTS'];
        $payload['canonical_id'] = $videoId;
        $payload['metadata'] = $metadata;
        $payload['url'] = (string) ($payload['url'] ?? $external['canonical_source_url'] ?? '');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize([
            'canonical_id' => $videoId,
            'source' => $external,
            'payload' => $payload,
        ]));
        $idempotencyKey = trim((string) ($videoProposal['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') $idempotencyKey = 'capture:' . (string) ($context['capture_id'] ?? $videoId) . ':video-create:' . $fingerprint;

        return [
            'status' => 'REBUILD_INGEST',
            'operation' => 'ingest',
            'entity_type' => 'video',
            'subject_id' => $videoId,
            'target_uuid' => null,
            // An ingest has no canonical owner revision. The staging packet
            // represents this create CAS as zero; Capture revision never
            // becomes Video revision.
            'expected_revision' => null,
            'fingerprint' => $fingerprint,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
        ];
    }

    /** @param array<string,mixed> $metadata @param array<string,string> $external @param array<string,mixed> $context @return array<string,mixed> */
    private function refreshDerivedEnrichment(array $metadata, array $external, array $context): array
    {
        if (!is_callable($this->knowledgeEnrichment)) return $metadata;
        $subject = is_array($context['subject_resolution']['primary'] ?? null)
            ? $context['subject_resolution']['primary']
            : (is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : null);
        $resolved = is_array($context['subject_resolution']['resolved'] ?? null)
            ? $context['subject_resolution']['resolved']
            : ($subject !== null ? [$subject] : []);
        $userHint = trim((string) ($context['user_hint'] ?? ($metadata['provenance']['user_hint']['value'] ?? '')));
        try {
            $enrichment = ($this->knowledgeEnrichment)([
                'resolved' => $resolved,
                'ambiguous' => is_array($context['subject_resolution']['ambiguous'] ?? null) ? $context['subject_resolution']['ambiguous'] : [],
                'intended_targets' => $subject !== null ? [$subject] : [],
                'source' => $external,
                'transcript_policy' => null,
                'user_hint' => $userHint !== '' ? ['value' => $userHint, 'kind' => 'USER_HINT'] : null,
            ]);
            if (!is_array($enrichment)) return $metadata;
            $metadata['knowledge_enrichment'] = $enrichment;
            if ($subject !== null) $metadata['subject_resolution_packet'] = $subject;
        } catch (\Throwable $error) {
            // A failed recomputation must not silently preserve an obsolete
            // packet. Keep the immutable source/identity fields, but expose a
            // current diagnostic so stale derived state cannot masquerade as
            // a successful retry.
            $metadata['knowledge_enrichment'] = [
                'status' => 'unavailable',
                'subject' => $subject,
                'candidates' => [],
                'diagnostics' => ['KNOWLEDGE_ENRICHMENT_RECOMPUTE_FAILED:' . $error->getMessage()],
                'proposal_ready' => false,
                'unresolved_reasons' => ['ENRICHMENT_UNAVAILABLE'],
            ];
        }
        return $metadata;
    }

    /** @return array<string,mixed> */
    private function sourceFromProposal(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        return is_array($metadata['source'] ?? null)
            ? $metadata['source']
            : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
    }

    /** @return array{platform:string,external_video_id:string,canonical_source_url:string}|null */
    private function externalVideo(array $source): ?array
    {
        $platform = strtolower(trim((string) ($source['platform'] ?? '')));
        $externalId = trim((string) ($source['external_video_id'] ?? ''));
        if ($platform === '' || $externalId === '') return null;
        return [
            'platform' => $platform,
            'external_video_id' => $externalId,
            'canonical_source_url' => trim((string) ($source['canonical_source_url'] ?? '')),
        ];
    }

    /** @return array<string,mixed> */
    private function reuse(Video $video, string $fingerprint, ?array $desired = null): array
    {
        return [
            'status' => 'REUSE_EDITORIAL',
            'operation' => 'update',
            'entity_type' => 'video',
            'subject_id' => $video->canonicalId,
            'target_uuid' => $video->canonicalId,
            'fingerprint' => $fingerprint,
            'canonical_readback' => [
                'canonical_id' => $video->canonicalId,
                'title' => $video->title,
                'editorial_title' => $desired['editorial']['title'] ?? ($video->metadata['editorial']['title'] ?? $video->title),
                'platform' => $video->platform,
                'external_video_id' => $video->externalVideoId,
                'canonical_url' => $video->canonicalUrl,
                'revision' => $video->revision,
                'editorial_input_fingerprint' => $video->metadata['editorial_input_fingerprint'] ?? null,
                'editorial_payload_parity' => $desired !== null,
            ],
            'idempotent' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function desiredPackage(Video $video, array $source, ?array $subject, string $delta, ?array $proposalMetadata = null, ?array $shared = null): array
    {
        $enrichment = [
            'source_facts' => trim((string) ($source['source_title'] ?? '')) !== '' ? [['text' => (string) $source['source_title']]] : [],
            'canonical_context' => $subject === null ? [] : [['text' => (string) ($subject['name'] ?? ''), 'entity_id' => (string) ($subject['id'] ?? ''), 'entity_type' => (string) ($subject['type'] ?? '')]],
        ];
        $replayEditorial = is_array($proposalMetadata['editorial'] ?? null) ? $proposalMetadata['editorial'] : [];
        $replayTitle = trim((string) ($replayEditorial['title'] ?? ''));
        $replayPackage = $delta === '' && $replayTitle !== '' ? $proposalMetadata : null;
        if ($replayPackage !== null) {
            $enrichment = is_array($replayPackage['enrichment_context'] ?? null) ? $replayPackage['enrichment_context'] : $enrichment;
            $editorial = $replayEditorial;
            $seo = is_array($replayPackage['seo'] ?? null) ? $replayPackage['seo'] : ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
            $subject = is_array($replayPackage['subject_resolution_packet'] ?? null) ? $replayPackage['subject_resolution_packet'] : $subject;
            $package = $replayPackage;
            $package['source'] = is_array($package['source'] ?? null) ? $package['source'] : $source;
            $package['editorial'] = $editorial;
            $package['seo'] = $seo;
            $package['subject_resolution_packet'] = $subject;
        } elseif ($shared !== null) {
            $package = $this->sharedPackage($shared, $source, $subject, $video->canonicalId);
            $editorial = $package['editorial'];
            $seo = $package['seo'];
        } else {
            $editorial = $this->editorial->generate($source, $delta, '', $subject, '', '', $enrichment);
            ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
            $seo = ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
            $package = [
                'source' => $source,
                'editorial' => $editorial,
                'seo' => $seo,
                'subject_resolution_packet' => $subject,
            ];
        }
        $package['canonical_id'] = $video->canonicalId;
        ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
        $seoProjection = is_array($package['seo_projection'] ?? null)
            ? $package['seo_projection']
            : $this->seo->project($package, $video->canonicalUrl);

        return [
            'enrichment' => $enrichment,
            'editorial' => $editorial,
            'seo' => $seo,
            'package' => $package,
            'subject_resolution_packet' => $subject,
            'seo_projection' => $seoProjection,
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed>|null $subject @param array<string,mixed> $context @param array<string,mixed> $metadata @return array<string,mixed>|null */
    private function shared(array $source, ?array $subject, string $delta, array $context, array $metadata): ?array
    {
        if ($this->sharedEditorial === null) return null;
        try {
            $identity = is_array($context['public_identity'] ?? null) ? $context['public_identity'] : (is_array($metadata['public_identity'] ?? null) ? $metadata['public_identity'] : []);
            if ($identity === [] && is_array($metadata['seo_projection'] ?? null)) {
                $canonical = trim((string) ($metadata['seo_projection']['canonical'] ?? ''));
                if ($canonical !== '') $identity = ['canonical_url' => $canonical, 'canonical_identity' => true, 'public_eligible' => true];
            }
            $result = $this->sharedEditorial->prepare([
                'source' => $source,
                'raw_input' => $delta !== '' ? $delta : (string) ($source['source_title'] ?? ''),
                'user_hint' => $delta,
                'editorial_instruction' => (string) ($context['editorial_instruction'] ?? ''),
                'subject_resolution' => ['primary' => $subject],
                'public_identity' => $identity,
            ]);
            if (strtoupper((string) ($result['status'] ?? '')) === 'BLOCKED') {
                throw new \RuntimeException(VideoEditorialOutcome::failureCode($result));
            }
            return $result;
        } catch (\Throwable $error) {
            if (in_array($error->getMessage(), ['VIDEO_EDITORIAL_QUALITY_BLOCKED', 'VIDEO_EDITORIAL_REVIEW_REQUIRED'], true)
                || str_starts_with($error->getMessage(), 'VISUAL_SUPPORT_')
                || $error->getMessage() === 'FACTUAL_CONFLICT'
            ) throw $error;
            throw new \RuntimeException('VIDEO_SHARED_EDITORIAL_UNAVAILABLE', 0, $error);
        }
    }

    /** @param array<string,mixed> $shared @param array<string,mixed> $source @param array<string,mixed>|null $subject @return array<string,mixed> */
    private function sharedPackage(array $shared, array $source, ?array $subject, string $videoId): array
    {
        $draft = $shared['draft'];
        $seo = $shared['seo_plan'];
        $editorial = [
            'title' => $draft->title,
            'summary' => $draft->summary,
            'body' => $draft->body,
            'claim_trace' => $draft->claimTrace,
            'why_this_matters' => 'Giúp người xem bắt đầu từ video và nhận biết đúng chủ đề đang được trình bày.',
            'context' => trim((string) ($source['source_title'] ?? '')) !== '' ? [['text' => (string) $source['source_title'], 'provenance' => 'SOURCE_FACT']] : [],
            'facts' => [],
            'related_knowledge' => [],
            'compliance_context' => ['source' => 'shared_editorial_quality_gate'],
        ];
        $package = ['canonical_id' => $videoId, 'source' => $source, 'editorial' => $editorial, 'seo' => ['title' => $seo->title, 'description' => $seo->metaDescription], 'subject_resolution_packet' => $subject, 'semantic_claim_trace' => $draft->claimTrace, 'content_quality' => ['status' => $shared['quality_report']->readiness === 'READY' ? 'CONTENT_COMPLETE' : 'NEEDS_REVIEW', 'blockers' => $shared['quality_report']->blockers, 'warnings' => $shared['quality_report']->warnings]];
        return [
            'editorial' => $editorial,
            'seo' => $package['seo'],
            'package' => $package,
            'enrichment' => [
                'source_facts' => $editorial['context'],
                'canonical_context' => $subject === null ? [] : [[
                    'text' => (string) ($subject['name'] ?? ''),
                    'entity_id' => (string) ($subject['id'] ?? ''),
                    'entity_type' => (string) ($subject['type'] ?? ''),
                ]],
            ],
        ];
    }

    private function canonicalEditorialPayloadMatches(Video $video, array $desired, string $fingerprint): bool
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($desired['package']['source'] ?? null) ? $desired['package']['source'] : [];
        $desiredTitle = trim((string) ($desired['editorial']['title'] ?? ''));
        $storedSource = is_array($metadata['source'] ?? null)
            ? $metadata['source']
            : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);

        return $video->canonicalId === (string) ($desired['package']['canonical_id'] ?? '')
            && $desiredTitle !== ''
            && $video->title === $desiredTitle
            && $video->platform === (string) ($source['platform'] ?? $video->platform)
            && $video->externalVideoId === (string) ($source['external_video_id'] ?? $video->externalVideoId)
            && $video->canonicalUrl === (string) ($source['canonical_source_url'] ?? $video->canonicalUrl)
            && ($storedSource['external_video_id'] ?? $video->externalVideoId) === $video->externalVideoId
            && ($storedSource['canonical_source_url'] ?? $video->canonicalUrl) === $video->canonicalUrl
            && ($metadata['editorial_input_fingerprint'] ?? null) === $fingerprint
            && $this->sameCanonicalValue($metadata['editorial'] ?? null, $desired['editorial'] ?? null)
            && $this->sameCanonicalValue($metadata['seo'] ?? null, $desired['seo'] ?? null)
            && $this->sameCanonicalValue($metadata['subject_resolution_packet'] ?? null, $desired['subject_resolution_packet'] ?? null)
            && $this->sameCanonicalValue($metadata['seo_projection'] ?? null, $desired['seo_projection'] ?? null)
            && $this->semanticTargetParity($metadata['semantic_attachments'] ?? null, $desired['package']['semantic_attachments'] ?? null);
    }

    private function sameCanonicalValue(mixed $actual, mixed $desired): bool
    {
        if (!is_array($actual) || !is_array($desired)) return $actual === $desired;
        return CommandCanonicalizer::canonicalize($actual) === CommandCanonicalizer::canonicalize($desired);
    }

    private function semanticTargetParity(mixed $actual, mixed $desired): bool
    {
        if (!is_array($desired)) return true;
        $actualTargets = $this->semanticTargets($actual);
        $desiredTargets = $this->semanticTargets($desired);
        sort($actualTargets);
        sort($desiredTargets);
        return $actualTargets === $desiredTargets;
    }

    /** @return list<string> */
    private function semanticTargets(mixed $attachments): array
    {
        $targets = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (!is_array($attachment)) continue;
            $target = trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''));
            if ($target === '') continue;
            $targets[] = strtolower((string) ($attachment['target_type'] ?? '') . ':' . $target);
        }
        return array_values(array_unique($targets));
    }

    /** @return array<string,mixed>|null */
    private function subject(array $context, array $metadata): ?array
    {
        $subject = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
        if ($subject === []) $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        return $subject === [] ? null : [
            'id' => (string) ($subject['id'] ?? ''),
            'type' => (string) ($subject['type'] ?? ''),
            'name' => (string) ($subject['name'] ?? ''),
            'revision' => isset($subject['revision']) ? (int) $subject['revision'] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function claims(array $context): array
    {
        $claims = [];
        foreach ((array) ($context['retrieval']['selected_claims'] ?? []) as $claim) {
            if (!is_array($claim)) continue;
            $id = trim((string) ($claim['id'] ?? $claim['claim_id'] ?? $claim['canonical_id'] ?? ''));
            if ($id === '') continue;
            $claims[] = ['id' => $id, 'revision' => (int) ($claim['revision'] ?? $claim['claim_revision'] ?? 0)];
        }
        usort($claims, static fn (array $left, array $right): int => strcmp(CommandCanonicalizer::canonicalize($left), CommandCanonicalizer::canonicalize($right)));
        return $claims;
    }

    private function fingerprint(Video $video, array $source, ?array $subject, array $claims, string $delta): string
    {
        $sourceState = [
            'platform' => $video->platform,
            'external_video_id' => $video->externalVideoId,
            'canonical_source_url' => $video->canonicalUrl,
            'source_revision' => $source['source_revision'] ?? $source['revision'] ?? null,
            'source_snapshot_hash' => $source['source_snapshot_hash'] ?? null,
            'source_title' => $source['source_title'] ?? $source['title'] ?? null,
            'source_description' => $source['source_description'] ?? $source['description'] ?? null,
        ];
        return hash('sha256', CommandCanonicalizer::canonicalize([
            'source' => $sourceState,
            'user_editorial_delta' => $delta,
            'subject' => $subject,
            'claims' => $claims,
            'policy_version' => self::POLICY_VERSION,
        ]));
    }
}
