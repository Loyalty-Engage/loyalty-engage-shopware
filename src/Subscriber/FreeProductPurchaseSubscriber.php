<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
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

            $criteria = new Criteria([$orderId]);
            $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('orderCustomer');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
                $this->logger->error('LoyaltyEngage: Order not found for free product event', ['orderId' => $orderId]);
                return;
            }

            $customer = $order->getOrderCustomer();
            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('LoyaltyEngage: Order customer or email missing', ['orderId' => $orderId]);
                return;
            }

            $email = $customer->getEmail();
            $orderNumber = $order->getOrderNumber();
            $freeProducts = [];

            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                // Check if the product is a loyalty free product via the payload flag.
                // This is more reliable than checking the price (which could be 0 for other reasons).
                $payload = $lineItem->getPayload() ?? [];
                if (($payload['loyaltyFreeProduct'] ?? false) !== true) {
                    continue;
                }

                if (!$lineItem->getProductId()) {
                    $this->logger->warning('LoyaltyEngage: Missing product ID on loyalty line item', [
                        'orderId' => $orderId,
                        'lineItemId' => $lineItem->getId()
                    ]);
                    continue;
                }

                // Use productNumber (SKU) from payload — the LoyaltyEngage API expects the SKU,
                // not the Shopware internal UUID.
                $sku = $payload['productNumber'] ?? null;

                if (!$sku) {
                    $this->logger->warning('LoyaltyEngage: Missing productNumber in loyalty line item payload', [
                        'orderId' => $orderId,
                        'lineItemId' => $lineItem->getId()
                    ]);
                    continue;
                }

                $freeProducts[] = [
                    'sku' => $sku,
                    'quantity' => (int) $lineItem->getQuantity()
                ];
            }

            if (empty($freeProducts)) {
                return;
            }

            $message = new FreeProductPurchaseMessage($email, $orderNumber, $freeProducts);

            $this->logger->info('LoyaltyEngage: Dispatching free product purchase message to queue', [
                'orderId' => $orderNumber
            ]);

            $this->messageBus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Error in FreeProductPurchaseSubscriber', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
