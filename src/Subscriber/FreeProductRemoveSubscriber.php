<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\LineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
            $context = $event->getContext();
            
            // Only process product line items with zero price
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return;
            }
            
            // Check if the product is free (price is 0)
            if ((float)$lineItem->getPrice()->getUnitPrice() !== 0.0) {
                return;
            }
            
            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();
            
            if (!$customer) {
                $this->logger->warning('Cannot determine customer for free product removal');
                return;
            }
            
            $email = $customer->getEmail();
            $productId = $lineItem->getReferencedId();
            $quantity = (int)$lineItem->getQuantity();
            
            if (!$email || !$productId) {
                $this->logger->warning('Missing email or product ID for free product removal', [
                    'email' => $email,
                    'productId' => $productId
                ]);
                return;
            }
            
            // Create and dispatch a free product remove message to be processed asynchronously
            $message = new FreeProductRemoveMessage($email, $productId, $quantity);
            
            $this->logger->info('Dispatching free product remove message to queue', [
                'email' => $email,
                'productId' => $productId,
                'quantity' => $quantity
            ]);
            
            $this->messageBus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('Error in FreeProductRemoveSubscriber', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
