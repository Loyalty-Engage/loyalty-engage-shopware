<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\ReturnMessage;
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

        file_put_contents('/tmp/return_subscriber.txt', '🔧 ReturnSubscriber constructed at ' . date('c') . "\n", FILE_APPEND);
    }

    public static function getSubscribedEvents(): array
    {
        file_put_contents('/tmp/return_subscriber.txt', "📌 getSubscribedEvents triggered\n", FILE_APPEND);
        return [
            StateMachineTransitionEvent::class => 'onDeliveryReturned'
        ];
    }

    public function onDeliveryReturned(StateMachineTransitionEvent $event): void
    {
        file_put_contents('/tmp/return_subscriber.txt', "🚀 onDeliveryReturned called\n", FILE_APPEND);

        try {
            if (
                $event->getEntityName() !== 'order_delivery' ||
                $event->getToPlace()->getTechnicalName() !== 'returned'
            ) {
                file_put_contents('/tmp/return_subscriber.txt', "⛔️ Not a delivery/returned transition. Skipping.\n", FILE_APPEND);
                return;
            }

            $deliveryId = $event->getEntityId();
            file_put_contents('/tmp/return_subscriber.txt', "➡️ Delivery ID: $deliveryId\n", FILE_APPEND);

            if (!$this->loyaltyEngageApiService->isReturnExportEnabled()) {
                file_put_contents('/tmp/return_subscriber.txt', "🚫 Return export disabled. Exiting early.\n", FILE_APPEND);
                return;
            }

            file_put_contents('/tmp/return_subscriber.txt', "✅ Return export is ENABLED.\n", FILE_APPEND);

            $criteria = new Criteria([$deliveryId]);
            $criteria->addFilter(new EqualsFilter('versionId', '0fa91ce3e96a4bc2be4bd9ce752c3425'));
            $criteria->addAssociation('order');
            $criteria->addAssociation('order.lineItems');
            $criteria->addAssociation('order.orderCustomer');

            $delivery = $this->orderDeliveryRepository->search($criteria, $event->getContext())->first();

            if (!$delivery || !$delivery->getOrder()) {
                file_put_contents('/tmp/return_subscriber.txt', "❌ Delivery or order not found\n", FILE_APPEND);
                $this->logger->error('Delivery or order not found', ['deliveryId' => $deliveryId]);
                return;
            }

            /** @var OrderEntity $order */
            $order = $delivery->getOrder();
            $customer = $order->getOrderCustomer();

            if (!$customer || !$customer->getEmail()) {
                file_put_contents('/tmp/return_subscriber.txt', "❌ Customer or email missing\n", FILE_APPEND);
                $this->logger->error('Missing customer or email', ['orderId' => $order->getId()]);
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
                    file_put_contents('/tmp/return_subscriber.txt', "⚠️ Line item missing product ID\n", FILE_APPEND);
                    $this->logger->warning('Missing product ID on line item', [
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
                file_put_contents('/tmp/return_subscriber.txt', "❌ No valid products found\n", FILE_APPEND);
                $this->logger->error('No valid product line items', ['orderId' => $order->getId()]);
                return;
            }

            // Create and dispatch a return message to be processed asynchronously
            $message = new ReturnMessage($email, $returnDate, $products);
            
            $this->logger->info('Dispatching return message to queue', [
                'email' => $email,
                'returnDate' => $returnDate
            ]);
            
            file_put_contents('/tmp/return_subscriber.txt', "✅ Dispatching return message to queue\n", FILE_APPEND);
            
            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            file_put_contents('/tmp/return_subscriber.txt', "❌ Crash: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->logger->error('Fatal error in onDeliveryReturned()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
