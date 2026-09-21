<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog};
use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use PHPUnit\Framework\TestCase;

final class McpSchemaParityTest extends TestCase
{
    public function testRegisteredAbilitySchemasAreCatalogProjections(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        foreach ($catalog as $toolName => $tool) {
            $abilityName = McpAbilityRegistration::abilityNameForTool($toolName);
            if ($abilityName === null) continue;
            $catalogSchema = $tool['inputSchema'];
            $abilitySchema = McpAbilityRegistration::inputSchemaForTool($toolName);
            if ($toolName === 'nhk.media.ingest') foreach (['file', 'filename', 'max_width', 'max_height', 'quality'] as $property) unset($catalogSchema['properties'][$property]);
            self::assertSame(array_keys($catalogSchema['properties'] ?? []), array_keys($abilitySchema['properties'] ?? []), $toolName);
            self::assertSame($catalogSchema['required'] ?? [], $abilitySchema['required'] ?? [], $toolName);
            foreach (array_keys($catalogSchema['properties'] ?? []) as $property) {
                if ($toolName === 'nhk.capture.ingest' && $property === 'files') continue;
                self::assertSame($catalogSchema['properties'][$property], $abilitySchema['properties'][$property], $toolName . '.' . $property);
            }
        }
    }

    public function testEasyMcpProjectsEveryRegisteredNhkConnectorFromTheCatalog(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        $connectorTools = [];
        foreach ($catalog as $toolName => $tool) {
            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            if ($ability === null) continue;
            if (in_array($toolName, ['nhk.media.upload-widget.open', 'nhk.media.widget-upload'], true)) continue;
            if (in_array($toolName, ['nhk.media.upload-widget.open', 'nhk.media.widget-upload'], true)) continue;
            $connectorTools[] = ['name' => McpAbilityRegistration::connectorToolNameForAbility($ability), 'description' => 'stale', 'inputSchema' => ['type' => 'object', 'properties' => ['stale' => ['type' => 'string']]]];
        }
        $projected = array_column(EasyMcpNativeFileCompatibilityAdapter::projectTools($connectorTools), null, 'name');
        foreach ($catalog as $toolName => $tool) {
            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            if ($ability === null) continue;
            if (in_array($toolName, ['nhk.media.upload-widget.open', 'nhk.media.widget-upload'], true)) continue;
            $connector = McpAbilityRegistration::connectorToolNameForAbility($ability);
            self::assertJsonStringEqualsJsonString(
                json_encode(EasyMcpNativeFileCompatibilityAdapter::normalizeFinalInputSchema($tool['inputSchema']), JSON_THROW_ON_ERROR),
                json_encode($projected[$connector]['inputSchema'], JSON_THROW_ON_ERROR),
                $toolName,
            );
            self::assertSame(McpToolCatalog::schemaHash($toolName), $projected[$connector]['_meta']['nhk/schemaHash'], $toolName);
        }
    }

    public function testSchemaIdentityIsDeterministicAndCatalogOwned(): void
    {
        $hashes = McpToolCatalog::schemaHashes();
        self::assertSame(count(McpToolCatalog::names()), count($hashes));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashes['nhk.capture.ingest']);
        self::assertSame($hashes['nhk.capture.ingest'], McpToolCatalog::schemaHash('nhk.capture.ingest'));
    }
}
