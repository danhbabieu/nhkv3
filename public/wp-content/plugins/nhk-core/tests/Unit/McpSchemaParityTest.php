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
            self::assertSchemaParity($catalogSchema, $abilitySchema, $toolName, $toolName === 'nhk.capture.ingest'
                ? ['nhk.capture.ingest.properties.files', 'nhk.capture.ingest.properties.relationship_operations.items.oneOf', 'nhk.capture.ingest.properties.relationship_operations.items.required']
                : []);
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

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual @param list<string> $ignored */
    private static function assertSchemaParity(array $expected, array $actual, string $path, array $ignored): void
    {
        if (in_array($path, $ignored, true)) return;

        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        foreach ($keys as $key) {
            $childPath = $path . '.' . $key;
            if (in_array($childPath, $ignored, true)) continue;
            if ($key === 'properties' || $key === 'patternProperties' || $key === '$defs' || $key === 'definitions') {
                $expectedProperties = $expected[$key] ?? [];
                $actualProperties = $actual[$key] ?? [];
                self::assertSame(array_keys($expectedProperties), array_keys($actualProperties), $childPath);
                foreach (array_keys($expectedProperties) as $property) {
                    self::assertSchemaParity($expectedProperties[$property], $actualProperties[$property], $childPath . '.' . $property, $ignored);
                }
                continue;
            }
            if (in_array($key, ['items', 'additionalProperties', 'contains', 'propertyNames', 'not'], true)) {
                if (is_array($expected[$key] ?? null) && is_array($actual[$key] ?? null)) {
                    self::assertSchemaParity($expected[$key], $actual[$key], $childPath, $ignored);
                } else {
                    self::assertSame($expected[$key] ?? null, $actual[$key] ?? null, $childPath);
                }
                continue;
            }
            if (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true)) {
                self::assertCount(count($expected[$key] ?? []), $actual[$key] ?? [], $childPath);
                foreach (array_keys($expected[$key] ?? []) as $index) {
                    self::assertSchemaParity($expected[$key][$index], $actual[$key][$index], $childPath . '.' . $index, $ignored);
                }
                continue;
            }
            self::assertSame($expected[$key] ?? null, $actual[$key] ?? null, $childPath);
        }
    }
}
