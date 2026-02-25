<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CartExpiryTask extends ScheduledTask
{
    /**
     * Run every minute
     */
    public static function getDefaultInterval(): int
    {
        return 60; // seconds
    }

    /**
     * Task name
     */
    public static function getTaskName(): string
    {
        return 'loyalty_engage.cart_expiry_task';
    }
}
