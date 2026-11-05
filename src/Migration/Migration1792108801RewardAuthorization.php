<?php declare(strict_types=1);

namespace LoyaltyEngage\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1792108801RewardAuthorization extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1792108801; }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'le_reward_request', 'active')) {
            $connection->executeStatement('ALTER TABLE le_reward_request ADD active TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function updateDestructive(Connection $connection): void {}
}
