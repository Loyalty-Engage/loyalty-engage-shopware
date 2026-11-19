<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductRemoveMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FreeProductRemoveMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(FreeProductRemoveMessage $message): void
    {
        // Old queued messages cannot identify the removed SKU or reservation.
        // Clearing the entire remote cart could cancel newer, valid reservations.
        $this->logger->warning('Legacy loyalty cart cleanup requires manual reconciliation; unsafe delayed cleanup skipped', [
            'contactHash' => hash('sha256', strtolower(trim($message->getEmail()))),
            'salesChannelId' => $message->getSalesChannelId(),
        ]);
    }
}
