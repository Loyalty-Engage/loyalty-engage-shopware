<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

/**
 * Service for the Loyalty Shop storefront widget.
 *
 * Handles two flows that are triggered by the embedded HTML/JS widget:
 *
 *  1. addProductBySku()         – physical product: look up Shopware product by SKU,
 *                                 verify eligibility via LoyaltyEngage API, add to cart at €0.
 *
 *  2. claimDiscountCodeProduct() – discount-code product: call the LoyaltyEngage
 *                                  buy_discount_code endpoint, create a Shopware promotion
 *                                  with the returned code, apply it to the cart.
 */
class LoyaltyShopService
{
    private const HTTP_OK = 200;

    private CartService $cartService;
    private CartPersister $cartPersister;
    private EntityRepository $productRepository;
    private EntityRepository $promotionRepository;
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        CartService $cartService,
        CartPersister $cartPersister,
        EntityRepository $productRepository,
        EntityRepository $promotionRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->cartService             = $cartService;
        $this->cartPersister           = $cartPersister;
        $this->productRepository       = $productRepository;
        $this->promotionRepository     = $promotionRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger                  = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Add a physical loyalty product to the Shopware cart by its SKU.
     *
     * Flow:
     *  1. Look up the Shopware product UUID by productNumber (= SKU).
     *  2. Call LoyaltyEngage API to verify eligibility / deduct points.
     *  3. Add the product to the cart at price €0.
     */
    public function addProductBySku(string $email, string $sku, SalesChannelContext $context): array
    {
        // 1. Resolve SKU → Shopware product UUID
        $productId = $this->resolveProductIdBySku($sku, $context);
        if ($productId === null) {
            return $this->error("Product with SKU '{$sku}' not found.");
        }

        // 2. Check eligibility with LoyaltyEngage API
        $apiStatus = $this->loyaltyEngageApiService->addToCart($email, $sku);
        if ($apiStatus !== self::HTTP_OK) {
            return $this->error('Product could not be added. User is not eligible.');
        }

        // 3. Add to Shopware cart at €0
        try {
            $criteria = new Criteria([$productId]);
            /** @var ProductEntity|null $product */
            $product  = $this->productRepository->search($criteria, $context->getContext())->first();

            if (!$product instanceof ProductEntity) {
                return $this->error('Product not found in Shopware.');
            }

            $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, 1);
            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);
            $lineItem->setPriceDefinition(
                new QuantityPriceDefinition(0, $context->buildTaxRules($product->getTaxId()), 1)
            );
            // Mark as loyalty free product so LoyaltyFreeProductProcessor forces price to €0
            // after Shopware's standard product processor runs
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
     *  2. Create a Shopware promotion with the returned discount code.
     *  3. Apply the promotion code to the customer's cart.
     *  4. Return success with the discount code.
     *
     * The $discount parameter contains the discount percentage (e.g. 15 for 15%).
     * If not provided or 0, we try to extract it from the API response.
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
                // API returned success but no code – still return success
                return array_merge(
                    $this->success('Discount code claimed successfully.'),
                    ['apiResponse' => $result]
                );
            }

            // Extract discount percentage from API response or use passed value
            // API may return: discountPercentage, percentage, discount_percentage, value
            $discountPercentage = (float) (
                $result['discountPercentage']
                ?? $result['percentage']
                ?? $result['discount_percentage']
                ?? $result['value']
                ?? $discount
                ?? 0
            );

            // Create the Shopware promotion with this code
            $promotionCreated = $this->createShopwarePromotion(
                $discountCode,
                $discountPercentage,
                $context
            );

            if (!$promotionCreated) {
                $this->logger->warning('LoyaltyShopService: Could not create Shopware promotion, returning code only', [
                    'discountCode' => $discountCode,
                ]);
                // Still return the code so the customer can use it manually
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

            // Promotion created but could not be applied to cart (cart may be empty)
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
     * Create a Shopware promotion with the given discount code.
     *
     * Uses a global code (not individual codes) so the code can be applied
     * directly to the cart. The promotion is:
     *  - Active immediately
     *  - Valid for 1 year
     *  - Max 1 redemption per customer
     *  - Percentage discount on the cart total
     *  - Available in all sales channels
     */
    private function createShopwarePromotion(
        string $code,
        float $discountPercentage,
        SalesChannelContext $context
    ): bool {
        try {
            $promotionId = Uuid::randomHex();
            $discountId  = Uuid::randomHex();

            // Use at least 1% if no percentage provided (fallback)
            if ($discountPercentage <= 0) {
                $discountPercentage = 1.0;
            }

            $salesChannelId = $context->getSalesChannelId();

            $promotionData = [
                'id'                     => $promotionId,
                'name'                   => 'LoyaltyEngage: ' . $code,
                'active'                 => true,
                'validFrom'              => null,
                'validUntil'             => (new \DateTime('+1 year'))->format(\DateTime::ATOM),
                'maxRedemptionsGlobal'   => 1,
                'maxRedemptionsPerCustomer' => 1,
                'priority'               => 1,
                'exclusive'              => false,
                'useCodes'               => true,
                'useIndividualCodes'     => false,
                'useSetGroups'           => false,
                'customerRestriction'    => false,
                'preventCombination'     => false,
                'code'                   => $code,
                'translations'           => [
                    ['languageId' => $context->getContext()->getLanguageId(), 'name' => 'LoyaltyEngage: ' . $code],
                ],
                'salesChannels'          => [
                    ['salesChannelId' => $salesChannelId, 'priority' => 1],
                ],
                'discounts'              => [
                    [
                        'id'                => $discountId,
                        'scope'             => 'cart',
                        'type'              => 'percentage',
                        'value'             => $discountPercentage,
                        'considerAdvancedRules' => false,
                        'sorterKey'         => 'PRICE_ASC',
                        'applierKey'        => 'ALL',
                        'usageKey'          => 'ALL',
                    ],
                ],
            ];

            $this->promotionRepository->create([$promotionData], $context->getContext());

            $this->logger->info('LoyaltyShopService: Shopware promotion created', [
                'promotionId'        => $promotionId,
                'code'               => $code,
                'discountPercentage' => $discountPercentage,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: Failed to create Shopware promotion', [
                'code'  => $code,
                'error' => $e->getMessage(),
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

            // Check if cart has items (promotion can only be applied to non-empty cart)
            if ($cart->getLineItems()->count() === 0) {
                return false;
            }

            // Add promotion line item to cart
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
