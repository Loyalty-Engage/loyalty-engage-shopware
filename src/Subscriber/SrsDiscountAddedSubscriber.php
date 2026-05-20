<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates LoyaltyEngage promotion codes against the SRS API
 * immediately after they are added to the cart (e.g. via the cart overlay).
 *
 * If the code is expired or already redeemed, it is removed from the cart
 * right away so the customer sees an error before reaching checkout.
 */
class SrsDiscountAddedSubscriber implements EventSubscriberInterface
{
    private const PROMOTION_NAME_PREFIX = 'LoyaltyEngage: ';
    private const BLOCKED_STATUS_LABELS = ['redeemed', 'expired'];

    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $promotionIndividualCodeRepository;
    private CartService $cartService;
    private LoggerInterface $logger;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $promotionIndividualCodeRepository,
        CartService $cartService,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService           = $loyaltyEngageApiService;
        $this->promotionIndividualCodeRepository = $promotionIndividualCodeRepository;
        $this->cartService                       = $cartService;
        $this->logger                            = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterLineItemAddedEvent::class => 'onAfterLineItemAdded',
        ];
    }

    public function onAfterLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        // Only run when SRS Discount Sync is enabled
        if (!$this->loyaltyEngageApiService->isSrsDiscountSyncEnabled()) {
            return;
        }

        $cart    = $event->getCart();
        $context = $event->getSalesChannelContext();

        foreach ($event->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== 'promotion') {
                continue;
            }

            // Get the promotion code from payload or referencedId
            $payload       = $lineItem->getPayload() ?? [];
            $promotionCode = $payload['code'] ?? $payload['promotionCode'] ?? $lineItem->getReferencedId();

            if (empty($promotionCode)) {
                continue;
            }

            // Look up the code in Shopware to check if it's a LoyaltyEngage promotion
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $promotionCode));
            $criteria->addAssociation('promotion');
            $criteria->setLimit(1);

            $searchResult = $this->promotionIndividualCodeRepository->search($criteria, $context->getContext());
            $codeEntity   = $searchResult->first();

            if ($codeEntity === null) {
                $this->logger->warning('SrsDiscountAddedSubscriber: Code not found in promotion_individual_code', [
                    'code' => $promotionCode,
                ]);
                continue;
            }

            // Resolve promotion name
            $promotionName = '';
            try {
                $promotion = $codeEntity->get('promotion');
                if ($promotion !== null && method_exists($promotion, 'getName')) {
                    $promotionName = (string) $promotion->getName();
                }
            } catch (\Throwable $e) {
                $vars      = $codeEntity->getVars();
                $promotion = $vars['promotion'] ?? null;
                if (is_array($promotion)) {
                    $promotionName = (string) ($promotion['name'] ?? '');
                } elseif ($promotion !== null && method_exists($promotion, 'getName')) {
                    $promotionName = (string) $promotion->getName();
                }
            }

            if (!str_starts_with($promotionName, self::PROMOTION_NAME_PREFIX)) {
                // Not a LoyaltyEngage code — skip
                continue;
            }

            $this->logger->warning('SrsDiscountAddedSubscriber: LoyaltyEngage code added to cart, checking SRS', [
                'code'          => $promotionCode,
                'promotionName' => $promotionName,
            ]);

            // Check SRS status
            $srsResult = $this->loyaltyEngageApiService->checkSrsVoucherStatus($promotionCode);

            if (empty($srsResult)) {
                // API call failed — fail open, allow the code
                $this->logger->warning('SrsDiscountAddedSubscriber: SRS API call failed, allowing code through', [
                    'code' => $promotionCode,
                ]);
                continue;
            }

            $checkSuccess   = $srsResult['srs_check_success'] ?? false;
            $srsStatusLabel = $srsResult['srs_status_label'] ?? null;
            $localRedeemed  = $srsResult['local_redeemed'] ?? false;
            $localExpired   = $srsResult['local_expired'] ?? false;

            $this->logger->warning('SrsDiscountAddedSubscriber: SRS result', [
                'code'           => $promotionCode,
                'checkSuccess'   => $checkSuccess,
                'srsStatusLabel' => $srsStatusLabel,
                'localRedeemed'  => $localRedeemed,
                'localExpired'   => $localExpired,
            ]);

            $shouldBlock = false;
            $reason      = '';

            if ($checkSuccess === true && in_array($srsStatusLabel, self::BLOCKED_STATUS_LABELS, true)) {
                $shouldBlock = true;
                $reason      = $srsStatusLabel === 'expired' ? 'verlopen' : 'al gebruikt';
            } elseif ($localRedeemed === true || $localExpired === true) {
                $shouldBlock = true;
                $reason      = $localExpired ? 'verlopen' : 'al gebruikt';
            }

            if ($shouldBlock) {
                $this->logger->warning('SrsDiscountAddedSubscriber: Blocking expired/redeemed code, removing from cart', [
                    'code'   => $promotionCode,
                    'reason' => $reason,
                ]);

                // Remove the promotion line item from the cart
                try {
                    $this->cartService->remove($cart, $lineItem->getId(), $context);
                } catch (\Throwable $e) {
                    $this->logger->error('SrsDiscountAddedSubscriber: Failed to remove line item from cart', [
                        'code'  => $promotionCode,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
