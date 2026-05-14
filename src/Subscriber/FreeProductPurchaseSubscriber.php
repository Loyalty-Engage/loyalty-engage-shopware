<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Triggers the LoyaltyEngage cart/purchase API call when an order is placed.
 *
 * We listen on CheckoutOrderPlacedEvent (order placed) instead of the
 * StateMachineTransitionEvent (order completed) because the purchase must be
 * finalised in LoyaltyEngage immediately after checkout so that reserved points
 * are converted to spent points right away.
 */
class FreeProductPurchaseSubscriber implements EventSubscriberInterface
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
        $this->messageBus              = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        try {
            $order    = $event->getOrder();
            $customer = $order->getOrderCustomer();

            if ($customer === null || !$customer->getEmail()) {
                $this->logger->error('FreeProductPurchaseSubscriber: Order customer or email missing', [
                    'orderId' => $order->getId(),
                ]);
                return;
            }

            $email       = $customer->getEmail();
            $orderNumber = $order->getOrderNumber();
            $freeProducts = [];

            $lineItems = $order->getLineItems();
            if ($lineItems === null) {
                return;
            }

            foreach ($lineItems as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                // Only process loyalty free products (marked by LoyaltyShopService)
                $payload = $lineItem->getPayload() ?? [];
                if (($payload['loyaltyFreeProduct'] ?? false) !== true) {
                    continue;
                }

                // The LoyaltyEngage API expects the SKU (productNumber), not the Shopware UUID
                $sku = $payload['productNumber'] ?? null;

                if (!$sku) {
                    $this->logger->warning('FreeProductPurchaseSubscriber: Missing productNumber in loyalty line item payload', [
                        'orderId'    => $order->getId(),
                        'lineItemId' => $lineItem->getId(),
                    ]);
                    continue;
                }

                $freeProducts[] = [
                    'sku'      => $sku,
                    'quantity' => (int) $lineItem->getQuantity(),
                ];
            }

            if (empty($freeProducts)) {
                return;
            }

            $message = new FreeProductPurchaseMessage($email, $orderNumber, $freeProducts);

            $this->logger->info('FreeProductPurchaseSubscriber: Dispatching cart/purchase message', [
                'orderNumber'  => $orderNumber,
                'email'        => $email,
                'freeProducts' => $freeProducts,
            ]);

            $this->messageBus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('FreeProductPurchaseSubscriber: Error handling order placed event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
