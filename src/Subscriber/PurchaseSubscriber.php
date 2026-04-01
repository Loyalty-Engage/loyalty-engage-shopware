<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\PurchaseMessage;
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

class PurchaseSubscriber implements EventSubscriberInterface
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

            if (!$this->loyaltyEngageApiService->isPurchaseExportEnabled()) {
                return;
            }

            $criteria = new Criteria([$orderId]);
            $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('orderCustomer');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
                $this->logger->error('LoyaltyEngage: Order not found for purchase event', ['orderId' => $orderId]);
                return;
            }

            $customer = $order->getOrderCustomer();
            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('LoyaltyEngage: Order customer or email missing', ['orderId' => $orderId]);
                return;
            }

            $email = $customer->getEmail();
            $orderNumber = $order->getOrderNumber();
            $orderDate = $order->getOrderDateTime()?->format(DATE_ATOM) ?? '';
            $products = [];

            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                if (!$lineItem->getProductId()) {
                    $this->logger->warning('LoyaltyEngage: Missing product ID on line item', [
                        'orderId' => $orderId,
                        'lineItemId' => $lineItem->getId()
                    ]);
                    continue;
                }

                $products[] = [
                    'sku' => $lineItem->getProductId(),
                    'price' => (float) $lineItem->getUnitPrice(),
                    'quantity' => (int) $lineItem->getQuantity()
                ];
            }

            if (empty($products)) {
                $this->logger->error('LoyaltyEngage: No valid product line items found', ['orderId' => $orderId]);
                return;
            }

            $message = new PurchaseMessage($email, $orderNumber, $orderDate, $products);

            $this->logger->info('LoyaltyEngage: Dispatching purchase message to queue', [
                'orderId' => $orderNumber
            ]);

            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Fatal error in onOrderCompleted()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
