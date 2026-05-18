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
        $this->logger->info('LoyaltyEngage: Processing free product remove message', [
            'email'     => $message->getEmail(),
            'productId' => $message->getProductId(),
            'sku'       => $message->getSku(),
            'quantity'  => $message->getQuantity(),
        ]);

        // Use the SKU (productNumber) — NOT the Shopware UUID — because the
        // LoyaltyEngage API identifies products by their SKU.
        $response = $this->loyaltyEngageApiService->removeItem(
            $message->getEmail(),
            $message->getSku(),
            $message->getQuantity()
        );

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: Free product remove sent successfully', [
                'email' => $message->getEmail(),
                'sku'   => $message->getSku(),
            ]);
        } else {
            $this->logger->error('LoyaltyEngage: Failed to send free product remove — will retry', [
                'email'    => $message->getEmail(),
                'sku'      => $message->getSku(),
                'response' => $response,
            ]);

            // Throw exception so Symfony Messenger retries automatically (up to max_retries)
            throw new \RuntimeException(
                sprintf(
                    'LoyaltyEngage free product remove failed for %s / SKU %s with HTTP status %s',
                    $message->getEmail(),
                    $message->getSku(),
                    $response ?? 'null'
                )
            );
        }
    }
}
