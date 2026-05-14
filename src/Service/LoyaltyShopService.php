<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

/**
 * Service for the Loyalty Shop storefront widget.
 *
 * Handles two flows that are triggered by the embedded HTML/JS widget:
 *
 *  1. addProductBySku()          – physical product: look up Shopware product by SKU,
 *                                  verify eligibility via LoyaltyEngage API, add to cart at €0.
 *
 *  2. claimDiscountCodeProduct() – discount-code product: call the LoyaltyEngage
 *                                  buy_discount_code endpoint, find or create ONE shared
 *                                  Shopware promotion per discount-type (identified by SKU),
 *                                  add the returned code as an individual code to that promotion,
 *                                  and apply it to the cart.
 *
 * Promotion strategy:
 *  - One promotion per SKU (e.g. all "€10 voucher" codes share one promotion).
 *  - useIndividualCodes = true, so each claimed code is a separate individual code.
 *  - After redemption the individual code is removed from Shopware and marked
 *    as redeemed in LoyaltyEngage (handled by DiscountRedemptionSubscriber).
 */
class LoyaltyShopService
{
    private const HTTP_OK = 200;

    /** Prefix used for promotion names so we can find them back by SKU. */
    private const PROMOTION_NAME_PREFIX = 'LoyaltyEngage: ';

