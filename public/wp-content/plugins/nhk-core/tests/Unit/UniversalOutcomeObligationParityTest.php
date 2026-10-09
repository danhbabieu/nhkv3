<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Completion\OutcomeObligationCompiler;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpDispatchRegistry, McpToolCatalog};
use PHPUnit\Framework\TestCase;

final class UniversalOutcomeObligationParityTest extends TestCase
{
    /** @dataProvider domainRecipeProvider */
    public function test_registered_domain_uses_a_shared_obligation_recipe(string $ownerType, string $domain, bool $publicRequest, string $publicClass): void
    {
        $signals = [
            'owner_types' => [$ownerType],
            'owner_capabilities' => [$ownerType => ['public_capable' => $publicRequest]],
            'public_request' => $publicRequest,
            'publish' => $publicRequest,
        ];
        if ($domain === 'knowledge') $signals['dependency_owner_types'] = [$ownerType];
        if ($domain === 'graph') $signals['relations_required'] = true;

        $plan = (new OutcomeObligationCompiler())->compile('parity-' . $domain, ['intent' => strtoupper($domain)], $signals);

        self::assertSame($domain, $plan['domain']);
        self::assertSame($domain, $plan['recipe']['domain']);
        self::assertSame('REQUIRED', $plan['recipe']['canonical']['class']);
        self::assertSame($publicClass, $plan['obligations']['public']['class']);
        if ($domain === 'graph') self::assertSame('REQUIRED', $plan['obligations']['relations']['class']);
    }

    /** @return list<array{string,string,bool,string}> */
    public static function domainRecipeProvider(): array
    {
        return [
            ['dictionary_entry', 'dictionary', false, 'NOT_APPLICABLE'],
            ['media', 'media', true, 'REQUIRED'],
            ['video', 'video', true, 'REQUIRED'],
            ['wp_post', 'article', true, 'REQUIRED'],
            ['brand', 'authority', true, 'REQUIRED'],
            ['knowledge', 'knowledge', false, 'NOT_APPLICABLE'],
            ['source', 'knowledge', false, 'NOT_APPLICABLE'],
            ['evidence', 'knowledge', false, 'NOT_APPLICABLE'],
            ['graph', 'graph', true, 'REQUIRED'],
        ];
    }

    public function test_video_frontend_server_capability_is_separate_from_client_exposure(): void
    {
        self::assertTrue(McpToolCatalog::has('nhk.video.frontend.reconcile'));
        self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler('nhk.video.frontend.reconcile'));
        self::assertSame('nhk-v3/video-frontend-reconcile', McpAbilityRegistration::abilityNameForTool('nhk.video.frontend.reconcile'));
        self::assertSame('nhk.video.frontend.reconcile', McpDispatchRegistry::handlerKey('nhk.video.frontend.reconcile'));
        $parity = McpAbilityRegistration::callableParity()['nhk.video.frontend.reconcile'];
        self::assertTrue($parity['runtime_registered']);
        self::assertTrue($parity['callable_dispatched']);
        self::assertTrue($parity['connector_discoverable']);
    }
}
