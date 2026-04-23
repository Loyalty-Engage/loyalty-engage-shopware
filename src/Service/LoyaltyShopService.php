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
 *                                  buy_discount_code endpoint and return the resulting
 *                                  discount code to the browser so the customer can use it.
 */
class LoyaltyShopService
{
    private const HTTP_OK = 200;

    private CartService $cartService;
    private CartPersister $cartPersister;
    private EntityRepository $productRepository;
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private LoggerInterface $logger;

    public function __construct(
        CartService $cartService,
        CartPersister $cartPersister,
        EntityRepository $productRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->cartService             = $cartService;
        $this->cartPersister           = $cartPersister;
        $this->productRepository       = $productRepository;
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
     *  2. Return the discount code(s) to the browser.
     *
     * The $discount parameter is passed through from the widget but is not used
     * by the buy_discount_code endpoint – the discount value is determined server-
     * side by LoyaltyEngage based on the SKU.
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

            if ($discountCode) {
                return array_merge(
                    $this->success("Discount code claimed successfully: {$discountCode}"),
                    ['discountCode' => $discountCode, 'apiResponse' => $result]
                );
            }

            // API returned success but no code in the expected fields – still a success
            return array_merge(
                $this->success('Discount code claimed successfully.'),
                ['apiResponse' => $result]
            );
        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyShopService: claimDiscountCodeProduct failed', [
                'sku'   => $sku,
                'error' => $e->getMessage(),
            ]);

            return $this->error('An error occurred while claiming the discount code.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
