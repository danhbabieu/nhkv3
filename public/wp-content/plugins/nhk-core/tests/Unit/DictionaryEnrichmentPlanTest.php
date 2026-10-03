<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryEnrichmentOwnerResolver, DictionaryEnrichmentPlan};
use NHK\Core\Domain\Dictionary\DictionaryConcept;
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentPlanTest extends TestCase
{
    public function test_exact_unique_approved_label_becomes_form_action_and_duplicates_are_noop(): void
    {
        $plan = new DictionaryEnrichmentPlan(new class {
            public function listForms(string $entryId): array { return [['form' => '400 ngày', 'kind' => 'PREFERRED']]; }
        }, new DictionaryEnrichmentOwnerResolver());

        $result = $plan->build(['items' => [[
            'entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'current_revision' => 4,
            'preferred_form' => '400 ngày', 'forms' => [['form' => '400 ngày', 'kind' => 'PREFERRED']],
            'approved_legacy_labels' => [
                ['label' => '400-Day Clock', 'kind' => 'ALTERNATE', 'locale' => 'en-US', 'source' => 'approved'],
                ['label' => '400 ngày', 'kind' => 'PREFERRED', 'locale' => 'vi-VN', 'source' => 'approved'],
            ],
            'owner_resolution' => ['classification' => 'EXACT_UNIQUE', 'target' => ['type' => 'classification', 'id' => 'owner-1'], 'evidence' => ['explicit_legacy_destination']],
        ]]]);

        self::assertSame('READY', $result['status']);
        $actionsByForm = []; foreach ($result['actions'] as $action) $actionsByForm[$action['form'] ?? $action['reason']] = $action;
        self::assertSame('ADD_ENTRY_FORM', $actionsByForm['400-Day Clock']['action_type']);
        self::assertSame('NOOP', $actionsByForm['400 ngày']['action_type']);
        self::assertNotSame('', $result['fingerprint']);
    }

    public function test_ambiguous_owner_is_review_required_and_private_source_cannot_create_form(): void
    {
        $plan = new DictionaryEnrichmentPlan(new class { public function listForms(string $entryId): array { return []; } }, new DictionaryEnrichmentOwnerResolver());
        $result = $plan->build(['items' => [[
            'entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'current_revision' => 1, 'preferred_form' => 'Côn', 'forms' => [],
            'approved_legacy_labels' => [['label' => 'guessed alias', 'kind' => 'ALTERNATE', 'source' => 'candidate']],
            'owner_resolution' => ['classification' => 'AMBIGUOUS', 'target' => null, 'evidence' => ['label_similarity']],
        ]]]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertNotEmpty(array_filter($result['actions'], static fn (array $action): bool => ($action['action_type'] ?? '') === 'REVIEW_REQUIRED'));
        self::assertSame('REVIEW_REQUIRED', $result['actions'][0]['action_type']);
    }

    public function test_owner_resolver_only_accepts_explicit_strong_evidence(): void
    {
        $resolver = new DictionaryEnrichmentOwnerResolver();
        $sense = new DictionaryConcept('sense-1', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED, 'classification', 'owner-1');

        self::assertSame('EXACT_UNIQUE', $resolver->resolve($sense, ['explicit_legacy_destination' => ['type' => 'classification', 'id' => 'owner-1']])['classification']);
        self::assertSame('AMBIGUOUS', $resolver->resolve($sense, ['label_similarity' => [['id' => 'owner-1'], ['id' => 'owner-2']]])['classification']);
        self::assertSame('NO_OWNER', $resolver->resolve(new DictionaryConcept('sense-2', 'Không biết', 'Nghĩa', DictionaryConcept::APPROVED), [])['classification']);
    }
}
