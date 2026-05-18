<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Scheduled task that retries failed return events towards the LoyaltyEngage API.
 *
 * Runs every 5 minutes and picks up orders where:
 *   - loyalty_return_sent = false  (event was not yet successfully delivered)
 *   - loyalty_return_retry_count < configured limit
 */
class ReturnEventRetryTask extends ScheduledTask
{
    public static function getDefaultInterval(): int
    {
        return 300; // 5 minutes
    }

    public static function getTaskName(): string
    {
        return 'loyalty_engage.return_event_retry_task';
    }
}
