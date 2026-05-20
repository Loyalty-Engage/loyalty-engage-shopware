<?php declare(strict_types=1);

namespace LoyaltyEngage\Cart;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
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

    /** SRS status labels that should block checkout */
    private const BLOCKED_STATUS_LABELS = ['redeemed', 'expired'];

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

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Only run when SRS Discount Sync is enabled
        if (!$this->loyaltyEngageApiService->isSrsDiscountSyncEnabled()) {
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

            // Check if this code belongs to a LoyaltyEngage promotion
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $promotionCode));
            $criteria->addAssociation('promotion');
            $criteria->setLimit(1);

            $searchResult = $this->promotionIndividualCodeRepository->search($criteria, $context->getContext());
            $codeEntity   = $searchResult->first();

            if ($codeEntity === null) {
                $this->logger->warning('SrsDiscountValidator: Code not found in promotion_individual_code', [
                    'code' => $promotionCode,
                ]);
                continue;
            }

            // Resolve promotion name via the loaded association
            $promotionName = '';
            try {
                $promotion = $codeEntity->get('promotion');
                if ($promotion !== null && method_exists($promotion, 'getName')) {
                    $promotionName = (string) $promotion->getName();
                }
            } catch (\Throwable $e) {
                // Fallback: try getVars
                $vars      = $codeEntity->getVars();
                $promotion = $vars['promotion'] ?? null;
                if (is_array($promotion)) {
                    $promotionName = (string) ($promotion['name'] ?? '');
                } elseif ($promotion !== null && method_exists($promotion, 'getName')) {
                    $promotionName = (string) $promotion->getName();
                }
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
            $srsResult = $this->loyaltyEngageApiService->checkSrsVoucherStatus($promotionCode);

            if (empty($srsResult)) {
                // API call failed — fail open, allow the code through
                $this->logger->warning('SrsDiscountValidator: SRS API call failed or returned empty, allowing code through', [
                    'code' => $promotionCode,
                ]);
                continue;
            }

            $checkSuccess   = $srsResult['srs_check_success'] ?? false;
            $srsStatus      = $srsResult['srs_status'] ?? null;
            $srsStatusLabel = $srsResult['srs_status_label'] ?? null;
            $localRedeemed  = $srsResult['local_redeemed'] ?? false;
            $localExpired   = $srsResult['local_expired'] ?? false;

            $this->logger->warning('SrsDiscountValidator: SRS voucher status result', [
                'code'           => $promotionCode,
                'checkSuccess'   => $checkSuccess,
                'srsStatus'      => $srsStatus,
                'srsStatusLabel' => $srsStatusLabel,
                'localRedeemed'  => $localRedeemed,
                'localExpired'   => $localExpired,
            ]);

            // Block if voucher is expired
            if ($checkSuccess === true && $srsStatusLabel === 'expired') {
                $this->logger->warning('SrsDiscountValidator: Code is expired via SRS, blocking checkout', [
                    'code'      => $promotionCode,
                    'srsResult' => $srsResult,
                ]);

                $errors->add(new GenericCartError(
                    md5('srs-expired-' . $promotionCode),
                    'Deze kortingscode is verlopen.',
                    [],
                    GenericCartError::LEVEL_ERROR,
                    false,
                    true
                ));
                continue;
            }

            // Block if voucher is already redeemed
            if ($checkSuccess === true && $srsStatusLabel === 'redeemed') {
                $this->logger->warning('SrsDiscountValidator: Code already redeemed via SRS, blocking checkout', [
                    'code'      => $promotionCode,
                    'srsResult' => $srsResult,
                ]);

                $errors->add(new GenericCartError(
                    md5('srs-redeemed-' . $promotionCode),
                    'Deze kortingscode is al gebruikt.',
                    [],
                    GenericCartError::LEVEL_ERROR,
                    false,
                    true
                ));
                continue;
            }

            // Block if locally redeemed or expired
            if ($localRedeemed === true || $localExpired === true) {
                $reason = $localExpired ? 'verlopen' : 'al gebruikt';
                $this->logger->warning('SrsDiscountValidator: Code is locally redeemed/expired, blocking checkout', [
                    'code'         => $promotionCode,
                    'localRedeemed' => $localRedeemed,
                    'localExpired'  => $localExpired,
                ]);

                $errors->add(new GenericCartError(
                    md5('srs-local-' . $promotionCode),
                    'Deze kortingscode is ' . $reason . '.',
                    [],
                    GenericCartError::LEVEL_ERROR,
                    false,
                    true
                ));
            }
        }
    }
}
