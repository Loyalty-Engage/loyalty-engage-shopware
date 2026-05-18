<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Scheduled task that retries failed purchase events towards the LoyaltyEngage API.
 *
 * Runs every 5 minutes and picks up orders where:
 *   - loyalty_purchase_sent = false  (event was not yet successfully delivered)
 *   - loyalty_purchase_retry_count < configured limit
 */
class PurchaseEventRetryTask extends ScheduledTask
{
    public static function getDefaultInterval(): int
    {
        return 300; // 5 minutes
    }

    public static function getTaskName(): string
    {
        return 'loyalty_engage.purchase_event_retry_task';
    }
}
