<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LoyaltyShopCacheSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => ['onResponse', -2048]]; }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $content = $response->getContent();
        if ($event->isMainRequest() && is_string($content) && str_contains($content, 'data-native-loyalty-shop')) {
            // The feed cache controls freshness; a longer CMS HTTP cache must not hide updates.
            $response->headers->set('Cache-Control', 'private, no-store');
        }
    }
}
