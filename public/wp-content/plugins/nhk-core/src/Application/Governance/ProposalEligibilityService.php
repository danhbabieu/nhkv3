<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\{EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Application\Graph\ClassifiedAsPolicy;
use NHK\Core\Domain\Governance\{DependencyGraph, EligibilityResult, ProposalState, ProposalSubjectBindingValidator};

final class ProposalEligibilityService
{
    public function __construct(private ProposalRepository $proposals, private DependencyGraph $dependencies, private EligibilityReader $reader, private ?VideoProposalEligibilityEvaluator $video = null, private ?ClassifiedAsPolicy $classifiedAs = null, private ?MediaUsageRepository $mediaUsages = null) {}

    public function check(string $proposalId): EligibilityResult
    {
        $proposal = $this->proposals->find($proposalId);
        if ($proposal === null) return EligibilityResult::blocked('PROPOSAL_NOT_FOUND');
        if ($proposal->state === ProposalState::APPLIED) return EligibilityResult::blocked('ALREADY_APPLIED');
        if (!ProposalSubjectBindingValidator::isValid($proposal)) return EligibilityResult::blocked('PROPOSAL_SUBJECT_BINDING_INVALID');
        if (!in_array($proposal->state, [ProposalState::SUBMITTED, ProposalState::APPROVED], true)) return EligibilityResult::blocked('NOT_APPROVED');
        if ($proposal->state !== ProposalState::APPROVED) return EligibilityResult::blocked('APPROVAL_MISSING');
        $approval = $this->proposals->latestApproval($proposalId);
        if ($approval === null) return EligibilityResult::blocked('APPROVAL_MISSING');
        if ((int) ($approval['proposal_revision'] ?? 0) !== $proposal->revision
            || $this->fingerprint($approval['fingerprint'] ?? null) !== strtolower($proposal->bindingFingerprint())) {
            return EligibilityResult::blocked('APPROVAL_BINDING_MISMATCH');
        }
        $reasons = [];
        $isCreation = in_array($proposal->operation, ['create', 'ingest'], true) && $proposal->targetUuid === null;
        // relation_create carries typed endpoint keys in its payload. A
        // WordPress endpoint key such as 1:487 is not an Authority UUID, so
        // do not send it through the generic canonical-target check before
        // the relation-specific revision checks below.
        if ($proposal->operation !== 'relation_create' && !$isCreation && $proposal->subjectId !== '' && !$this->reader->targetExists($proposal->targetUuid ?: $proposal->subjectId)) $reasons[] = 'TARGET_NOT_FOUND';
        if ($proposal->entityType === 'media' && $proposal->operation === 'representative_bind') {
            $mediaRevision = (int) ($proposal->payload['media_revision'] ?? $proposal->expectedRevision ?? 0);
            $targetRevision = (int) ($proposal->payload['target_revision'] ?? 0);
            if ($mediaRevision < 1 || $this->reader->targetRevision($proposal->subjectId) !== $mediaRevision) $reasons[] = 'MEDIA_REVISION_CHANGED';
            if ($proposal->targetUuid === null || $targetRevision < 1 || $this->reader->targetRevision($proposal->targetUuid) !== $targetRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        }
        if ($proposal->entityType === 'media' && in_array($proposal->operation, ['add', 'replace', 'remove'], true)) {
            $target = is_array($proposal->payload['target'] ?? null) ? $proposal->payload['target'] : [];
            $targetType = strtolower(trim((string) ($target['type'] ?? '')));
            if ($targetType === 'wp_post') {
                $blog = (int) ($target['blog_id'] ?? 1);
                $post = (int) ($target['post_id'] ?? $target['id'] ?? 0);
                $endpointKey = $blog . ':' . $post;
                if ($blog < 1 || $post < 1 || !$this->reader->targetExists($endpointKey)) $reasons[] = 'ARTICLE_TARGET_NOT_FOUND';
            } elseif ($proposal->targetUuid === null || !$this->reader->targetExists($proposal->targetUuid)) {
                $reasons[] = 'MEDIA_USAGE_TARGET_NOT_FOUND';
            }
            if (in_array($proposal->operation, ['replace', 'remove'], true)) {
                if (!preg_match('/^[0-9A-Fa-f-]{36}$/', (string) ($proposal->payload['usage_id'] ?? ''))) $reasons[] = 'MEDIA_USAGE_ID_REQUIRED';
                $expectedUsageRevision = (int) ($proposal->payload['expected_usage_revision'] ?? 0);
                if ($expectedUsageRevision < 1) {
                    $reasons[] = 'MEDIA_USAGE_REVISION_REQUIRED';
                } elseif ($this->mediaUsages !== null) {
                    $usageId = (string) ($proposal->payload['usage_id'] ?? '');
                    $usage = null;
                    if ($targetType === 'wp_post') {
                        foreach ($this->mediaUsages->listByEndpoint('wp_post', $endpointKey) as $candidate) {
                            if ($candidate->usageId === $usageId) { $usage = $candidate; break; }
                        }
                    } elseif ($proposal->targetUuid !== null) {
                        foreach ($this->mediaUsages->listByEndpoint($targetType, $proposal->targetUuid) as $candidate) {
                            if ($candidate->usageId === $usageId) { $usage = $candidate; break; }
                        }
                    }
                    if ($usage === null || $usage->activeSlot === 'retired' || $usage->revision !== $expectedUsageRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
                }
            }
        }
        if ($proposal->operation === 'merge') {
            $sourceRevision = (int) ($proposal->payload['source_revision'] ?? $proposal->expectedRevision);
            $targetRevision = (int) ($proposal->payload['target_revision'] ?? 0);
            if ($sourceRevision < 1 || $targetRevision < 1) $reasons[] = 'MERGE_REVISIONS_REQUIRED';
            if ($proposal->subjectId !== '' && $this->reader->targetRevision($proposal->subjectId) !== $sourceRevision) $reasons[] = 'SOURCE_REVISION_CHANGED';
            if ($proposal->targetUuid !== null && $this->reader->targetRevision($proposal->targetUuid) !== $targetRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        } elseif ($proposal->operation === 'relation_create') {
            if (($proposal->payload['predicate'] ?? '') === 'classified_as') {
                try {
                    ($this->classifiedAs ?? new ClassifiedAsPolicy())->assertCandidate([
                        'source_type' => (string) ($proposal->payload['source_type'] ?? ''),
                        'scope' => (string) ($proposal->payload['scope'] ?? ''),
                        'provenance' => (string) ($proposal->payload['provenance'] ?? ''),
                        'target_type' => (string) ($proposal->payload['target_type'] ?? ''),
                        'target_family' => (string) ($proposal->payload['target_family'] ?? ''),
                    ]);
                } catch (\Throwable $error) {
                    $reasons[] = $error->getMessage();
                }
            }
            $sourceRevision = (int) ($proposal->payload['source_revision'] ?? 0);
            $targetRevision = (int) ($proposal->payload['target_revision'] ?? 0);
            $sourceId = (string) ($proposal->payload['source_uuid'] ?? $proposal->subjectId);
            $targetId = (string) ($proposal->payload['target_uuid'] ?? '');
            if ($sourceRevision < 1 || $targetRevision < 1 || $this->reader->targetRevision($sourceId) !== $sourceRevision || $this->reader->targetRevision($targetId) !== $targetRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        } elseif (!$isCreation && !($proposal->entityType === 'media' && in_array($proposal->operation, ['add', 'replace', 'remove'], true)) && $proposal->subjectId !== '' && $proposal->expectedRevision > 0 && $this->reader->targetRevision($proposal->targetUuid ?: $proposal->subjectId) !== $proposal->expectedRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        $dependencyRevisions = $proposal->payload['dependency_revisions'] ?? [];
        if (is_array($dependencyRevisions)) {
            foreach ($dependencyRevisions as $dependencyUuid => $expectedRevision) {
                if (!is_string($dependencyUuid) || !is_int($expectedRevision) || $expectedRevision < 1) {
                    $reasons[] = 'DEPENDENCY_REVISION_INVALID';
                    continue;
                }
                if ($this->reader->targetRevision($dependencyUuid) !== $expectedRevision) $reasons[] = 'DEPENDENCY_REVISION_CHANGED';
            }
        }
        if ($this->video !== null) $reasons = array_merge($reasons, $this->video->evaluate($proposal));
        foreach ($this->dependencies->closure($proposalId) as $dependency) if (!$this->reader->isApplied($dependency)) $reasons[] = 'DEPENDENCY_NOT_APPLIED';
        return $reasons ? EligibilityResult::blocked(...$reasons) : EligibilityResult::ready();
    }

    private function fingerprint(mixed $value): string
    {
        if (!is_string($value)) return '';
        return preg_match('/^[a-f0-9]{64}$/i', $value) === 1
            ? strtolower($value)
            : bin2hex($value);
    }
}
