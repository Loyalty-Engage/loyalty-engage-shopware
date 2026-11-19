<?php declare(strict_types=1);

namespace LoyaltyEngage\Message\Handler;

use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Service\DeliveryOutbox;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ReturnMessageHandler
{
    public function __construct(private readonly DeliveryOutbox $outbox) {}

    public function __invoke(ReturnMessage $message): void
    {
        $this->outbox->dispatch($message);
    }
}
