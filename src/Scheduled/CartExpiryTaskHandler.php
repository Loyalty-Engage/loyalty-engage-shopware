<?php declare(strict_types=1);

namespace LoyaltyEngage\Scheduled;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
        parent::__construct($scheduledTaskRepository);
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
        $criteria->addFilter(new EqualsFilter('customerId', null, 'neq'));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('customer');

        $carts = $this->orderRepository->search($criteria, $context);

        if ($carts->getTotal() > 0) {
            foreach ($carts->getEntities() as $cart) {
                try {
                    $customer = $cart->getCustomer();
                    if (!$customer) {
                        if ($loggerStatus) {
                            $this->logger->error('Customer not found for cart', ['cartId' => $cart->getId()]);
                        }
                        continue;
                    }

                    $email = $customer->getEmail();
                    if (!$email) {
                        if ($loggerStatus) {
                            $this->logger->error('Email not found for customer', ['customerId' => $customer->getId()]);
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

                    // Mark cart as inactive
                    $this->orderRepository->update([
                        [
                            'id' => $cart->getId(),
                            'active' => false,
                        ]
                    ], $context);

                    if ($loggerStatus) {
                        $this->logger->info('Cart ID ' . $cart->getId() . ' processed successfully.');
                    }
                } catch (\Exception $e) {
                    if ($loggerStatus) {
                        $this->logger->error('Error processing cart ID ' . $cart->getId() . ': ' . $e->getMessage(), [
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
