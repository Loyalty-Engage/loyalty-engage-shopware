<?php declare(strict_types=1);

namespace LoyaltyEngage\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1792108800LoyaltyShopProducts extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1792108800; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS le_reward_request (
            id VARCHAR(64) NOT NULL PRIMARY KEY, response JSON NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $setId = $connection->fetchOne('SELECT id FROM custom_field_set WHERE name = :name', ['name' => 'le_loyalty_products']);
        if (!$setId) {
            $setId = Uuid::randomBytes();
            $connection->insert('custom_field_set', [
                'id' => $setId, 'name' => 'le_loyalty_products', 'active' => 1,
                'config' => json_encode(['label' => ['en-GB' => 'Loyalty shop', 'nl-NL' => 'Loyaltywinkel']]),
                'created_at' => date('Y-m-d H:i:s.v'),
            ]);
            $connection->insert('custom_field_set_relation', [
                'id' => Uuid::randomBytes(), 'set_id' => $setId, 'entity_name' => 'product', 'created_at' => date('Y-m-d H:i:s.v'),
            ]);
        }
        $definitions = [
            ['le_shop_enabled', 'bool', 'sw-field', 'checkbox', 'Loyalty reward', 'Loyaltybeloning'],
            ['le_shop_coin_price', 'int', 'sw-field', 'number', 'Coin price (positive integer)', 'Puntenprijs (geheel getal)'],
            ['le_shop_type', 'select', 'sw-single-select', null, 'Reward type', 'Beloningstype'],
            ['le_shop_tier_id', 'int', 'sw-field', 'number', 'Minimum tier ID (when tiers enabled)', 'Minimaal tier-ID (bij ingeschakelde tiers)'],
            ['le_shop_regular_purchase', 'bool', 'sw-field', 'checkbox', 'Also allow regular purchases', 'Ook reguliere aankoop toestaan'],
            ['le_shop_discount_value', 'float', 'sw-field', 'number', 'Discount value (server-side fallback)', 'Kortingswaarde (server-side terugval)'],
            ['le_shop_discount_type', 'select', 'sw-single-select', null, 'Discount type', 'Kortingstype'],
        ];
        foreach ($definitions as $position => [$name, $type, $component, $fieldType, $en, $nl]) {
            if ($connection->fetchOne('SELECT id FROM custom_field WHERE name = :name', ['name' => $name])) {
                continue;
            }
            $config = ['label' => ['en-GB' => $en, 'nl-NL' => $nl], 'componentName' => $component, 'customFieldPosition' => $position + 1];
            if ($fieldType) { $config['type'] = $fieldType; }
            if ($name === 'le_shop_type' || $name === 'le_shop_discount_type') {
                $values = $name === 'le_shop_type' ? ['physical', 'discount_code'] : ['absolute', 'percentage'];
                $config['options'] = array_map(static fn (string $value): array => ['value' => $value, 'label' => ['en-GB' => $value, 'nl-NL' => $value]], $values);
            }
            $connection->insert('custom_field', [
                'id' => Uuid::randomBytes(), 'name' => $name, 'type' => $type, 'active' => 1,
                'config' => json_encode($config), 'set_id' => $setId,
                'created_at' => date('Y-m-d H:i:s.v'),
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void {}
}
