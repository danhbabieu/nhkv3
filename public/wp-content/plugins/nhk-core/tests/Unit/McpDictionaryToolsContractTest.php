<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpCapabilityManifest, McpDispatchRegistry, McpToolCatalog, SingleEntryPointPolicy};
use PHPUnit\Framework\TestCase;

final class McpDictionaryToolsContractTest extends TestCase
{
    public function test_dictionary_tools_have_catalog_dispatch_and_strict_mutation_fields(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.dictionary.search', 'nhk.dictionary.resolve', 'nhk.dictionary.concept.get', 'nhk.dictionary.candidate.list', 'nhk.dictionary.candidate.get', 'nhk.dictionary.mentions.list', 'nhk.dictionary.concept.create', 'nhk.dictionary.entry.create-with-sense', 'nhk.dictionary.entry.form.add', 'nhk.dictionary.entry.sense.add', 'nhk.dictionary.concept.update', 'nhk.dictionary.concept.lifecycle', 'nhk.dictionary.label.save', 'nhk.dictionary.candidate.review', 'nhk.dictionary.relation.handoff', 'nhk.dictionary.backfill.dry_run', 'nhk.dictionary.profile', 'nhk.dictionary.enrichment.audit', 'nhk.dictionary.enrichment.plan', 'nhk.dictionary.enrichment.apply', 'nhk.dictionary.materialization.profile', 'nhk.dictionary.materialization.plan', 'nhk.dictionary.materialization.apply'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertTrue(McpDispatchRegistry::hasHandler($name));
        }
        self::assertSame('mutation', $tools['nhk.dictionary.concept.update']['kind']);
        self::assertContains('idempotency_key', $tools['nhk.dictionary.concept.update']['inputSchema']['required']);
        self::assertContains('expected_revision', $tools['nhk.dictionary.concept.update']['inputSchema']['required']);
        self::assertArrayNotHasKey('nhk.dictionary.concept.delete', $tools);
        self::assertSame('read', $tools['nhk.dictionary.enrichment.audit']['kind']);
        self::assertSame('mutation', $tools['nhk.dictionary.enrichment.apply']['kind']);
        self::assertContains('approved_plan_fingerprint', $tools['nhk.dictionary.enrichment.apply']['inputSchema']['required']);
        self::assertSame('read', $tools['nhk.dictionary.materialization.profile']['kind']);
        self::assertSame('read', $tools['nhk.dictionary.materialization.plan']['kind']);
        self::assertSame('mutation', $tools['nhk.dictionary.materialization.apply']['kind']);
        self::assertSame(['concept_id', 'concept_ids', 'limit'], array_keys($tools['nhk.dictionary.materialization.profile']['inputSchema']['properties']));
        self::assertSame(['concept_id', 'concept_ids', 'limit'], array_keys($tools['nhk.dictionary.materialization.plan']['inputSchema']['properties']));
        self::assertSame([], $tools['nhk.dictionary.materialization.profile']['inputSchema']['required']);
        self::assertSame([], $tools['nhk.dictionary.materialization.plan']['inputSchema']['required']);
        self::assertFalse($tools['nhk.dictionary.materialization.profile']['governed']);
        self::assertFalse($tools['nhk.dictionary.materialization.plan']['governed']);
        self::assertTrue($tools['nhk.dictionary.materialization.apply']['governed']);
        self::assertSame(['plan', 'approved_plan_fingerprint', 'idempotency_key'], $tools['nhk.dictionary.materialization.apply']['inputSchema']['required']);
    }

    public function test_materialization_catalog_manifest_ability_and_policy_layers_cannot_drift(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $manifest = McpCapabilityManifest::forContentKind('dictionary');
        self::assertIsArray($manifest);
        self::assertSame(['nhk.dictionary.materialization.profile', 'nhk.dictionary.materialization.plan'], array_values(array_intersect($manifest['reads'], ['nhk.dictionary.materialization.profile', 'nhk.dictionary.materialization.plan'])));
        self::assertContains('nhk.dictionary.materialization.apply', $manifest['writes']);
        self::assertSame([], $manifest['unsupported']);
        self::assertContains('nhk.dictionary.materialization.apply', $manifest['internal_only_tools']);

        foreach (['profile', 'plan'] as $operation) {
            $tool = 'nhk.dictionary.materialization.' . $operation;
            $ability = McpAbilityRegistration::abilityNameForTool($tool);
            self::assertSame('nhk-v3/dictionary-materialization-' . $operation, $ability);
            self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler($tool));
            self::assertNotSame('', McpToolCatalog::schemaHash($tool));
            self::assertNotContains($ability, McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
            self::assertContains($ability, McpAbilityRegistration::operatorEnabledAbilityAllowlist(), 'Read materialization abilities must remain discoverable on the public/read surface.');
        }

        $apply = 'nhk.dictionary.materialization.apply';
        $applyAbility = McpAbilityRegistration::abilityNameForTool($apply);
        self::assertSame('nhk-v3/dictionary-materialization-apply', $applyAbility);
        self::assertTrue(SingleEntryPointPolicy::isInternalOnly($apply));
        self::assertSame('internal_admin_only', SingleEntryPointPolicy::surface($apply));
        self::assertContains($applyAbility, McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertNotContains($applyAbility, McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertSame(1, array_count_values(McpAbilityRegistration::abilityNames())[$applyAbility]);
        self::assertContains('nhk-v3/capture-ingest', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertSame('nhk.capture.ingest', SingleEntryPointPolicy::CANONICAL_TOOL);
        self::assertNotSame('nhk.capture.ingest', $apply);

        foreach (['nhk.dictionary.materialization.profile', 'nhk.dictionary.materialization.plan', 'nhk.dictionary.materialization.apply'] as $tool) {
            self::assertArrayHasKey($tool, $tools);
            self::assertArrayHasKey($tool, McpAbilityRegistration::exposureContract());
        }
    }

    public function test_entry_sense_and_enrichment_abilities_are_guarded_easy_mcp_opt_ins(): void
    {
        foreach ([
            'nhk-v3/dictionary-entry-create-with-sense',
            'nhk-v3/dictionary-entry-form-add',
            'nhk-v3/dictionary-entry-sense-add',
            'nhk-v3/dictionary-enrichment-apply',
        ] as $ability) {
            self::assertContains($ability, \NHK\Core\Application\Mcp\McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        }

        foreach ([
            'nhk-v3/dictionary-enrichment-audit',
            'nhk-v3/dictionary-enrichment-plan',
        ] as $ability) {
            self::assertContains($ability, \NHK\Core\Application\Mcp\McpAbilityRegistration::explicitInternalAdminReadOnlyAbilityAllowlist());
            self::assertContains($ability, \NHK\Core\Application\Mcp\McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        }

        self::assertNotContains('nhk-v3/dictionary-entry-create-with-sense', \NHK\Core\Application\Mcp\McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertNotContains('nhk-v3/dictionary-enrichment-apply', \NHK\Core\Application\Mcp\McpAbilityRegistration::operatorEnabledAbilityAllowlist());
    }
}
