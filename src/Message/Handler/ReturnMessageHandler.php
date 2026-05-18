<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ReturnMessageHandler
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

    public function __invoke(ReturnMessage $message): void
    {
        $this->logger->info('LoyaltyEngage: Processing return message', [
            'email'      => $message->getEmail(),
            'returnDate' => $message->getReturnDate(),
        ]);

        $response = $this->loyaltyEngageApiService->sendEvent($message->toArray());

        if ($response === 200) {
            $this->logger->info('LoyaltyEngage: Return event sent successfully', [
                'email'      => $message->getEmail(),
                'returnDate' => $message->getReturnDate(),
            ]);
        } else {
            $this->logger->error('LoyaltyEngage: Failed to send return event — will retry', [
                'email'      => $message->getEmail(),
                'returnDate' => $message->getReturnDate(),
                'response'   => $response,
            ]);

            // Throw exception so Symfony Messenger retries automatically (up to max_retries)
            throw new \RuntimeException(
                sprintf(
                    'LoyaltyEngage return event failed for %s with HTTP status %s',
                    $message->getEmail(),
                    $response ?? 'null'
                )
            );
        }
    }
}
