<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
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
 * Retries return events (sendEvent) that were not successfully delivered to the
 * LoyaltyEngage API.
 *
 * An order delivery is eligible for retry when:
 *   - loyalty_return_sent  = false  (not yet confirmed as sent)
 *   - loyalty_return_retry_count < loyaltyOrderRetrieveLimit (configurable)
 *   - delivery state = returned
 *
 * On success  → loyalty_return_sent = true
 * On failure  → loyalty_return_retry_count++
 */
class ReturnEventRetryTaskHandler extends ScheduledTaskHandler
{
    private EntityRepository $orderDeliveryRepository;
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $orderDeliveryRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
    }

    public static function getHandledMessages(): iterable
    {
        return [ReturnEventRetryTask::class];
    }

    public function run(): void
    {
        if (!$this->loyaltyEngageApiService->isReturnExportEnabled()) {
            return;
        }

        $limit   = $this->loyaltyEngageApiService->getLoyaltyOrderRetrieveLimit();
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', 'returned'));
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('customFields.loyalty_return_sent', false),
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsFilter('customFields.loyalty_return_sent', true),
                ]),
            ])
        );
        $criteria->addFilter(new RangeFilter('customFields.loyalty_return_retry_count', [
            RangeFilter::LT => $limit,
        ]));
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('stateMachineState');
        $criteria->setLimit(50);

        $deliveries = $this->orderDeliveryRepository->search($criteria, $context);

        if ($deliveries->getTotal() === 0) {
            return;
        }

        $this->logger->info('LoyaltyEngage: ReturnEventRetryTask processing deliveries', [
            'count' => $deliveries->getTotal(),
        ]);

        foreach ($deliveries->getEntities() as $delivery) {
            /** @var OrderDeliveryEntity $delivery */
            $this->processDelivery($delivery, $context);
        }
    }

    private function processDelivery(OrderDeliveryEntity $delivery, Context $context): void
    {
        $order = $delivery->getOrder();
        if (!$order) {
            return;
        }

        $customer = $order->getOrderCustomer();
        if (!$customer || !$customer->getEmail()) {
            $this->logger->warning('LoyaltyEngage: ReturnEventRetryTask — skipping delivery without customer/email', [
                'deliveryId' => $delivery->getId(),
            ]);
            return;
        }

        $email    = $customer->getEmail();
        $products = [];

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== 'product') {
                continue;
            }

            $payload = $lineItem->getPayload() ?? [];
            $sku     = $payload['productNumber'] ?? null;

            if (!$sku) {
                continue;
            }

            $products[] = [
                'sku'      => $sku,
                'price'    => (float) $lineItem->getUnitPrice(),
                'quantity' => (int) $lineItem->getQuantity(),
            ];
        }

        if (empty($products)) {
            $this->logger->warning('LoyaltyEngage: ReturnEventRetryTask — no valid products for delivery', [
                'deliveryId' => $delivery->getId(),
            ]);
            return;
        }

        $returnDate = (new \DateTime())->format(DATE_ATOM);
        $payload    = [
            'event'      => 'return',
            'email'      => $email,
            'returnDate' => $returnDate,
            'products'   => $products,
        ];

        $response     = $this->loyaltyEngageApiService->sendEvent($payload);
        $customFields = $delivery->getCustomFields() ?? [];

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: ReturnEventRetryTask — event sent successfully', [
                'deliveryId' => $delivery->getId(),
            ]);
            $customFields['loyalty_return_sent']        = true;
            $customFields['loyalty_return_retry_count'] = (int) ($customFields['loyalty_return_retry_count'] ?? 0);
        } else {
            $retryCount = (int) ($customFields['loyalty_return_retry_count'] ?? 0) + 1;
            $this->logger->error('LoyaltyEngage: ReturnEventRetryTask — event failed', [
                'deliveryId' => $delivery->getId(),
                'response'   => $response,
                'retryCount' => $retryCount,
            ]);
            $customFields['loyalty_return_sent']        = false;
            $customFields['loyalty_return_retry_count'] = $retryCount;
        }

        $this->orderDeliveryRepository->update([
            ['id' => $delivery->getId(), 'customFields' => $customFields],
        ], $context);
    }
}
