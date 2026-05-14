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
        $this->logger = $logger;
    }

    public function __invoke(FreeProductRemoveMessage $message): void
    {
        try {
            $this->logger->info('FreeProductRemoveMessageHandler: Processing free product remove message', [
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
                $this->logger->info('FreeProductRemoveMessageHandler: Free product remove sent successfully', [
                    'email' => $message->getEmail(),
                    'sku'   => $message->getSku(),
                ]);
            } else {
                $this->logger->error('FreeProductRemoveMessageHandler: Failed to send free product remove', [
                    'email'    => $message->getEmail(),
                    'sku'      => $message->getSku(),
                    'response' => $response,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('FreeProductRemoveMessageHandler: Error processing free product remove message', [
                'email' => $message->getEmail(),
                'sku'   => $message->getSku(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
