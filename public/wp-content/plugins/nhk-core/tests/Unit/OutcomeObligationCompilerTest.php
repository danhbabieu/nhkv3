<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use InvalidArgumentException;
use NHK\Core\Application\Completion\OutcomeObligationCompiler;
use PHPUnit\Framework\TestCase;

final class OutcomeObligationCompilerTest extends TestCase
{
    public function test_every_accepted_owner_requires_canonical_readback(): void
    {
        $plan = (new OutcomeObligationCompiler())->compile('capture-1', ['intent' => 'TEXT_ARTICLE'], [
            'owner_types' => ['wp_post'],
            'owner_capabilities' => ['wp_post' => ['public_capable' => true]],
        ]);

        $this->assertSame('REQUIRED', $plan['obligations']['canonical']['class']);
        $this->assertSame('capture-1', $plan['capture_id']);
    }

    public function test_explicit_publish_requires_public_frontend_and_publication_proof(): void
    {
        $plan = (new OutcomeObligationCompiler())->compile('capture-2', ['intent' => 'VIDEO'], [
            'owner_types' => ['video'],
            'owner_capabilities' => ['video' => ['public_capable' => true]],
            'publish' => true,
        ]);

        $this->assertTrue($plan['public_request']);
        $this->assertSame('REQUIRED', $plan['obligations']['public']['class']);
        $this->assertSame('REQUIRED', $plan['obligations']['frontend']['class']);
        $this->assertSame('REQUIRED', $plan['obligations']['publication']['class']);
    }

    public function test_private_semantic_dependencies_are_not_forced_public(): void
    {
        $plan = (new OutcomeObligationCompiler())->compile('capture-3', ['intent' => 'VIDEO'], [
            'owner_types' => ['knowledge', 'source', 'evidence'],
            'dependency_owner_types' => ['knowledge', 'source', 'evidence'],
            'owner_capabilities' => [
                'knowledge' => ['public_capable' => false],
                'source' => ['public_capable' => false],
                'evidence' => ['public_capable' => false],
            ],
        ]);

        $this->assertSame('NOT_APPLICABLE', $plan['obligations']['public']['class']);
        $this->assertNotSame('', $plan['obligations']['public']['reason']);
        $this->assertSame('NOT_APPLICABLE', $plan['obligations']['frontend']['class']);
        $this->assertNotSame('', $plan['obligations']['frontend']['reason']);
    }

    public function test_homepage_is_optional_until_requested_or_policy_required(): void
    {
        $compiler = new OutcomeObligationCompiler();
        $base = [
            'owner_types' => ['video'],
            'owner_capabilities' => ['video' => ['public_capable' => true]],
            'publish' => true,
        ];

        $optional = $compiler->compile('capture-4', ['intent' => 'VIDEO'], $base);
        $requested = $compiler->compile('capture-5', ['intent' => 'VIDEO'], $base + ['homepage_request' => true]);

        $this->assertSame('OPTIONAL', $optional['obligations']['homepage']['class']);
        $this->assertSame('REQUIRED', $requested['obligations']['homepage']['class']);
    }

    public function test_fingerprint_is_stable_for_normalized_inputs_and_changes_with_bindings(): void
    {
        $compiler = new OutcomeObligationCompiler();
        $first = $compiler->compile('capture-6', ['intent' => 'video'], [
            'owner_types' => ['video', 'source'],
            'dependency_owner_types' => ['source'],
            'owner_capabilities' => ['video' => ['public_capable' => true]],
            'owner_revisions' => ['video-1' => 1],
        ]);
        $same = $compiler->compile('capture-6', ['intent' => ' VIDEO '], [
            'owner_types' => ['source', 'video'],
            'dependency_owner_types' => ['source'],
            'owner_capabilities' => ['video' => ['public_capable' => true]],
            'owner_revisions' => ['video-1' => 1],
        ]);
        $changed = $compiler->compile('capture-6', ['intent' => 'VIDEO'], [
            'owner_types' => ['video', 'source'],
            'dependency_owner_types' => ['source'],
            'owner_capabilities' => ['video' => ['public_capable' => true]],
            'owner_revisions' => ['video-1' => 2],
        ]);

        $this->assertSame($first['fingerprint'], $same['fingerprint']);
        $this->assertNotSame($first['fingerprint'], $changed['fingerprint']);
    }

    public function test_ambiguous_public_applicability_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OUTCOME_OBLIGATION_APPLICABILITY_AMBIGUOUS');

        (new OutcomeObligationCompiler())->compile('capture-7', ['intent' => 'UNKNOWN'], [
            'owner_types' => ['future_owner'],
            'publish' => true,
        ]);
    }
}
