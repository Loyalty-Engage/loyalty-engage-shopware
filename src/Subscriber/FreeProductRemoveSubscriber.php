<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\LineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class FreeProductRemoveSubscriber implements EventSubscriberInterface
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
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LineItemRemovedEvent::class => 'onLineItemRemoved'
        ];
    }

    public function onLineItemRemoved(LineItemRemovedEvent $event): void
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

            $email     = $customer->getEmail();
            $productId = $lineItem->getReferencedId();
            $quantity  = (int) $lineItem->getQuantity();

            // The LoyaltyEngage API expects the product SKU (productNumber), not the Shopware UUID.
            // The productNumber is stored in the cart line item payload by Shopware.
            $sku = $payload['productNumber'] ?? null;

            if (!$email || !$productId) {
                $this->logger->warning('FreeProductRemoveSubscriber: Missing email or product ID for free product removal', [
                    'email'     => $email,
                    'productId' => $productId,
                ]);
                return;
            }

            if (!$sku) {
                $this->logger->warning('FreeProductRemoveSubscriber: Could not resolve SKU from cart payload, falling back to productId', [
                    'productId' => $productId,
                ]);
                // Fall back to productId so the message is still dispatched (API call may fail, but we log it)
                $sku = $productId;
            }

            // Dispatch an async message so the cart response is not delayed by the API call
            $message = new FreeProductRemoveMessage($email, $productId, $sku, $quantity);

            $this->logger->info('FreeProductRemoveSubscriber: Dispatching free product remove message', [
                'email'     => $email,
                'productId' => $productId,
                'sku'       => $sku,
                'quantity'  => $quantity,
            ]);

            $this->messageBus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('FreeProductRemoveSubscriber: Error handling line item removal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
