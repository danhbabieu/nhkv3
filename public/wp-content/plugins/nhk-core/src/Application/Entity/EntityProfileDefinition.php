<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/** Read-only profile metadata; it is not an Authority or Graph definition. */
final readonly class EntityProfileDefinition
{
    /**
     * @param array<string,string> $matchingRule
     * @param list<string> $supportedDossierSections
     * @param list<string> $relationTargetGroups
     * @param list<string> $readFamilyAliases
     * @param array<string,mixed> $archiveNavigationIntent
     * @param array<string,mixed> $rootDetailRouteIntent
     * @param list<string> $capabilities
     * @param array<string,mixed> $presentation
     */
    public function __construct(
        public string $key,
        public string $visitorLabel,
        public string $adminLabel,
        public array $matchingRule,
        public array $supportedDossierSections,
        public string $relationQueryRecipe,
        public array $relationTargetGroups,
        public array $readFamilyAliases,
        public array $archiveNavigationIntent,
        public array $rootDetailRouteIntent,
        public array $capabilities,
        public array $presentation = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'visitor_label' => $this->visitorLabel,
            'admin_label' => $this->adminLabel,
            'matching_rule' => $this->matchingRule,
            'supported_dossier_sections' => $this->supportedDossierSections,
            'relation_query_recipe' => $this->relationQueryRecipe,
            'relation_target_groups' => $this->relationTargetGroups,
            'read_family_aliases' => $this->readFamilyAliases,
            'archive_navigation_intent' => $this->archiveNavigationIntent,
            'root_detail_route_intent' => $this->rootDetailRouteIntent,
            'capabilities' => $this->capabilities,
            'presentation' => $this->presentation,
        ];
    }
}
