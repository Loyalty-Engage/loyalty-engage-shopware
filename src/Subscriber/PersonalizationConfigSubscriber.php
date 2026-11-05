<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\PersonalizationConfig;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PersonalizationConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [BeforeSystemConfigChangedEvent::class => 'validate'];
    }

    public function validate(BeforeSystemConfigChangedEvent $event): void
    {
        if ($event->getValue() === null) { return; } // Null restores sales-channel inheritance.
        try {
            if ($event->getKey() === 'LoyaltyEngage.config.personalizationTenant') {
                if (!is_string($event->getValue())) { throw new \InvalidArgumentException('Tenant must be text.'); }
                $event->setValue(PersonalizationConfig::tenant($event->getValue()));
            }
            if ($event->getKey() === 'LoyaltyEngage.config.personalizationRules') {
                $event->setValue(PersonalizationConfig::normalizeRules($event->getValue()));
            }
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }
}
