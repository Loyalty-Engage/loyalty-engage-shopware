<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FreeProductPurchaseMessageHandler
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

    public function __invoke(FreeProductPurchaseMessage $message): void
    {
        $this->logger->info('LoyaltyEngage: Processing free product purchase message', [
            'email'    => $message->getEmail(),
            'orderId'  => $message->getOrderId(),
            'products' => $message->getProducts(),
        ]);

        $response   = $this->loyaltyEngageApiService->placeOrderWithResponse(
            $message->getEmail(),
            $message->getOrderId(),
            $message->getProducts()
        );

        $statusCode = $response['statusCode'] ?? null;
        $body       = $response['body'] ?? null;

        if ($statusCode === 200) {
            $this->logger->info('LoyaltyEngage: Free product purchase sent successfully', [
                'email'   => $message->getEmail(),
                'orderId' => $message->getOrderId(),
            ]);
        } else {
            $this->logger->error('LoyaltyEngage: Failed to send free product purchase — will retry', [
                'email'      => $message->getEmail(),
                'orderId'    => $message->getOrderId(),
                'statusCode' => $statusCode,
                'body'       => $body,
            ]);

            // Throw exception so Symfony Messenger retries automatically (up to max_retries)
            throw new \RuntimeException(
                sprintf(
                    'LoyaltyEngage free product purchase failed for order %s with HTTP status %s',
                    $message->getOrderId(),
                    $statusCode ?? 'null'
                )
            );
        }
    }
}
