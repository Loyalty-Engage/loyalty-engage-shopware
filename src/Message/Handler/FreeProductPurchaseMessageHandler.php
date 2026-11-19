<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Service\DeliveryOutbox;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FreeProductPurchaseMessageHandler
{
    public function __construct(private readonly DeliveryOutbox $outbox) {}

    public function __invoke(FreeProductPurchaseMessage $message): void
    {
        $this->outbox->dispatch($message);
    }
}
