<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use LoyaltyEngage\Service\DeliveryOutbox;
use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Psr\Log\LoggerInterface;

class OrderPlaceTaskHandler extends ScheduledTaskHandler
{
    /**
     * @var EntityRepository
     */
    private $orderRepository;

    /**
     * @var LoyaltyEngageApiService
     */
    private $loyaltyEngageApiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param EntityRepository $scheduledTaskRepository
     * @param EntityRepository $orderRepository
     * @param LoyaltyEngageApiService $loyaltyEngageApiService
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $orderRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger,
        private readonly DeliveryOutbox $outbox
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
        $this->orderRepository = $orderRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public static function getHandledMessages(): iterable
    {
        return [OrderPlaceTask::class];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->outbox->retryDue();
        $loyaltyOrderRetrieveLimit = $this->loyaltyEngageApiService->getLoyaltyOrderRetrieveLimit();
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.loyalty_order_place', false));
        $criteria->addFilter(new RangeFilter('customFields.loyalty_order_place_retrieve', [
            RangeFilter::LT => $loyaltyOrderRetrieveLimit,
        ]));
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('orderCustomer');

        $orders = $this->orderRepository->search($criteria, $context);

        foreach ($orders->getEntities() as $order) {
            /** @var OrderEntity $order */
            $orderCustomer = $order->getOrderCustomer();
            if (!$orderCustomer || !$orderCustomer->getEmail()) {
                $this->logger->warning('LoyaltyEngage: Skipping order without customer/email in OrderPlaceTaskHandler', [
                    'orderId' => $order->getId()
                ]);
                continue;
            }

            $email = $orderCustomer->getEmail();
            $orderNumber = $order->getOrderNumber();

            // Prepare order data
            $products = [];
            foreach ($order->getLineItems() as $lineItem) {
                // Skip non-product line items
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                $payload = $lineItem->getPayload() ?? [];
                if (($payload['loyaltyFreeProduct'] ?? false) !== true || empty($payload['productNumber'])) {
                    continue;
                }

                $products[] = [
                    'sku' => $payload['productNumber'],
                    'quantity' => (int) $lineItem->getQuantity()
                ];
            }

            // Place order
            if ($products === []) {
                continue;
            }
            $response = $this->outbox->dispatch(new FreeProductPurchaseMessage($email, $orderNumber, $products, $order->getSalesChannelId()));

            $customFields = $order->getCustomFields() ?? [];

            if ($response) {
                $customFields['loyalty_order_place'] = true;
            } else {
                $currentValue = (int) ($customFields['loyalty_order_place_retrieve'] ?? 0);
                $customFields['loyalty_order_place_retrieve'] = $currentValue + 1;
            }

            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields
                ]
            ], $context);
        }
    }
}
