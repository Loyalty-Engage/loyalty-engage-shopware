<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class OrderPlaceTask extends ScheduledTask
{
    /**
     * Run every 5 minutes
     */
    public static function getDefaultInterval(): int
    {
        return 300; // seconds
    }

    /**
     * Task name
     */
    public static function getTaskName(): string
    {
        return 'loyalty_engage.order_place_task';
    }
}
