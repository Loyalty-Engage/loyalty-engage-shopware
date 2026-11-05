<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\PersonalizationConfig;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PersonalizationPageSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PersonalizationConfig $config) {}

    public static function getSubscribedEvents(): array
    {
        return [GenericPageLoadedEvent::class => 'load'];
    }

    public function load(GenericPageLoadedEvent $event): void
    {
        // Only public configuration is allowed in shared storefront HTML.
        $event->getPage()->addExtension('lePersonalizations', new ArrayStruct(
            $this->config->storefront($event->getSalesChannelContext()->getSalesChannelId())
        ));
    }
}
