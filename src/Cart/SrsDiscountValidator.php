<?php declare(strict_types=1);

namespace LoyaltyEngage\Cart;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Validates LoyaltyEngage discount codes against the SRS API before checkout.
 *
 * Only active when "SRS Discount Sync" is enabled in the plugin config.
 * For each LoyaltyEngage promotion code in the cart, the SRS API is called
 * to check whether the voucher has already been redeemed or is expired.
 *
 * Blocked when:
 *   - srs_status_label === "redeemed"  (already used)
 *   - srs_status_label === "expired"   (past valid_to date)
 *   - local_redeemed === true          (redeemed in LoyaltyEngage system)
 *   - local_expired === true           (expired in LoyaltyEngage system)
 *
 * Fail-open: if the SRS API call fails (timeout, network error), the code
 * is allowed through to avoid blocking legitimate customers.
 */
class SrsDiscountValidator implements CartValidatorInterface
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

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Only run when SRS Discount Sync is enabled
        if (!$this->loyaltyEngageApiService->isSrsDiscountSyncEnabled($context->getSalesChannelId())) {
            return;
        }

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== 'promotion') {
                continue;
            }

            // In the cart, the promotion code is stored in the line item payload under 'code'.
            // getReferencedId() returns the promotion UUID, not the code string.
            $payload       = $lineItem->getPayload() ?? [];
            $promotionCode = $payload['code'] ?? null;

            // Fallback: some Shopware versions store it under 'promotionCode'
            if (empty($promotionCode)) {
                $promotionCode = $payload['promotionCode'] ?? null;
            }

            // Last fallback: try getReferencedId() in case it is the code
            if (empty($promotionCode)) {
                $promotionCode = $lineItem->getReferencedId();
            }

            if (empty($promotionCode)) {
                $this->logger->warning('SrsDiscountValidator: No promotion code found in line item', [
                    'lineItemId' => $lineItem->getId(),
                    'payload'    => $payload,
                ]);
                continue;
            }

            $promotionName = $this->resolvePromotionName($promotionCode, $context);
            if ($promotionName === null) {
                $this->logger->warning('SrsDiscountValidator: Code not found in Shopware promotions', [
                    'code' => $promotionCode,
                ]);
                continue;
            }

            $this->logger->warning('SrsDiscountValidator: Found promotion code in cart', [
                'code'          => $promotionCode,
                'promotionName' => $promotionName,
            ]);

            if (!str_starts_with($promotionName, self::PROMOTION_NAME_PREFIX)) {
                $this->logger->warning('SrsDiscountValidator: Skipping non-LoyaltyEngage promotion', [
                    'code'          => $promotionCode,
                    'promotionName' => $promotionName,
                ]);
                continue;
            }

            // This is a LoyaltyEngage code — check SRS status
            $srsResult = $this->loyaltyEngageApiService->checkSrsVoucherStatus($promotionCode, $context->getSalesChannelId());
            $blockReason    = $this->loyaltyEngageApiService->getVoucherBlockStateFromSrsResult($srsResult);

            $this->logger->warning('SrsDiscountValidator: SRS voucher status result', [
                'code'        => $promotionCode,
                'blockReason' => $blockReason,
                'srsResult'   => $srsResult,
            ]);

            if ($blockReason !== null) {
                $this->logger->warning('SrsDiscountValidator: Code is blocked, preventing checkout', [
                    'code'       => $promotionCode,
                    'reason'     => $blockReason,
                    'srsResult'  => $srsResult,
                ]);

                $errors->add(new GenericCartError(
                    md5('srs-blocked-' . $promotionCode),
                    'Deze kortingscode is ' . $blockReason . '.',
                    [],
                    GenericCartError::LEVEL_ERROR,
                    false,
                    true
                ));
            }
        }
    }

    private function resolvePromotionName(string $promotionCode, SalesChannelContext $context): ?string
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
