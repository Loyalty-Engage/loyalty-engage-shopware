<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * CartExpiryTaskHandler — intentionally disabled.
 *
 * The original implementation called removeAllItems() for every order older than
 * the configured expiry time. This caused a burst of API calls on every scheduled
 * task run (one call per order in the database), which triggered rate-limiting
 * (HTTP 429) on the LoyaltyEngage DELETE /cart endpoint.
 *
 * Loyalty cart cleanup is now handled exclusively by the FreeProductRemoveSubscriber
 * which fires only when a customer explicitly removes a product from the cart.
 */
class CartExpiryTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public static function getHandledMessages(): iterable
    {
        return [CartExpiryTask::class];
    }

    public function run(): void
    {
        // Intentionally empty — see class docblock above.
    }
}
