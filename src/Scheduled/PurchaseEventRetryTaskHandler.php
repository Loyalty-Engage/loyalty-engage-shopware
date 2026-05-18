<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

/**
 * Retries purchase events (sendEvent) that were not successfully delivered to the
 * LoyaltyEngage API.
 *
 * An order is eligible for retry when:
 *   - loyalty_purchase_sent  = false  (not yet confirmed as sent)
 *   - loyalty_purchase_retry_count < loyaltyOrderRetrieveLimit (configurable)
 *
 * On success  → loyalty_purchase_sent = true
 * On failure  → loyalty_purchase_retry_count++
 */
class PurchaseEventRetryTaskHandler extends ScheduledTaskHandler
{
    private EntityRepository $orderRepository;
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $orderRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
        $this->orderRepository         = $orderRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
    }

    public static function getHandledMessages(): iterable
    {
        return [PurchaseEventRetryTask::class];
    }

    public function run(): void
    {
        if (!$this->loyaltyEngageApiService->isPurchaseExportEnabled()) {
            return;
        }

        $limit   = $this->loyaltyEngageApiService->getLoyaltyOrderRetrieveLimit();
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                // loyalty_purchase_sent is explicitly false
                new EqualsFilter('customFields.loyalty_purchase_sent', false),
                // or the field does not exist yet (NULL = never processed)
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsFilter('customFields.loyalty_purchase_sent', true),
                ]),
            ])
        );
        $criteria->addFilter(new RangeFilter('customFields.loyalty_purchase_retry_count', [
            RangeFilter::LT => $limit,
        ]));
        // Only orders that were set to "completed" state (purchase event is triggered on completed)
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', 'completed'));
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('stateMachineState');
        $criteria->setLimit(50);

        $orders = $this->orderRepository->search($criteria, $context);

        if ($orders->getTotal() === 0) {
            return;
        }

        $this->logger->info('LoyaltyEngage: PurchaseEventRetryTask processing orders', [
            'count' => $orders->getTotal(),
        ]);

        foreach ($orders->getEntities() as $order) {
            /** @var OrderEntity $order */
            $this->processOrder($order, $context);
        }
    }

    private function processOrder(OrderEntity $order, Context $context): void
    {
        $orderCustomer = $order->getOrderCustomer();
        if (!$orderCustomer || !$orderCustomer->getEmail()) {
            $this->logger->warning('LoyaltyEngage: PurchaseEventRetryTask — skipping order without customer/email', [
                'orderId' => $order->getId(),
            ]);
            return;
        }

        $email       = $orderCustomer->getEmail();
        $orderNumber = $order->getOrderNumber();
        $products    = [];

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== 'product') {
                continue;
            }

            $payload = $lineItem->getPayload() ?? [];
            $sku     = $payload['productNumber'] ?? null;

            if (!$sku) {
                $this->logger->warning('LoyaltyEngage: PurchaseEventRetryTask — missing productNumber in payload', [
                    'orderId'    => $order->getId(),
                    'lineItemId' => $lineItem->getId(),
                ]);
                continue;
            }

            $products[] = [
                'sku'      => $sku,
                'price'    => (float) $lineItem->getUnitPrice(),
                'quantity' => (int) $lineItem->getQuantity(),
            ];
        }

        if (empty($products)) {
            $this->logger->warning('LoyaltyEngage: PurchaseEventRetryTask — no valid products for order', [
                'orderId' => $order->getId(),
            ]);
            return;
        }

        $payload = [
            'event'       => 'purchase',
            'email'       => $email,
            'orderId'     => $orderNumber,
            'orderDate'   => $order->getOrderDateTime()?->format(DATE_ATOM) ?? '',
            'products'    => $products,
        ];

        $response     = $this->loyaltyEngageApiService->sendEvent($payload);
        $customFields = $order->getCustomFields() ?? [];

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: PurchaseEventRetryTask — event sent successfully', [
                'orderId' => $orderNumber,
            ]);
            $customFields['loyalty_purchase_sent']        = true;
            $customFields['loyalty_purchase_retry_count'] = (int) ($customFields['loyalty_purchase_retry_count'] ?? 0);
        } else {
            $retryCount = (int) ($customFields['loyalty_purchase_retry_count'] ?? 0) + 1;
            $this->logger->error('LoyaltyEngage: PurchaseEventRetryTask — event failed', [
                'orderId'    => $orderNumber,
                'response'   => $response,
                'retryCount' => $retryCount,
            ]);
            $customFields['loyalty_purchase_sent']        = false;
            $customFields['loyalty_purchase_retry_count'] = $retryCount;
        }

        $this->orderRepository->update([
            ['id' => $order->getId(), 'customFields' => $customFields],
        ], $context);
    }
}
