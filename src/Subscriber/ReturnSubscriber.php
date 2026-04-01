<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ReturnSubscriber implements EventSubscriberInterface
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $orderDeliveryRepository;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $orderDeliveryRepository,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onDeliveryReturned'
        ];
    }

    public function onDeliveryReturned(StateMachineTransitionEvent $event): void
    {
        try {
            if (
                $event->getEntityName() !== 'order_delivery' ||
                $event->getToPlace()->getTechnicalName() !== 'returned'
            ) {
                return;
            }

            $deliveryId = $event->getEntityId();

            if (!$this->loyaltyEngageApiService->isReturnExportEnabled()) {
                return;
            }

            $criteria = new Criteria([$deliveryId]);
            $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $criteria->addAssociation('order');
            $criteria->addAssociation('order.lineItems');
            $criteria->addAssociation('order.orderCustomer');

            /** @var OrderDeliveryEntity|null $delivery */
            $delivery = $this->orderDeliveryRepository->search($criteria, $event->getContext())->first();

            if (!$delivery || !$delivery->getOrder()) {
                $this->logger->error('LoyaltyEngage: Delivery or order not found', ['deliveryId' => $deliveryId]);
                return;
            }

            /** @var OrderEntity $order */
            $order = $delivery->getOrder();
            $customer = $order->getOrderCustomer();

            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('LoyaltyEngage: Missing customer or email', ['orderId' => $order->getId()]);
                return;
            }

            $email = $customer->getEmail();
            $returnDate = (new \DateTime())->format(DATE_ATOM);
            $products = [];

            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                if (!$lineItem->getProductId()) {
                    $this->logger->warning('LoyaltyEngage: Missing product ID on line item', [
                        'orderId' => $order->getId(),
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
                $this->logger->error('LoyaltyEngage: No valid product line items found', ['orderId' => $order->getId()]);
                return;
            }

            $message = new ReturnMessage($email, $returnDate, $products);

            $this->logger->info('LoyaltyEngage: Dispatching return message to queue', [
                'returnDate' => $returnDate
            ]);

            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Fatal error in onDeliveryReturned()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
