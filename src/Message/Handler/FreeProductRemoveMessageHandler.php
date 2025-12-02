<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FreeProductRemoveMessageHandler
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
     * @param FreeProductRemoveMessage $message
     */
    public function __invoke(FreeProductRemoveMessage $message): void
    {
        try {
            $this->logger->info('Processing free product remove message', [
                'email' => $message->getEmail(),
                'productId' => $message->getProductId()
            ]);

            $response = $this->loyaltyEngageApiService->removeItem(
                $message->getEmail(),
                $message->getProductId(),
                $message->getQuantity()
            );

            if ($response === 200) {
                $this->logger->info('Free product remove sent successfully', [
                    'email' => $message->getEmail(),
                    'productId' => $message->getProductId()
                ]);
            } else {
                $this->logger->error('Failed to send free product remove', [
                    'email' => $message->getEmail(),
                    'productId' => $message->getProductId(),
                    'response' => $response
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error processing free product remove message', [
                'email' => $message->getEmail(),
                'productId' => $message->getProductId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
