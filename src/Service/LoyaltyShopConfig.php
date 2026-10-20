<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class LoyaltyShopConfig
{
    public function __construct(private readonly SystemConfigService $config) {}

    public function get(string $name, string $salesChannelId, $default = null)
    {
        $value = $this->config->get('LoyaltyEngage.config.' . $name, $salesChannelId);
        if ($value === null || $value === '') {
            $value = $this->config->get('LoyaltyEngage.config.' . $name);
        }
        return $value === null || $value === '' ? $default : $value;
    }

    public function enabled(string $salesChannelId): bool
    {
        return (bool) $this->get('shopEnabled', $salesChannelId, false);
    }

    /** Tier order is configured explicitly; remote IDs are not ranks. */
    public function tiers(string $salesChannelId): array
    {
        $tiers = [];
        foreach (preg_split('/\R/', (string) $this->get('shopTiers', $salesChannelId, '')) as $line) {
            $parts = explode('|', trim($line), 2);
            if (count($parts) === 2 && ctype_digit($parts[0]) && (int) $parts[0] > 0 && trim($parts[1]) !== '') {
                $tiers[(int) $parts[0]] = trim($parts[1]);
            }
        }
        return $tiers;
    }
}
