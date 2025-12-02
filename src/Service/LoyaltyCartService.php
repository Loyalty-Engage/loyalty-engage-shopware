<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class LoyaltyCartService
{
    private const HTTP_OK = 200;
    private const HTTP_BAD_REQUEST = 401;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var CartPersister
     */
    private $cartPersister;

    /**
     * @var EntityRepositoryInterface
     */
    private $productRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $promotionRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $promotionDiscountRepository;

    /**
     * @var LoyaltyEngageApiService
     */
    private $loyaltyEngageApiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CartService $cartService
     * @param CartPersister $cartPersister
     * @param EntityRepositoryInterface $productRepository
     * @param EntityRepositoryInterface $promotionRepository
     * @param EntityRepositoryInterface $promotionDiscountRepository
     * @param LoyaltyEngageApiService $loyaltyEngageApiService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CartService $cartService,
        CartPersister $cartPersister,
        EntityRepositoryInterface $productRepository,
        EntityRepositoryInterface $promotionRepository,
        EntityRepositoryInterface $promotionDiscountRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->cartService = $cartService;
        $this->cartPersister = $cartPersister;
        $this->productRepository = $productRepository;
        $this->promotionRepository = $promotionRepository;
        $this->promotionDiscountRepository = $promotionDiscountRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger = $logger;
    }

    /**
     * Add a product to the cart using loyalty points
     */
    public function addProduct(string $email, string $productId, SalesChannelContext $context): array
    {
        if (empty($email) || empty($productId)) {
            return $this->createErrorResponse('Email and Product ID are required.');
        }

        // Check with loyalty API if the customer is eligible
        $apiResponse = $this->loyaltyEngageApiService->addToCart($email, $productId);
        if ($apiResponse !== self::HTTP_OK) {
            return $this->createErrorResponse('Product could not be added. User is not eligible.');
        }

        try {
            // Get product details
            $criteria = new Criteria([$productId]);
            $criteria->addAssociation('cover');
            $criteria->addAssociation('options.group');

            $product = $this->productRepository->search($criteria, $context->getContext())->first();

            if (!$product instanceof SalesChannelProductEntity && !$product instanceof ProductEntity) {
                return $this->createErrorResponse('Product not found.');
            }

            if (!$this->isValidProduct($product)) {
                return $this->createErrorResponse('Invalid or unavailable product.');
            }

            // Create line item for the product
            $lineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, 1);
            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);

            // Set price to 0
            $lineItem->setPriceDefinition(
                new \Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition(
                    0,
                    $context->buildTaxRules($product->getTaxId()),
                    1
                )
            );

            // Add to cart
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart->add($lineItem);
            $this->cartPersister->save($cart, $context);

            return $this->createSuccessResponse('Product added to loyalty cart successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error adding product to loyalty cart: ' . $e->getMessage(), [
                'email' => $email,
                'productId' => $productId,
                'exception' => $e
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Remove a product from the cart
     */
    public function removeProduct(string $email, string $productId, SalesChannelContext $context): array
    {
        if (empty($email) || empty($productId)) {
            return $this->createErrorResponse('Email and Product ID are required.');
        }

        try {
            // Notify loyalty API
            $apiResponse = $this->loyaltyEngageApiService->removeItem($email, $productId, 1);
            if ($apiResponse !== self::HTTP_OK) {
                return $this->createErrorResponse('Product could not be removed from loyalty system.');
            }

            // Remove from cart
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $lineItems = $cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
            
            foreach ($lineItems as $lineItem) {
                if ($lineItem->getReferencedId() === $productId) {
                    $cart->remove($lineItem->getId());
                    break;
                }
            }
            
            $this->cartPersister->save($cart, $context);

            return $this->createSuccessResponse('Product removed from loyalty cart successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error removing product from loyalty cart: ' . $e->getMessage(), [
                'email' => $email,
                'productId' => $productId,
                'exception' => $e
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Remove all products from the cart
     */
    public function removeAllProducts(string $email, SalesChannelContext $context): array
    {
        if (empty($email)) {
            return $this->createErrorResponse('Email is required.');
        }

        try {
            // Notify loyalty API
            $apiResponse = $this->loyaltyEngageApiService->removeAllItems($email);
            if ($apiResponse !== self::HTTP_OK) {
                return $this->createErrorResponse('Products could not be removed from loyalty system.');
            }

            // Clear cart
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $lineItems = $cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
            
            foreach ($lineItems as $lineItem) {
                $cart->remove($lineItem->getId());
            }
            
            $this->cartPersister->save($cart, $context);

            return $this->createSuccessResponse('All products removed from loyalty cart successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error removing all products from loyalty cart: ' . $e->getMessage(), [
                'email' => $email,
                'exception' => $e
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Claim a discount after adding a product to the loyalty cart
     */
    public function claimDiscountAfterAddToCart(string $email, string $productId, float $discount, SalesChannelContext $context): array
    {
        if (empty($email) || empty($productId)) {
            return $this->createErrorResponse('Email and Product ID are required.');
        }

        try {
            // Step 1: Add to Loyalty Engage
            $cartStatus = $this->loyaltyEngageApiService->addToCart($email, $productId);
            if ($cartStatus !== self::HTTP_OK) {
                return $this->createErrorResponse('Failed to add product to loyalty cart.');
            }

            // Step 2: Claim discount
            $discountResult = $this->loyaltyEngageApiService->claimDiscount($email, $discount);
            if (!$discountResult) {
                return $this->createErrorResponse('No discount code returned.');
            }

            $discountCode = $discountResult['discountCode'] ?? 'LOYALTY-' . strtoupper(bin2hex(random_bytes(4)));
            $discountAmount = $discountResult['discount'] ?? $discount;

            // Step 3: Ensure promotion exists
            $promotionId = $this->ensurePromotionExists($discountCode, $discountAmount, $context->getContext());

            // Step 4: Add product to cart
            $addResult = $this->addProduct($email, $productId, $context);
            if (!$addResult['success']) {
                return $addResult;
            }

            // Step 5: Apply promotion to cart
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart->addExtension('loyaltyPromotion', new \Shopware\Core\Framework\Struct\ArrayStruct(['code' => $discountCode]));
            $this->cartPersister->save($cart, $context);

            return $this->createSuccessResponse("Product added and discount code '{$discountCode}' applied.");
        } catch (\Exception $e) {
            $this->logger->error('Error claiming discount: ' . $e->getMessage(), [
                'email' => $email,
                'productId' => $productId,
                'discount' => $discount,
                'exception' => $e
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Ensure a promotion exists with the given code and discount
     */
    private function ensurePromotionExists(string $code, float $discountRate, Context $context): string
    {
        try {
            // Check if promotion with this code already exists
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $code));
            $existingPromotion = $this->promotionRepository->search($criteria, $context)->first();

            if ($existingPromotion) {
                return $existingPromotion->getId();
            }

            // Create a new promotion
            $promotionId = \Shopware\Core\Framework\Uuid\Uuid::randomHex();
            $discountId = \Shopware\Core\Framework\Uuid\Uuid::randomHex();
            $discountPercent = $discountRate * 100;

            $this->promotionRepository->create([
                [
                    'id' => $promotionId,
                    'name' => 'LoyaltyEngage Auto Promotion',
                    'active' => true,
                    'useCodes' => true,
                    'useIndividualCodes' => false,
                    'code' => $code,
                    'salesChannels' => [
                        ['salesChannelId' => $context->getSource()->getSalesChannelId(), 'priority' => 1]
                    ],
                    'discounts' => [
                        [
                            'id' => $discountId,
                            'scope' => 'cart',
                            'type' => 'percentage',
                            'value' => $discountPercent,
                            'considerAdvancedRules' => false
                        ]
                    ]
                ]
            ], $context);

            return $promotionId;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create promotion: ' . $e->getMessage(), [
                'code' => $code,
                'discountRate' => $discountRate,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Validates if a product is valid for loyalty cart
     */
    private function isValidProduct($product): bool
    {
        return $product->getActive() && $product->getAvailable();
    }

    /**
     * Create a success response
     */
    private function createSuccessResponse(string $message): array
    {
        return [
            'success' => true,
            'message' => $message
        ];
    }

    /**
     * Create an error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'message' => $message
        ];
    }
}
