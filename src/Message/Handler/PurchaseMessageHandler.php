<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\PurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class PurchaseMessageHandler implements MessageHandlerInterface
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
     * @param PurchaseMessage $message
     */
    public function __invoke(PurchaseMessage $message): void
    {
        try {
            $this->logger->info('Processing purchase message', [
                'email' => $message->getEmail(),
                'orderId' => $message->getOrderId()
            ]);

            $response = $this->loyaltyEngageApiService->sendEvent($message->toArray());

            if ($response === 200) {
                $this->logger->info('Purchase event sent successfully', [
                    'email' => $message->getEmail(),
                    'orderId' => $message->getOrderId()
                ]);
            } else {
                $this->logger->error('Failed to send purchase event', [
                    'email' => $message->getEmail(),
                    'orderId' => $message->getOrderId(),
                    'response' => $response
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error processing purchase message', [
                'email' => $message->getEmail(),
                'orderId' => $message->getOrderId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
