<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\PurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PurchaseMessageHandler
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;
    private EntityRepository $orderRepository;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger,
        EntityRepository $orderRepository
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
        $this->orderRepository         = $orderRepository;
    }

    public function __invoke(PurchaseMessage $message): void
    {
        $this->logger->info('LoyaltyEngage: Processing purchase message', [
            'email'   => $message->getEmail(),
            'orderId' => $message->getOrderId(),
        ]);

        $response = $this->loyaltyEngageApiService->sendEvent($message->toArray());

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: Purchase event sent successfully', [
                'email'   => $message->getEmail(),
                'orderId' => $message->getOrderId(),
            ]);

            // Mark the order as successfully sent to loyalty
            $this->markOrderAsSent($message->getOrderId());
        } else {
            $this->logger->error('LoyaltyEngage: Failed to send purchase event — will retry', [
                'email'    => $message->getEmail(),
                'orderId'  => $message->getOrderId(),
                'response' => $response,
            ]);

            // Throw exception so Symfony Messenger retries automatically (up to max_retries)
            throw new \RuntimeException(
                sprintf(
                    'LoyaltyEngage purchase event failed for order %s with HTTP status %s',
                    $message->getOrderId(),
                    $response ?? 'null'
                )
            );
        }
    }

    /**
     * Mark the order custom field loyalty_purchase_sent = true so the scheduled
     * retry task knows it no longer needs to process this order.
     */
    private function markOrderAsSent(string $orderNumber): void
    {
        try {
            $context  = Context::createDefaultContext();
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order) {
                return;
            }

            $customFields                          = $order->getCustomFields() ?? [];
            $customFields['loyalty_purchase_sent'] = true;

            $this->orderRepository->update([
                ['id' => $order->getId(), 'customFields' => $customFields],
            ], $context);
        } catch (\Throwable $e) {
            // Non-critical: log but don't fail the handler
            $this->logger->warning('LoyaltyEngage: Could not mark order as sent', [
                'orderNumber' => $orderNumber,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
