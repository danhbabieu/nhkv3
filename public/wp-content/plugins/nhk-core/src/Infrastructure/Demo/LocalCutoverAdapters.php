<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\StageResult;
use NHK\Core\Contracts\Demo\CutoverPorts;

final class LocalCutoverAdapters
{
    public static function forRepository(string $root): CutoverPorts
    {
        $safety = static function (DemoCutoverContext $context) use ($root): StageResult {
            $manifest = $root . '/docs/semantic-packs/' . $context->pack . '/' . strtoupper($context->pack) . '_INGEST_MANIFEST.yaml';
            return is_dir($root) && is_file($root . '/composer.lock') && is_file($root . '/public/wp-content/plugins/nhk-core/nhk-core.php')
                ? (is_file($manifest) ? StageResult::pass() : StageResult::blocked('PACK_MANIFEST_UNAVAILABLE'))
                : StageResult::blocked('LOCAL_SAFETY_FAILED');
        };
        $deployment = RemoteDeploymentAdapter::fromEnvironment($root);
        $deploy = static fn (DemoCutoverContext $context): StageResult => $deployment->deploy($context);
        $unavailable = static fn (): StageResult => StageResult::blocked('REMOTE_RUNTIME_ADAPTER_UNAVAILABLE');
        $pass = static fn (): StageResult => StageResult::pass();
        return new CutoverPorts(
            $safety, $deploy, $pass, $unavailable, $unavailable, $unavailable, $unavailable,
            static fn (): StageResult => StageResult::blocked('LIVE_PLANNER_ADAPTER_UNAVAILABLE'),
            static fn (): StageResult => StageResult::blocked('GOVERNANCE_ADAPTER_UNAVAILABLE'),
            static fn (): StageResult => StageResult::blocked('HUMAN_APPROVAL_REQUIRED'),
            static fn (): StageResult => StageResult::blocked('GOVERNANCE_ADAPTER_UNAVAILABLE'),
            static fn (): StageResult => StageResult::blocked('CONTROLLED_APPLY_ADAPTER_UNAVAILABLE'),
            static fn (): StageResult => StageResult::blocked('READBACK_ADAPTER_UNAVAILABLE'),
            static fn (): StageResult => StageResult::pass(),
        );
    }
}
