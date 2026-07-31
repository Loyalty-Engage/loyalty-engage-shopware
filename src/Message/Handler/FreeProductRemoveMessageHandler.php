<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FreeProductRemoveMessageHandler
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
    }

    public function __invoke(FreeProductRemoveMessage $message): void
    {
        $email = $message->getEmail();

        try {
            $this->logger->info('FreeProductRemoveMessageHandler: Processing removeAllItems', [
                'email' => $email,
            ]);

            // Use removeAllItems() instead of removeItem() per product.
            // This ensures exactly ONE API call per customer regardless of how many
            // loyalty products were removed, preventing rate-limiting (HTTP 429).
            $response = $this->loyaltyEngageApiService->removeAllItems($email, $message->getSalesChannelId());

            if ($response === 200) {
                $this->logger->info('FreeProductRemoveMessageHandler: removeAllItems sent successfully', [
                    'email' => $email,
                ]);
            } else {
                $this->logger->error('FreeProductRemoveMessageHandler: Failed to send removeAllItems', [
                    'email'    => $email,
                    'response' => $response,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('FreeProductRemoveMessageHandler: Error processing removeAllItems', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
