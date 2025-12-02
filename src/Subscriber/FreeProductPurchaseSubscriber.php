<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class FreeProductPurchaseSubscriber implements EventSubscriberInterface
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $orderRepository;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $orderRepository,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onOrderCompleted'
        ];
    }

    public function onOrderCompleted(StateMachineTransitionEvent $event): void
    {
        try {
            if (
                $event->getEntityName() !== 'order' ||
                $event->getToPlace()->getTechnicalName() !== 'completed'
            ) {
                return;
            }

            $orderId = $event->getEntityId();

            // Use hardcoded live version UUID to ensure visibility
            $criteria = new Criteria([$orderId]);
            $criteria->addFilter(new EqualsFilter('versionId', '0fa91ce3e96a4bc2be4bd9ce752c3425'));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('orderCustomer');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
                $this->logger->error('Order not found', ['orderId' => $orderId]);
                return;
            }

            $customer = $order->getOrderCustomer();
            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('Order customer or email missing', ['orderId' => $orderId]);
                return;
            }

            $email = $customer->getEmail();
            $orderNumber = $order->getOrderNumber();
            $freeProducts = [];

            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                // Check if the product is free (price is 0)
                if ((float)$lineItem->getUnitPrice() === 0.0) {
                    if (!$lineItem->getProductId()) {
                        $this->logger->warning('Missing product ID on free line item', [
                            'orderId' => $orderId,
                            'lineItemId' => $lineItem->getId()
                        ]);
                        continue;
                    }

                    $freeProducts[] = [
                        'sku' => $lineItem->getProductId(),
                        'quantity' => (int) $lineItem->getQuantity()
                    ];
                }
            }

            if (empty($freeProducts)) {
                $this->logger->info('No free products found in order', ['orderId' => $orderId]);
                return;
            }

            // Create and dispatch a free product purchase message to be processed asynchronously
            $message = new FreeProductPurchaseMessage($email, $orderNumber, $freeProducts);
            
            $this->logger->info('Dispatching free product purchase message to queue', [
                'email' => $email,
                'orderId' => $orderNumber
            ]);
            
            $this->messageBus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('Error in FreeProductPurchaseSubscriber', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
