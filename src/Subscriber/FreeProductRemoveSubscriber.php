<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FreeProductRemoveSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [BeforeLineItemRemovedEvent::class => 'onLineItemRemoved'];
    }

    public function onLineItemRemoved(BeforeLineItemRemovedEvent $event): void
    {
        $item = $event->getLineItem();
        if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE || $item->getPayloadValue('loyaltyFreeProduct') !== true) {
            return;
        }
        if ($item->hasPayloadValue('leRewardRequestKey') && $item->hasPayloadValue('leRewardAuthorization')) {
            return; // Signed reservations are released by ManagedRewardLifecycleSubscriber.
        }
        $this->logger->warning('Unverified legacy loyalty reward removed; reservation requires reconciliation', [
            'salesChannelId' => $event->getSalesChannelContext()->getSalesChannelId(),
            'lineItemId' => $item->getId(),
        ]);
    }
}
