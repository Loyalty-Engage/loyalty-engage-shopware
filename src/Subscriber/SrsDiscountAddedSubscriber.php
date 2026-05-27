<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates LoyaltyEngage promotion codes against the SRS API
 * before Shopware recalculates the cart (e.g. via the cart overlay).
 *
 * If the code is expired or already redeemed, it is removed from the cart
 * before calculation so the voucher is not applied at all.
 */
class SrsDiscountAddedSubscriber implements EventSubscriberInterface
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
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        // Only run when SRS Discount Sync is enabled
        if (!$this->loyaltyEngageApiService->isSrsDiscountSyncEnabled()) {
            return;
        }

        $lineItem = $event->getLineItem();
        if ($lineItem->getType() !== 'promotion') {
            return;
        }

        $cart    = $event->getCart();
        $context = $event->getSalesChannelContext();

        // Get the promotion code from payload or referencedId
        $payload       = $lineItem->getPayload() ?? [];
        $promotionCode = $payload['code'] ?? $payload['promotionCode'] ?? $lineItem->getReferencedId();

        if (empty($promotionCode)) {
            return;
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
            return;
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
            return;
        }

        $this->logger->warning('SrsDiscountAddedSubscriber: LoyaltyEngage code added to cart, checking SRS', [
            'code'          => $promotionCode,
            'promotionName' => $promotionName,
        ]);

        $srsResult = $this->loyaltyEngageApiService->checkSrsVoucherStatus($promotionCode);
        if (empty($srsResult)) {
            $this->logger->warning('SrsDiscountAddedSubscriber: SRS API call failed, allowing code through', [
                'code' => $promotionCode,
            ]);
            return;
        }

        $blockReason = $this->loyaltyEngageApiService->getVoucherBlockState($promotionCode);

        $this->logger->warning('SrsDiscountAddedSubscriber: SRS result', [
            'code'        => $promotionCode,
            'blockReason' => $blockReason,
            'srsResult'   => $srsResult,
        ]);

        if ($blockReason === null) {
            return;
        }

        $this->logger->warning('SrsDiscountAddedSubscriber: Blocking expired/redeemed code before cart calculation', [
            'code'   => $promotionCode,
            'reason' => $blockReason,
        ]);

        $cart->remove($lineItem->getId());
        $cart->getErrors()->add(new GenericCartError(
            md5('srs-before-add-' . $promotionCode),
            'Deze kortingscode is ' . $blockReason . '.',
            [],
            GenericCartError::LEVEL_ERROR,
            false,
            true
        ));
        $cart->markModified();
    }
}
