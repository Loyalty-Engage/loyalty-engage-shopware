<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use LoyaltyEngage\Service\RewardAuthorization;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ManagedRewardLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RewardAuthorization $authorization, private readonly LoyaltyEngageApiService $api) {}

    public static function getSubscribedEvents(): array
    {
        return [BeforeLineItemRemovedEvent::class => ['remove', 100], CheckoutOrderPlacedEvent::class => ['ordered', -100]];
    }

    public function remove(BeforeLineItemRemovedEvent $event): void
    {
        $item = $event->getLineItem();
        $context = $event->getSalesChannelContext();
        if ($item->getPayloadValue('leManagedReward') !== true || !$this->authorization->valid($item, $context)) { return; }
        $status = $this->api->removeItem($context->getCustomer()->getEmail(), (string) $item->getPayloadValue('productNumber'), $item->getQuantity(), $context->getSalesChannelId());
        if (!in_array($status, [200, 204], true)) {
            throw new ServiceUnavailableHttpException(null, 'De puntenreservering kon niet worden vrijgegeven. Probeer later opnieuw.');
        }
        $this->authorization->revoke((string) $item->getPayloadValue('leRewardRequestKey'));
    }

    public function ordered(CheckoutOrderPlacedEvent $event): void
    {
        foreach ($event->getOrder()->getLineItems() ?? [] as $item) {
            $key = ($item->getPayload() ?? [])['leRewardRequestKey'] ?? null;
            if (is_string($key)) { $this->authorization->revoke($key); }
        }
    }
}
