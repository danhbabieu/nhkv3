<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

use NHK\Core\Contracts\PresentationNavigation\NavigationRepository;
use NHK\Core\Domain\PresentationNavigation\NavigationNode;

final class ClockTypeNavigationSeed
{
    /** @var list<string> */
    public const INITIAL_LABELS = ['Đồng hồ tủ', 'Đồng hồ treo tường', 'Đồng hồ Pháp', 'Đồng hồ Đức', 'Đồng hồ chim cúc cu', 'Đồng hồ 400 ngày', 'Đồng hồ công cộng', 'Đồng hồ vai bò', 'Đồng hồ để bàn'];

    /** @param callable(string):list<array<string,mixed>> $resolver */
    public function __construct(private NavigationRepository $repository, private $resolver) {}

    /** @return list<array<string,mixed>> */
    public function seed(): array
    {
        $existing = [];
        foreach ($this->repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY) as $node) $existing[$node->canonicalUuid] = $node;
        $reports = [];
        foreach (self::INITIAL_LABELS as $index => $label) {
            $matches = ($this->resolver)($label);
            $valid = array_values(array_filter($matches, static fn (array $candidate): bool => ($candidate['canonical_type'] ?? '') === 'classification' && ($candidate['family'] ?? '') === 'clock_type' && ($candidate['active'] ?? false) === true));
            if (count($valid) !== 1) {
                $reports[] = ['label' => $label, 'status' => $matches === [] ? 'REVIEW_REQUIRED' : 'BLOCKED'];
                continue;
            }
            $uuid = (string) $valid[0]['canonical_uuid'];
            if (isset($existing[$uuid])) {
                $reports[] = ['label' => $label, 'canonical_uuid' => $uuid, 'status' => 'ALREADY_PRESENT'];
                continue;
            }
            $node = NavigationNode::fromArray([
                'navigation_key' => ClockTypeNavigationProjection::NAVIGATION_KEY,
                'canonical_type' => 'classification',
                'canonical_uuid' => $uuid,
                'sort_order' => $index * 10,
                'enabled' => true,
                'show_in_type_index' => true,
                'show_in_header_menu' => true,
                'show_in_mobile_menu' => true,
                'show_in_sidebar' => true,
                'featured' => $index < 3,
                'revision' => 1,
            ]);
            $saved = $this->repository->save($node, 0);
            $existing[$uuid] = $saved;
            $reports[] = ['label' => $label, 'canonical_uuid' => $uuid, 'status' => 'SEEDED'];
        }
        return $reports;
    }
}
