<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Psr\Log\LoggerInterface;

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
