<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Psr\Log\LoggerInterface;

class CartExpiryTaskHandler extends ScheduledTaskHandler
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
        LoggerInterface $logger
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
        return [CartExpiryTask::class];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $expiryTime = $this->loyaltyEngageApiService->getExpiryTime();
        $loggerStatus = $this->loyaltyEngageApiService->getLoggerStatus();

        $fromTime = new \DateTime('now', new \DateTimezone('UTC'));
        $expiryMinutes = $expiryTime * 60;
        $fromTime->sub(new \DateInterval("PT{$expiryMinutes}S"));
        $fromDate = $fromTime->format('Y-m-d H:i:s');

        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::LTE => $fromDate,
        ]));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customerId', null),
        ]));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('customer');

        $carts = $this->orderRepository->search($criteria, $context);

        if ($carts->getTotal() > 0) {
            foreach ($carts->getEntities() as $order) {
                /** @var OrderEntity $order */
                try {
                    $orderCustomer = $order->getOrderCustomer();
                    if (!$orderCustomer) {
                        if ($loggerStatus) {
                            $this->logger->error('Customer not found for order', ['orderId' => $order->getId()]);
                        }
                        continue;
                    }

                    $email = $orderCustomer->getEmail();
                    if (!$email) {
                        if ($loggerStatus) {
                            $this->logger->error('Email not found for order customer', ['orderId' => $order->getId()]);
                        }
                        continue;
                    }

                    $response = $this->loyaltyEngageApiService->removeAllItems($email);

                    if ($response !== 200) {
                        if ($loggerStatus) {
                            $this->logger->error(
                                'Products could not be removed for email ' . $email . '. User is not eligible.',
                                ['response' => $response]
                            );
                        }
                        continue;
                    }

                    // Mark order loyalty cart as processed via custom field
                    $customFields = $order->getCustomFields() ?? [];
                    $customFields['loyalty_cart_expired'] = true;
                    $this->orderRepository->update([
                        [
                            'id' => $order->getId(),
                            'customFields' => $customFields,
                        ]
                    ], $context);

                    if ($loggerStatus) {
                        $this->logger->info('Order ID ' . $order->getId() . ' loyalty cart expired and processed successfully.');
                    }
                } catch (\Exception $e) {
                    if ($loggerStatus) {
                        $this->logger->error('Error processing order ID ' . $order->getId() . ': ' . $e->getMessage(), [
                            'exception' => $e
                        ]);
                    }
                }
            }
        } else {
            if ($loggerStatus) {
                $this->logger->info('No expired carts found to process.');
            }
        }
    }
}
