<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\PurchaseMessage;
use LoyaltyEngage\Service\DeliveryOutbox;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PurchaseMessageHandler
{
    public function __construct(private readonly DeliveryOutbox $outbox) {}

    public function __invoke(PurchaseMessage $message): void
    {
        $this->outbox->dispatch($message);
    }
}
