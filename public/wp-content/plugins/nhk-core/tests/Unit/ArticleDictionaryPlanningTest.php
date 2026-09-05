<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleResearchPreflight;
use PHPUnit\Framework\TestCase;

final class ArticleDictionaryPlanningTest extends TestCase
{
    public function test_article_research_exposes_non_blocking_dictionary_plan(): void
    {
        $preflight = new ArticleResearchPreflight(
            static fn (array $input): array => ['status' => 'resolved', 'primary' => ['id' => 'subject-1', 'type' => 'brand'], 'subjects' => [['id' => 'subject-1', 'type' => 'brand']]],
            static fn (array $input): array => ['status' => 'available', 'posts' => [], 'categories' => [['name' => 'Tri thức', 'slug' => 'tri-thuc']], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [['id' => 'm1', 'ready' => true, 'public' => true]], 'videos' => [], 'relations' => []],
            static fn (array $relation): array => ['status' => 'available', 'eligible' => false],
            static fn (string $text, array $context): array => [
                'resolved_terms' => [],
                'ambiguous_terms' => [],
                'candidate_terms' => [['term' => 'côn lòng máng', 'state' => 'NEEDS_REVIEW']],
                'internal_link_candidates' => [],
                'warnings' => [],
                'blocking' => false,
            ],
        );

        $packet = $preflight->research('Bài về côn lòng máng', ['brand' => 'subject-1'], ['body' => 'Nội dung nhắc côn lòng máng.'])->toArray();

        self::assertTrue($packet['ready_for_draft']);
        self::assertSame('côn lòng máng', $packet['dictionary_plan']['candidate_terms'][0]['term']);
        self::assertNotContains('DICTIONARY_REVIEW_REQUIRED', $packet['blockers']);
    }

    public function test_dictionary_runtime_failure_is_warning_not_fabricated_empty_success(): void
    {
        $preflight = new ArticleResearchPreflight(
            static fn (): array => ['status' => 'resolved', 'primary' => ['id' => 'subject-1', 'type' => 'brand'], 'subjects' => [['id' => 'subject-1', 'type' => 'brand']]],
            static fn (): array => ['status' => 'available', 'posts' => [], 'categories' => [['name' => 'Tri thức', 'slug' => 'tri-thuc']], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [['id' => 'm1', 'ready' => true, 'public' => true]], 'videos' => [], 'relations' => []],
            static fn (): array => ['status' => 'available', 'eligible' => false],
            static function (): array { throw new \RuntimeException('dictionary unavailable'); },
        );

        $packet = $preflight->research('Bài thử')->toArray();

        self::assertSame('UNAVAILABLE', $packet['dictionary_plan']['status']);
        self::assertContains('DICTIONARY_PLANNING_UNAVAILABLE', $packet['warnings']);
        self::assertTrue($packet['ready_for_draft']);
    }
}
