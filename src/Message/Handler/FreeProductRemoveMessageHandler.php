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

        $this->logger->info('LoyaltyEngage: Processing free product remove — calling removeAllItems', [
            'email' => $email,
        ]);

        // Use removeAllItems() instead of removeItem() per product.
        // This ensures exactly ONE API call per customer regardless of how many
        // loyalty products were removed, preventing rate-limiting (HTTP 429).
        $response = $this->loyaltyEngageApiService->removeAllItems($email);

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: removeAllItems sent successfully', [
                'email' => $email,
            ]);
        } else {
            $this->logger->error('LoyaltyEngage: Failed to send removeAllItems — will retry', [
                'email'    => $email,
                'response' => $response,
            ]);

            // Throw exception so Symfony Messenger retries automatically (up to max_retries)
            throw new \RuntimeException(
                sprintf(
                    'LoyaltyEngage removeAllItems failed for %s with HTTP status %s',
                    $email,
                    $response ?? 'null'
                )
            );
        }
    }
}
