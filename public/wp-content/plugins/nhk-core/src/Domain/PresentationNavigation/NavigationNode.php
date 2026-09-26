<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PresentationNavigation;

final class NavigationNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $navigationKey,
        public readonly string $canonicalType,
        public readonly string $canonicalUuid,
        public readonly ?string $parentId,
        public readonly int $sortOrder,
        public readonly bool $enabled,
        public readonly bool $showInTypeIndex,
        public readonly bool $showInHeaderMenu,
        public readonly bool $showInMobileMenu,
        public readonly bool $showInSidebar,
        public readonly bool $featured,
        public readonly int $revision,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['navigation_key'] ?? ''),
            (string) ($row['canonical_type'] ?? ''),
            (string) ($row['canonical_uuid'] ?? ''),
            ($row['parent_id'] ?? null) === null || (string) $row['parent_id'] === '' ? null : (string) $row['parent_id'],
            max(0, (int) ($row['sort_order'] ?? 0)),
            (bool) ($row['enabled'] ?? false),
            (bool) ($row['show_in_type_index'] ?? false),
            (bool) ($row['show_in_header_menu'] ?? false),
            (bool) ($row['show_in_mobile_menu'] ?? false),
            (bool) ($row['show_in_sidebar'] ?? false),
            (bool) ($row['featured'] ?? false),
            max(1, (int) ($row['revision'] ?? 1)),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'navigation_key' => $this->navigationKey,
            'canonical_type' => $this->canonicalType,
            'canonical_uuid' => $this->canonicalUuid,
            'parent_id' => $this->parentId,
            'sort_order' => $this->sortOrder,
            'enabled' => $this->enabled,
            'show_in_type_index' => $this->showInTypeIndex,
            'show_in_header_menu' => $this->showInHeaderMenu,
            'show_in_mobile_menu' => $this->showInMobileMenu,
            'show_in_sidebar' => $this->showInSidebar,
            'featured' => $this->featured,
            'revision' => $this->revision,
        ];
    }

    public function visibleAt(string $placement): bool
    {
        return $this->enabled && (bool) $this->{match (NavigationPlacement::field($placement)) {
            'show_in_type_index' => 'showInTypeIndex',
            'show_in_header_menu' => 'showInHeaderMenu',
            'show_in_mobile_menu' => 'showInMobileMenu',
            'show_in_sidebar' => 'showInSidebar',
        }};
    }
}
