<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use InvalidArgumentException;
use NHK\Core\Contracts\Governance\AutomationPolicyStorage;
use NHK\Core\Domain\Governance\AutomationMode;

final class WpOptionAutomationPolicyStorage implements AutomationPolicyStorage
{
    /** @param list<string> $registeredTypes */
    public function __construct(
        private array $registeredTypes,
        private string $optionName = 'nhk_governance_automation_policy',
        private $getOption = null,
        private $updateOption = null,
    ) {}

    public function read(): array
    {
        $reader = $this->getOption;
        $value = $reader !== null
            ? $reader($this->optionName, [])
            : (function_exists('get_option') ? get_option($this->optionName, []) : []);
        if (!is_array($value)) return [];

        $result = [];
        foreach ($value as $type => $mode) {
            if (!is_string($type) || !in_array($type, $this->registeredTypes, true)) continue;
            if (!is_string($mode)) continue;
            $result[$type] = $mode;
        }
        return $result;
    }

    public function write(array $policies): void
    {
        $clean = [];
        foreach ($policies as $type => $mode) {
            if (!is_string($type) || !in_array($type, $this->registeredTypes, true)) throw new InvalidArgumentException('Unknown governance automation type: ' . (string) $type);
            if (!is_string($mode)) throw new InvalidArgumentException('Invalid governance automation mode.');
            $clean[$type] = AutomationMode::fromStored($mode)->value;
        }

        $writer = $this->updateOption;
        $result = $writer !== null
            ? $writer($this->optionName, $clean)
            : (function_exists('update_option') ? update_option($this->optionName, $clean, false) : false);
        if ($result === false) throw new \RuntimeException('GOVERNANCE_AUTOMATION_POLICY_SAVE_FAILED');
    }
}
