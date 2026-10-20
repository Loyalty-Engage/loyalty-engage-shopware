<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
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
    private EntityRepository $promotionRepository;
    private LoggerInterface $logger;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $promotionIndividualCodeRepository,
        EntityRepository $promotionRepository,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService           = $loyaltyEngageApiService;
        $this->promotionIndividualCodeRepository = $promotionIndividualCodeRepository;
        $this->promotionRepository               = $promotionRepository;
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
        if (!$this->loyaltyEngageApiService->isSrsDiscountSyncEnabled($event->getSalesChannelContext()->getSalesChannelId())) {
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

        $promotionName = $this->resolvePromotionName($promotionCode, $context);
        if ($promotionName === null) {
            $this->logger->warning('SrsDiscountAddedSubscriber: Code not found in Shopware promotions', [
                'code' => $promotionCode,
            ]);
            return;
        }

        if (!str_starts_with($promotionName, self::PROMOTION_NAME_PREFIX)) {
            return;
        }

        $this->logger->warning('SrsDiscountAddedSubscriber: LoyaltyEngage code added to cart, checking SRS', [
            'code'          => $promotionCode,
            'promotionName' => $promotionName,
        ]);

        $srsResult = $this->loyaltyEngageApiService->checkSrsVoucherStatus($promotionCode, $event->getSalesChannelContext()->getSalesChannelId());
        $blockReason = $this->loyaltyEngageApiService->getVoucherBlockStateFromSrsResult($srsResult);

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

    private function resolvePromotionName(string $promotionCode, $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('code', $promotionCode));
        $criteria->addAssociation('promotion');
        $criteria->setLimit(1);

        $searchResult = $this->promotionIndividualCodeRepository->search($criteria, $context->getContext());
        $codeEntity   = $searchResult->first();

        if ($codeEntity instanceof Entity) {
            $promotionName = $this->extractPromotionName($codeEntity->get('promotion'));
            if ($promotionName !== '') {
                return $promotionName;
            }
        }

        $promotionCriteria = new Criteria();
        $promotionCriteria->addFilter(new EqualsFilter('code', $promotionCode));
        $promotionCriteria->addAssociation('translations');
        $promotionCriteria->setLimit(1);

        $promotion = $this->promotionRepository->search($promotionCriteria, $context->getContext())->first();
        if (!$promotion instanceof Entity) {
            return null;
        }

        $translatedName = (string) $promotion->getTranslation('name');
        if ($translatedName !== '') {
            return $translatedName;
        }

        return $this->extractPromotionName($promotion);
    }

    private function extractPromotionName($promotion): string
    {
        if ($promotion === null) {
            return '';
        }

        if (is_array($promotion)) {
            return (string) ($promotion['name'] ?? '');
        }

        if (method_exists($promotion, 'getTranslation')) {
            $translatedName = (string) $promotion->getTranslation('name');
            if ($translatedName !== '') {
                return $translatedName;
            }
        }

        if (method_exists($promotion, 'getName')) {
            return (string) $promotion->getName();
        }

        if (method_exists($promotion, 'getVars')) {
            $vars = $promotion->getVars();
            return (string) ($vars['name'] ?? '');
        }

        return '';
    }
}
