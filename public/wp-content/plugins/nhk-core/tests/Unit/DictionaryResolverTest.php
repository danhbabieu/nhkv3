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
}
