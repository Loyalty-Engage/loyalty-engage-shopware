<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ReturnMessageHandler implements MessageHandlerInterface
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
     * @param ReturnMessage $message
     */
    public function __invoke(ReturnMessage $message): void
    {
        try {
            $this->logger->info('Processing return message', [
                'email' => $message->getEmail(),
                'returnDate' => $message->getReturnDate()
            ]);

            $response = $this->loyaltyEngageApiService->sendEvent($message->toArray());

            if ($response === 200) {
                $this->logger->info('Return event sent successfully', [
                    'email' => $message->getEmail(),
                    'returnDate' => $message->getReturnDate()
                ]);
            } else {
                $this->logger->error('Failed to send return event', [
                    'email' => $message->getEmail(),
                    'returnDate' => $message->getReturnDate(),
                    'response' => $response
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error processing return message', [
                'email' => $message->getEmail(),
                'returnDate' => $message->getReturnDate(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
