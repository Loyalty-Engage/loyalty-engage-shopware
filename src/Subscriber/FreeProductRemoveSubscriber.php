<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class FreeProductRemoveSubscriber implements EventSubscriberInterface
{
    /**
     * Delay in milliseconds before the removeAllItems API call is made.
     *
     * A 5-second delay ensures that if a customer removes multiple loyalty
     * products in quick succession, all removals are batched into a single
     * removeAllItems() call instead of firing one API call per product.
     * This prevents rate-limiting (HTTP 429) on the LoyaltyEngage delete endpoint.
     */
    private const REMOVE_DELAY_MS = 5000;

    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemRemovedEvent::class => 'onLineItemRemoved',
        ];
    }

    public function onLineItemRemoved(BeforeLineItemRemovedEvent $event): void
    {
        try {
            $lineItem = $event->getLineItem();

            // Only process product line items
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return;
            }

            // Only process loyalty free products (marked by LoyaltyShopService)
            $payload = $lineItem->getPayload() ?? [];
            if (($payload['loyaltyFreeProduct'] ?? false) !== true) {
                return;
            }

            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();

            if (!$customer) {
                $this->logger->warning('FreeProductRemoveSubscriber: Cannot determine customer for free product removal');
                return;
            }

            $email = $customer->getEmail();

            if (!$email) {
                $this->logger->warning('FreeProductRemoveSubscriber: Missing email for free product removal');
                return;
            }

            if (($payload['leManagedReward'] ?? false) === true) {
                return;
            }

            // Dispatch a delayed removeAllItems message.
            // The delay batches rapid successive removals into a single API call,
            // preventing rate-limiting on the LoyaltyEngage delete endpoint.
            $message = new FreeProductRemoveMessage($email, $salesChannelContext->getSalesChannelId());

            $this->logger->info('FreeProductRemoveSubscriber: Dispatching removeAllItems message (delayed)', [
                'email'   => $email,
                'delayMs' => self::REMOVE_DELAY_MS,
            ]);

            $this->messageBus->dispatch($message, [new DelayStamp(self::REMOVE_DELAY_MS)]);
        } catch (\Throwable $e) {
            $this->logger->error('FreeProductRemoveSubscriber: Error handling line item removal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
