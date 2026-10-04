<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryResolver;
use NHK\Core\Domain\Dictionary\DictionaryResolution;
use PHPUnit\Framework\TestCase;

final class DictionaryResolverTest extends TestCase
{
    public function test_approved_label_reuses_existing_canonical_destination(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (string $term, array $context): array => $term === 'westminster' ? [[
                'concept_id' => '018f2f9a-0000-7000-8000-000000000001',
                'preferred_label' => 'Westminster',
                'destination_type' => 'music',
                'destination_id' => '018f2f9a-0000-7000-8000-000000000002',
                'destination_url' => '/ban-nhac/westminster/',
            ]] : [],
            entityLookup: static fn (): array => [],
            knowledgeLookup: static fn (): array => [],
            articleLookup: static fn (): array => [],
            suppressionLookup: static fn (): bool => false,
        );

        $result = $resolver->resolve('Westminster');

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame('/ban-nhac/westminster/', $result->destinationUrl);
        self::assertSame('music', $result->destinationType);
    }

    public function test_existing_entity_is_reused_before_knowledge_or_article(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [],
            entityLookup: static fn (): array => [['destination_type' => 'component', 'destination_id' => 'component-1', 'destination_url' => '/linh-kien/khoa-ngua/', 'preferred_label' => 'Khóa ngựa']],
            knowledgeLookup: static fn (): array => [['destination_type' => 'knowledge', 'destination_id' => 'knowledge-1', 'destination_url' => '/tri-thuc/khoa-ngua/', 'preferred_label' => 'Khóa ngựa']],
            articleLookup: static fn (): array => [['destination_type' => 'article', 'destination_id' => '55', 'destination_url' => '/bai-viet/khoa-ngua/', 'preferred_label' => 'Khóa ngựa']],
            suppressionLookup: static fn (): bool => false,
        );

        $result = $resolver->resolve('Khóa ngựa');

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame('component', $result->destinationType);
        self::assertSame('/linh-kien/khoa-ngua/', $result->destinationUrl);
    }

    public function test_multiple_context_valid_label_matches_fail_closed_as_ambiguous(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [
                ['concept_id' => 'concept-a', 'preferred_label' => 'Côn', 'destination_type' => 'knowledge', 'destination_id' => 'a', 'destination_url' => '/tri-thuc/a/'],
                ['concept_id' => 'concept-b', 'preferred_label' => 'Côn', 'destination_type' => 'component', 'destination_id' => 'b', 'destination_url' => '/linh-kien/b/'],
            ],
            entityLookup: static fn (): array => [],
            knowledgeLookup: static fn (): array => [],
            articleLookup: static fn (): array => [],
            suppressionLookup: static fn (): bool => false,
        );

        $result = $resolver->resolve('Côn');

        self::assertSame(DictionaryResolution::AMBIGUOUS, $result->status);
        self::assertNull($result->destinationUrl);
        self::assertCount(2, $result->candidates);
    }

    public function test_unknown_term_is_suppressed_when_do_not_suggest_exists(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [],
            entityLookup: static fn (): array => [],
            knowledgeLookup: static fn (): array => [],
            articleLookup: static fn (): array => [],
            suppressionLookup: static fn (string $term): bool => $term === 'máy đẹp',
        );

        $result = $resolver->resolve('Máy đẹp');

        self::assertSame(DictionaryResolution::SUPPRESSED, $result->status);
        self::assertNull($result->destinationUrl);
    }

    public function test_unknown_term_returns_unknown_without_fabricating_destination(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [],
            entityLookup: static fn (): array => [],
            knowledgeLookup: static fn (): array => [],
            articleLookup: static fn (): array => [],
            suppressionLookup: static fn (): bool => false,
        );

        $result = $resolver->resolve('Một thuật ngữ hoàn toàn mới');

        self::assertSame(DictionaryResolution::UNKNOWN, $result->status);
        self::assertNull($result->destinationUrl);
        self::assertSame('một thuật ngữ hoàn toàn mới', $result->normalizedTerm);
    }

    public function test_exact_approved_label_with_curation_metadata_remains_resolvable(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [[
                'concept_id' => 'sense-400', 'preferred_label' => '400 ngày',
                'destination_type' => 'dictionary', 'destination_id' => 'sense-400',
                'context' => ['migrated_from_duplicate_draft' => true, 'review_actor' => 'curator'],
            ]],
            entityLookup: static fn (): array => [], knowledgeLookup: static fn (): array => [], articleLookup: static fn (): array => [], suppressionLookup: static fn (): bool => false,
        );

        $result = $resolver->resolve('400 ngày', ['locale' => 'vi-VN']);

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame('sense-400', $result->destinationId);
    }

    public function test_exact_wording_with_two_applicable_approved_senses_remains_ambiguous(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [
                ['concept_id' => 'sense-a', 'preferred_label' => 'Anniversary clock', 'context' => ['research_source' => 'a']],
                ['concept_id' => 'sense-b', 'preferred_label' => 'Anniversary clock', 'context' => ['research_source' => 'b']],
            ],
            entityLookup: static fn (): array => [], knowledgeLookup: static fn (): array => [], articleLookup: static fn (): array => [], suppressionLookup: static fn (): bool => false,
        );

        self::assertSame(DictionaryResolution::AMBIGUOUS, $resolver->resolve('Anniversary clock')->status);
    }

    public function test_incompatible_lexical_scope_is_not_broadened_by_exact_wording(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [[
                'concept_id' => 'sense-clock', 'preferred_label' => 'Clock', 'context' => ['domain' => 'horology'],
            ]],
            entityLookup: static fn (): array => [], knowledgeLookup: static fn (): array => [], articleLookup: static fn (): array => [], suppressionLookup: static fn (): bool => false,
        );

        self::assertSame(DictionaryResolution::UNKNOWN, $resolver->resolve('Clock', ['domain' => 'music'])->status);
    }

    public function test_source_locale_does_not_reject_exact_foreign_label_without_explicit_lexical_constraint(): void
    {
        $resolver = new DictionaryResolver(
            approvedLabelLookup: static fn (): array => [['concept_id' => 'sense-400', 'preferred_label' => 'Anniversary clock', 'locale' => 'en']],
            entityLookup: static fn (): array => [], knowledgeLookup: static fn (): array => [], articleLookup: static fn (): array => [], suppressionLookup: static fn (): bool => false,
        );

        self::assertSame(DictionaryResolution::RESOLVED, $resolver->resolve('Anniversary clock', ['source_locale' => 'vi-VN'])->status);
        self::assertSame(DictionaryResolution::UNKNOWN, $resolver->resolve('Anniversary clock', ['lexical_locale' => 'vi-VN'])->status);
    }
}