    private CartService $cartService;
    private CartPersister $cartPersister;
    private EntityRepository $productRepository;
    private EntityRepository $promotionRepository;
    private EntityRepository $promotionIndividualCodeRepository;
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        CartService $cartService,
        CartPersister $cartPersister,
        EntityRepository $productRepository,
        EntityRepository $promotionRepository,
        EntityRepository $promotionIndividualCodeRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->cartService                       = $cartService;
        $this->cartPersister                     = $cartPersister;
        $this->productRepository                 = $productRepository;
        $this->promotionRepository               = $promotionRepository;
        $this->promotionIndividualCodeRepository = $promotionIndividualCodeRepository;
        $this->loyaltyEngageApiService           = $loyaltyEngageApiService;
        $this->logger                            = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Add a physical loyalty product to the Shopware cart by its SKU.
     *
     * Flow:
     *  1. Look up the Shopware product UUID by productNumber (= SKU).
     *  2. Check if the maximum number of loyalty products per cart has been reached.
     *  3. Call LoyaltyEngage API to verify eligibility / deduct points.
     *  4. Add the product to the cart at price €0.
     */
    public function addProductBySku(string $email, string $sku, SalesChannelContext $context): array
    {
        // 1. Resolve SKU → Shopware product UUID
        $productId = $this->resolveProductIdBySku($sku, $context);
        if ($productId === null) {
            return $this->error("Product with SKU '{$sku}' not found.");
        }

        // 2. Check if the maximum number of loyalty products per cart has been reached
        $maxLoyaltyProducts = $this->loyaltyEngageApiService->getMaxLoyaltyProductsPerCart();
        if ($maxLoyaltyProducts > 0) {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $currentLoyaltyCount = 0;
            foreach ($cart->getLineItems() as $lineItem) {
                $payload = $lineItem->getPayload() ?? [];
                if (($payload['loyaltyFreeProduct'] ?? false) === true) {
                    $currentLoyaltyCount++;
                }
            }
            if ($currentLoyaltyCount >= $maxLoyaltyProducts) {
                return $this->error(
                    sprintf(
                        'Maximum loyalty products per cart reached. You can only add %d loyalty product(s) per purchase.',
                        $maxLoyaltyProducts
                    )
                );
            }
        }

        // 3. Check eligibility with LoyaltyEngage API
        $apiStatus = $this->loyaltyEngageApiService->addToCart($email, $sku);
        if ($apiStatus !== self::HTTP_OK) {
            return $this->error('Product could not be added. User is not eligible.');
        }

        // 4. Add to Shopware cart at €0
        try {
            $criteria = new Criteria([$productId]);
            /** @var ProductEntity|null $product */
            $product = $this->productRepository->search($criteria, $context->getContext())->first();

            if (!$product instanceof ProductEntity) {
                return $this->error('Product not found in Shopware.');
            }

            $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, 1);
            $lineItem->setStackable(false);
            $lineItem->setRemovable(true);
            $lineItem->setPriceDefinition(
                new QuantityPriceDefinition(0, $context->buildTaxRules($product->getTaxId()), 1)
            );
            // Mark as loyalty free product so LoyaltyFreeProductProcessor forces price to €0
            $lineItem->setPayloadValue('loyaltyFreeProduct', true);

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart->add($lineItem);
            $this->cartPersister->save($cart, $context);

            return $this->success('Product added to cart successfully.');
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: addProductBySku failed', [
                'sku'       => $sku,
                'productId' => $productId,
                'error'     => $e->getMessage(),
            ]);

            return $this->error('An error occurred while adding the product to the cart.');
        }
    }

    /**
     * Claim a discount-code product from the LoyaltyEngage shop.
     *
     * Flow:
     *  1. Call LoyaltyEngage buy_discount_code endpoint with the SKU.
     *  2. Find or create ONE shared Shopware promotion for this SKU.
     *  3. Add the returned code as an individual code to that promotion.
     *  4. Apply the promotion code to the customer's cart.
     *  5. Return success with the discount code.
     */
    public function claimDiscountCodeProduct(
        string $email,
        string $sku,
        float $discount,
        SalesChannelContext $context
    ): array {
        try {
            $result = $this->loyaltyEngageApiService->buyDiscountCodeProduct($email, $sku);

            if ($result === null) {
                return $this->error('Discount code could not be claimed. Please try again.');
            }

            // Extract discount code from the API response
            $discountCode = $result['discountCode'] ?? $result['code'] ?? null;

            if (!$discountCode) {
                return array_merge(
                    $this->success('Discount code claimed successfully.'),
                    ['apiResponse' => $result]
                );
            }

            // Determine discount type and value from API response.
            // The API returns either:
            //   - discountPercentage (float) + discountAmount null    → percentage discount
            //   - discountPercentage null    + discountAmount (float) → absolute fixed amount in EUR
            $apiDiscountPercentage = isset($result['discountPercentage']) && $result['discountPercentage'] !== null
                ? (float) $result['discountPercentage']
                : null;

            $apiDiscountAmount = isset($result['discountAmount']) && $result['discountAmount'] !== null
                ? (float) $result['discountAmount']
                : null;

            if ($apiDiscountAmount !== null && $apiDiscountPercentage === null) {
                $promotionDiscountValue = $apiDiscountAmount;
                $promotionDiscountType  = 'absolute';
            } else {
                $promotionDiscountValue = $apiDiscountPercentage ?? (float) $discount;
                $promotionDiscountType  = 'percentage';
            }

            $this->logger->info('LoyaltyShopService: discount values from API', [
                'sku'                  => $sku,
                'discountCode'         => $discountCode,
                'apiDiscountAmount'    => $apiDiscountAmount,
                'apiDiscountPercentage'=> $apiDiscountPercentage,
                'promotionDiscountValue' => $promotionDiscountValue,
                'promotionDiscountType'  => $promotionDiscountType,
            ]);

            // Find or create the shared promotion for this SKU
            $promotionId = $this->findOrCreatePromotion(
                $sku,
                $promotionDiscountValue,
                $promotionDiscountType,
                $context
            );

            if ($promotionId === null) {
                $this->logger->warning('LoyaltyShopService: Could not find or create Shopware promotion', [
                    'discountCode' => $discountCode,
                    'sku'          => $sku,
                ]);
                return array_merge(
                    $this->success("Kortingscode: {$discountCode}"),
                    ['discountCode' => $discountCode, 'apiResponse' => $result]
                );
            }

            // Add the individual code to the shared promotion
            $codeAdded = $this->addIndividualCode($promotionId, $discountCode, $context->getContext());

            if (!$codeAdded) {
                $this->logger->warning('LoyaltyShopService: Could not add individual code to promotion', [
                    'promotionId'  => $promotionId,
                    'discountCode' => $discountCode,
                ]);
                return array_merge(
                    $this->success("Kortingscode: {$discountCode}"),
                    ['discountCode' => $discountCode, 'apiResponse' => $result]
                );
            }

            // Apply the promotion code to the cart
            $applied = $this->applyPromotionToCart($discountCode, $context);

            if ($applied) {
                return array_merge(
                    $this->success("Kortingscode '{$discountCode}' is toegepast op uw winkelwagen!"),
                    ['discountCode' => $discountCode, 'apiResponse' => $result]
                );
            }

            return array_merge(
                $this->success("Kortingscode aangemaakt: {$discountCode}. Voeg producten toe aan uw winkelwagen en gebruik de code bij het afrekenen."),
                ['discountCode' => $discountCode, 'apiResponse' => $result]
            );

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: claimDiscountCodeProduct failed', [
                'sku'   => $sku,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('An error occurred while claiming the discount code.');
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find the shared Shopware promotion for a given SKU, or create it if it does not exist yet.
     *
     * All discount codes of the same type (same SKU) share ONE promotion with
     * useIndividualCodes = true. This keeps the Shopware promotion list clean.
     *
     * @return string|null The promotion ID, or null on failure.
     */
    private function findOrCreatePromotion(
        string $sku,
        float $discountValue,
        string $discountType,
        SalesChannelContext $context
    ): ?string {
        $promotionName = self::PROMOTION_NAME_PREFIX . $sku;

        // Try to find an existing promotion for this SKU
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $promotionName));
        $criteria->addAssociation('discounts');
        $criteria->setLimit(1);

        $searchResult = $this->promotionRepository->search($criteria, $context->getContext());
        $existingIds  = $searchResult->getIds();

        if (!empty($existingIds)) {
            $existingId = reset($existingIds);

            // Update the discount value/type if it differs from what the API returned.
            // This fixes promotions that were created with the wrong value (e.g. value=1
            // from the old fallback) before this fix was deployed.
            $this->updatePromotionDiscountIfNeeded(
                $existingId,
                $discountValue,
                $discountType,
                $context->getContext()
            );

            $this->logger->info('LoyaltyShopService: Found existing promotion for SKU', [
                'sku'         => $sku,
                'promotionId' => $existingId,
            ]);
            return $existingId;
        }

        // Create a new shared promotion for this SKU
        return $this->createSharedPromotion($sku, $promotionName, $discountValue, $discountType, $context);
    }

    /**
     * Update the discount value and type on an existing promotion if they differ
     * from the values returned by the API. This self-heals promotions that were
     * created with the wrong value before the absolute-discount fix was deployed.
     */
    private function updatePromotionDiscountIfNeeded(
        string $promotionId,
        float $discountValue,
        string $discountType,
        Context $context
    ): void {
        try {
            // Load the promotion with its discounts
            $criteria = new Criteria([$promotionId]);
            $criteria->addAssociation('discounts');

            $promotion = $this->promotionRepository->search($criteria, $context)->first();
            if ($promotion === null) {
                return;
            }

            $vars     = $promotion->getVars();
            $discounts = $vars['discounts'] ?? null;

            if ($discounts === null) {
                return;
            }

            // Iterate over discount entities and update any that have the wrong value/type
            foreach ($discounts as $discountEntity) {
                $discountVars      = $discountEntity->getVars();
                $currentValue      = (float) ($discountVars['value'] ?? 0);
                $currentType       = (string) ($discountVars['type'] ?? '');
                $discountEntityId  = (string) ($discountVars['id'] ?? '');

                if ($discountEntityId === '') {
                    continue;
                }

                if (abs($currentValue - $discountValue) > 0.001 || $currentType !== $discountType) {
                    $this->promotionRepository->update([
                        [
                            'id'        => $promotionId,
                            'discounts' => [
                                [
                                    'id'    => $discountEntityId,
                                    'type'  => $discountType,
                                    'value' => $discountValue,
                                ],
                            ],
                        ],
                    ], $context);

                    $this->logger->info('LoyaltyShopService: Updated promotion discount value', [
                        'promotionId'   => $promotionId,
                        'discountId'    => $discountEntityId,
                        'oldType'       => $currentType,
                        'oldValue'      => $currentValue,
                        'newType'       => $discountType,
                        'newValue'      => $discountValue,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('LoyaltyShopService: Could not update promotion discount', [
                'promotionId' => $promotionId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new shared Shopware promotion for a discount SKU.
     *
     * Uses individual codes (useIndividualCodes = true) so each claimed code
     * is a separate entry. The promotion itself has no global code.
     *
     * @param string $discountType 'percentage' or 'absolute'
     * @return string|null The new promotion ID, or null on failure.
     */
    private function createSharedPromotion(
        string $sku,
        string $promotionName,
        float $discountValue,
        string $discountType,
        SalesChannelContext $context
    ): ?string {
        try {
            if ($discountValue <= 0) {
                $discountValue = 1.0;
            }

            $promotionId = Uuid::randomHex();
            $discountId  = Uuid::randomHex();
            $salesChannelId = $context->getSalesChannelId();

            $promotionData = [
                'id'                        => $promotionId,
                'name'                      => $promotionName,
                'active'                    => true,
                'validFrom'                 => null,
                'validUntil'                => (new \DateTime('+10 years'))->format(\DateTime::ATOM),
                'maxRedemptionsGlobal'      => null,  // No global limit; each individual code has its own limit
                'maxRedemptionsPerCustomer' => 1,
                'priority'                  => 1,
                'exclusive'                 => false,
                'useCodes'                  => true,
                'useIndividualCodes'        => true,  // Each claimed code is an individual code
                'useSetGroups'              => false,
                'customerRestriction'       => false,
                'preventCombination'        => false,
                'translations'              => [
                    ['languageId' => $context->getContext()->getLanguageId(), 'name' => $promotionName],
                ],
                'salesChannels'             => [
                    ['salesChannelId' => $salesChannelId, 'priority' => 1],
                ],
                'discounts'                 => [
                    [
                        'id'                    => $discountId,
                        'scope'                 => 'cart',
                        'type'                  => $discountType,
                        'value'                 => $discountValue,
                        'considerAdvancedRules' => false,
                        'sorterKey'             => 'PRICE_ASC',
                        'applierKey'            => 'ALL',
                        'usageKey'              => 'ALL',
                    ],
                ],
            ];

            $this->promotionRepository->create([$promotionData], $context->getContext());

            $this->logger->info('LoyaltyShopService: Created shared promotion for SKU', [
                'promotionId'   => $promotionId,
                'sku'           => $sku,
                'discountType'  => $discountType,
                'discountValue' => $discountValue,
            ]);

            return $promotionId;
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: Failed to create shared promotion', [
                'sku'   => $sku,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Add an individual code to an existing promotion.
     */
    private function addIndividualCode(string $promotionId, string $code, Context $context): bool
    {
        try {
            $this->promotionIndividualCodeRepository->create([
                [
                    'id'          => Uuid::randomHex(),
                    'promotionId' => $promotionId,
                    'code'        => $code,
                ],
            ], $context);

            $this->logger->info('LoyaltyShopService: Individual code added to promotion', [
                'promotionId' => $promotionId,
                'code'        => $code,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: Failed to add individual code', [
                'promotionId' => $promotionId,
                'code'        => $code,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Apply a promotion code to the customer's cart.
     */
    private function applyPromotionToCart(string $code, SalesChannelContext $context): bool
    {
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);

            if ($cart->getLineItems()->count() === 0) {
                return false;
            }

            $promotionLineItem = new LineItem(
                Uuid::randomHex(),
                LineItem::PROMOTION_LINE_ITEM_TYPE,
                null,
                1
            );
            $promotionLineItem->setReferencedId($code);
            $promotionLineItem->setLabel($code);
            $promotionLineItem->setRemovable(true);
            $promotionLineItem->setStackable(false);

            $cart->add($promotionLineItem);
            $this->cartService->recalculate($cart, $context);
            $this->cartPersister->save($cart, $context);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('LoyaltyShopService: Could not apply promotion to cart', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Look up a Shopware product UUID by its productNumber (= SKU).
     */
    private function resolveProductIdBySku(string $sku, SalesChannelContext $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $sku));
        $criteria->setLimit(1);

        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context->getContext())->first();

        return $product instanceof ProductEntity ? $product->getId() : null;
    }

    private function success(string $message): array
    {
        return ['success' => true, 'message' => $message];
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
