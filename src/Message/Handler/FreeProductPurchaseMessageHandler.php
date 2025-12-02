<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class FreeProductPurchaseMessageHandler implements MessageHandlerInterface
{
    /**
     * @var LoyaltyEngageApiService
     */
    private $loyaltyEngageApiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoyaltyEngageApiService $loyaltyEngageApiService
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger = $logger;
    }

    /**
     * @param FreeProductPurchaseMessage $message
     */
    public function __invoke(FreeProductPurchaseMessage $message): void
    {
        try {
            $this->logger->info('Processing free product purchase message', [
                'email' => $message->getEmail(),
                'orderId' => $message->getOrderId()
            ]);

            $response = $this->loyaltyEngageApiService->placeOrder(
                $message->getEmail(),
                $message->getOrderId(),
                $message->getProducts()
            );

            if ($response === 200) {
                $this->logger->info('Free product purchase sent successfully', [
                    'email' => $message->getEmail(),
                    'orderId' => $message->getOrderId()
                ]);
            } else {
                $this->logger->error('Failed to send free product purchase', [
                    'email' => $message->getEmail(),
                    'orderId' => $message->getOrderId(),
                    'response' => $response
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error processing free product purchase message', [
                'email' => $message->getEmail(),
                'orderId' => $message->getOrderId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
