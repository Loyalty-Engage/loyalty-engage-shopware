<?php declare(strict_types=1);

namespace LoyaltyEngage\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1795046400DeliveryOutbox extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1795046400; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS le_delivery_outbox (
            id CHAR(64) NOT NULL PRIMARY KEY,
            kind VARCHAR(32) NOT NULL,
            payload LONGTEXT NOT NULL,
            sales_channel_id VARCHAR(32) NULL,
            retry_safe TINYINT(1) NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT \'pending\',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            last_status INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX le_delivery_due (state, next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function updateDestructive(Connection $connection): void {}
}
