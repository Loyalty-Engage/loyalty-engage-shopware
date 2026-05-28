<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles cleanup after a LoyaltyEngage discount code is redeemed at checkout.
 *
 * When an order is placed:
 *  1. Find all promotion line items in the order.
 *  2. For each promotion code that belongs to a LoyaltyEngage promotion
 *     (name starts with "LoyaltyEngage: "), mark it as redeemed in the
 *     LoyaltyEngage API.
 *  3. Delete the individual code from the Shopware promotion so it cannot
 *     be reused.
 */
class DiscountRedemptionSubscriber implements EventSubscriberInterface
{
    private const PROMOTION_NAME_PREFIX = 'LoyaltyEngage: ';

    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $promotionIndividualCodeRepository;
    private LoggerInterface $logger;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $promotionIndividualCodeRepository,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService           = $loyaltyEngageApiService;
        $this->promotionIndividualCodeRepository = $promotionIndividualCodeRepository;
        $this->logger                            = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order    = $event->getOrder();
        $context  = $event->getContext();

        // Get customer email for the LoyaltyEngage API call
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null) {
            return;
        }
        $email = $orderCustomer->getEmail();

        // Find all promotion line items in the order
        $lineItems = $order->getLineItems();
        if ($lineItems === null) {
            return;
        }

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== 'promotion') {
                continue;
            }

            $promotionCode = $lineItem->getReferencedId();
            if ($promotionCode === null || $promotionCode === '') {
                continue;
            }

            // Check if this code belongs to a LoyaltyEngage promotion
            // by looking up the individual code and checking the promotion name
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $promotionCode));
            $criteria->addAssociation('promotion');
            $criteria->setLimit(1);

            $searchResult = $this->promotionIndividualCodeRepository->search($criteria, $context);
            $codeIds      = $searchResult->getIds();

            if (empty($codeIds)) {
                continue;
            }

            $codeId     = reset($codeIds);
            $codeEntity = $searchResult->first();

            // Check if the promotion name starts with our prefix
            // The association is loaded so we can access it via the entity
            $promotionName = '';
            if ($codeEntity !== null) {
                /** @var \Shopware\Core\Framework\DataAbstractionLayer\Entity $codeEntity */
                $vars = $codeEntity->getVars();
                $promotion = $vars['promotion'] ?? null;
                if ($promotion !== null && method_exists($promotion, 'getName')) {
                    $promotionName = (string) $promotion->getName();
                } elseif ($promotion !== null && is_array($promotion)) {
                    $promotionName = (string) ($promotion['name'] ?? '');
                }
            }

            if (!str_starts_with($promotionName, self::PROMOTION_NAME_PREFIX)) {
                continue;
            }

            $this->logger->info('DiscountRedemptionSubscriber: LoyaltyEngage code redeemed', [
                'code'          => $promotionCode,
                'promotionName' => $promotionName,
                'email'         => $email,
                'orderId'       => $order->getId(),
            ]);

            // 1. Mark as redeemed in LoyaltyEngage API
            try {
                $this->loyaltyEngageApiService->redeemDiscountCode($email, $promotionCode);
            } catch (\Throwable $e) {
                $this->logger->error('DiscountRedemptionSubscriber: Failed to mark code as redeemed in LoyaltyEngage', [
                    'code'  => $promotionCode,
                    'error' => $e->getMessage(),
                ]);
            }

            // 2. Delete the individual code from Shopware so it cannot be reused
            try {
                $this->promotionIndividualCodeRepository->delete([
                    ['id' => $codeId],
                ], $context);

                $this->logger->info('DiscountRedemptionSubscriber: Individual code removed from Shopware', [
                    'code'   => $promotionCode,
                    'codeId' => $codeId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('DiscountRedemptionSubscriber: Failed to delete individual code from Shopware', [
                    'code'  => $promotionCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
