<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\PurchaseMessage;
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

        file_put_contents('/tmp/purchase_subscriber.txt', '🔧 Subscriber constructed at ' . date('c') . "\n", FILE_APPEND);
    }

    public static function getSubscribedEvents(): array
    {
        file_put_contents('/tmp/purchase_subscriber.txt', "📌 getSubscribedEvents triggered\n", FILE_APPEND);
        return [
            StateMachineTransitionEvent::class => 'onOrderCompleted'
        ];
    }

    public function onOrderCompleted(StateMachineTransitionEvent $event): void
    {
        file_put_contents('/tmp/purchase_subscriber.txt', "🚀 onOrderCompleted called\n", FILE_APPEND);
        try {
            if (
                $event->getEntityName() !== 'order' ||
                $event->getToPlace()->getTechnicalName() !== 'completed'
            ) {
                file_put_contents('/tmp/purchase_subscriber.txt', "⛔️ Not an order/completed transition. Skipping.\n", FILE_APPEND);
                return;
            }

            $orderId = $event->getEntityId();
            file_put_contents('/tmp/purchase_subscriber.txt', "➡️ Order ID: $orderId\n", FILE_APPEND);

            $versionId = $event->getContext()->getVersionId();
            file_put_contents('/tmp/purchase_subscriber.txt', "🧪 Event context versionId: $versionId\n", FILE_APPEND);

            if (!$this->loyaltyEngageApiService->isPurchaseExportEnabled()) {
                file_put_contents('/tmp/purchase_subscriber.txt', "🚫 Purchase export disabled. Exiting early.\n", FILE_APPEND);
                return;
            }

            file_put_contents('/tmp/purchase_subscriber.txt', "✅ Purchase export is ENABLED.\n", FILE_APPEND);

            // ✅ Use hardcoded live version UUID to ensure visibility
            $criteria = new Criteria([$orderId]);
            $criteria->addFilter(new EqualsFilter('versionId', '0fa91ce3e96a4bc2be4bd9ce752c3425'));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('orderCustomer');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
                file_put_contents('/tmp/purchase_subscriber.txt', "❌ Order still not found!\n", FILE_APPEND);
                $this->logger->error('Order not found', ['orderId' => $orderId]);
                return;
            }

            file_put_contents('/tmp/purchase_subscriber.txt', "✅ Order found: " . $order->getOrderNumber() . "\n", FILE_APPEND);

            $customer = $order->getOrderCustomer();
            if (!$customer || !$customer->getEmail()) {
                file_put_contents('/tmp/purchase_subscriber.txt', "❌ Customer or email missing\n", FILE_APPEND);
                $this->logger->error('Order customer or email missing', ['orderId' => $orderId]);
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
                    file_put_contents('/tmp/purchase_subscriber.txt', "⚠️ Line item missing product ID\n", FILE_APPEND);
                    $this->logger->warning('Missing product ID on line item', [
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
                file_put_contents('/tmp/purchase_subscriber.txt', "❌ No valid products found\n", FILE_APPEND);
                $this->logger->error('No valid product line items', ['orderId' => $orderId]);
                return;
            }

            // Create and dispatch a purchase message to be processed asynchronously
            $message = new PurchaseMessage($email, $orderNumber, $orderDate, $products);
            
            $this->logger->info('Dispatching purchase message to queue', [
                'email' => $email,
                'orderId' => $orderNumber
            ]);
            
            file_put_contents('/tmp/purchase_subscriber.txt', "✅ Dispatching purchase message to queue\n", FILE_APPEND);
            
            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            file_put_contents('/tmp/purchase_subscriber.txt', "❌ Crash: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->logger->error('Fatal error in onOrderCompleted()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
