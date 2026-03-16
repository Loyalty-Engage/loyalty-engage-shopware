<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class PointsRedemptionService
{
    public const LOYALTY_DISCOUNT_LINE_ITEM_TYPE = 'loyalty_points_discount';
    public const LOYALTY_DISCOUNT_LINE_ITEM_KEY = 'loyalty-points-discount';

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var CartPersister
     */
    private $cartPersister;

    /**
     * @var EntityRepository
     */
    private $customerRepository;

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
     * @param EntityRepository $customerRepository
     * @param LoyaltyEngageApiService $loyaltyEngageApiService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CartService $cartService,
        CartPersister $cartPersister,
        EntityRepository $customerRepository,
        LoyaltyEngageApiService $loyaltyEngageApiService,
        LoggerInterface $logger
    ) {
        $this->cartService = $cartService;
        $this->cartPersister = $cartPersister;
        $this->customerRepository = $customerRepository;
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->logger = $logger;
    }

    /**
     * Redeem loyalty points for a discount in the cart
     *
     * @param string $email Customer email
     * @param int $points Number of points to redeem
     * @param SalesChannelContext $context
     * @return array Result with success status and message
     */
    public function redeemPointsForDiscount(string $email, int $points, SalesChannelContext $context): array
    {
        // Check if points redemption is enabled
        if (!$this->loyaltyEngageApiService->isPointsRedemptionEnabled()) {
            return $this->createErrorResponse('Points redemption is not enabled.');
        }

        // Get discount product SKU from config
        $discountProductSku = $this->loyaltyEngageApiService->getDiscountProductSku();
        if (empty($discountProductSku)) {
            return $this->createErrorResponse('Discount product SKU is not configured.');
        }

        // Validate minimum points
        $minPoints = $this->loyaltyEngageApiService->getMinPointsToRedeem();
        if ($points < $minPoints) {
            return $this->createErrorResponse("Minimum {$minPoints} points required to redeem.");
        }

        // Validate maximum points per order
        $maxPoints = $this->loyaltyEngageApiService->getMaxPointsPerOrder();
        if ($maxPoints > 0 && $points > $maxPoints) {
            return $this->createErrorResponse("Maximum {$maxPoints} points can be redeemed per order.");
        }

        // Get cart and calculate discount limits
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $cartTotal = $cart->getPrice()->getTotalPrice();

        // Calculate points to euro conversion
        $pointsPerEuro = $this->loyaltyEngageApiService->getPointsPerEuro();
        $discountAmount = $points / $pointsPerEuro;

        // Check maximum discount percentage
        $maxDiscountPercentage = $this->loyaltyEngageApiService->getMaxDiscountPercentage();
        if ($maxDiscountPercentage > 0) {
            $maxDiscountAmount = ($cartTotal * $maxDiscountPercentage) / 100;
            if ($discountAmount > $maxDiscountAmount) {
                $maxAllowedPoints = (int) ($maxDiscountAmount * $pointsPerEuro);
                return $this->createErrorResponse(
                    "Maximum discount is {$maxDiscountPercentage}% of cart total. You can redeem up to {$maxAllowedPoints} points (€{$maxDiscountAmount})."
                );
            }
        }

        // Ensure discount doesn't exceed cart total
        if ($discountAmount > $cartTotal) {
            $maxAllowedPoints = (int) ($cartTotal * $pointsPerEuro);
            return $this->createErrorResponse(
                "Discount cannot exceed cart total. You can redeem up to {$maxAllowedPoints} points (€{$cartTotal})."
            );
        }

        // Get customer's available points from custom fields
        $customerPoints = $this->getCustomerAvailablePoints($email, $context->getContext());
        if ($customerPoints === null) {
            return $this->createErrorResponse('Could not retrieve customer loyalty data.');
        }

        if ($points > $customerPoints) {
            return $this->createErrorResponse("Insufficient points. You have {$customerPoints} points available.");
        }

        try {
            // Calculate how many €1 discount products we need to buy
            // Each discount product = €1, so for €10 discount we need 10 products
            $numberOfProducts = (int) $discountAmount;

            if ($numberOfProducts < 1) {
                return $this->createErrorResponse('Points amount too low for a discount.');
            }

            // Buy discount code products from LoyaltyEngage API
            $apiResult = $this->loyaltyEngageApiService->buyMultipleDiscountCodeProducts(
                $email,
                $discountProductSku,
                $numberOfProducts
            );

            if (!$apiResult['success']) {
                $this->logger->error('Failed to buy discount code products from LoyaltyEngage', [
                    'email' => $email,
                    'points' => $points,
                    'numberOfProducts' => $numberOfProducts,
                    'apiResult' => $apiResult
                ]);

                // If some products were purchased, we need to handle partial success
                if ($apiResult['successCount'] > 0) {
                    $partialDiscount = $apiResult['successCount'];
                    $this->applyDiscountToCart($cart, $partialDiscount, $context);
                    
                    return [
                        'success' => true,
                        'partial' => true,
                        'message' => "Partial redemption: €{$partialDiscount} discount applied. Some points could not be redeemed.",
                        'discountAmount' => $partialDiscount,
                        'pointsRedeemed' => $apiResult['successCount'] * $pointsPerEuro,
                        'discountCodes' => $apiResult['discountCodes']
                    ];
                }

                return $this->createErrorResponse('Failed to redeem points. Please try again later.');
            }

            // Apply discount to cart
            $this->applyDiscountToCart($cart, $discountAmount, $context);

            $this->logger->info('Points redeemed successfully', [
                'email' => $email,
                'points' => $points,
                'discountAmount' => $discountAmount,
                'discountCodes' => $apiResult['discountCodes']
            ]);

            return [
                'success' => true,
                'message' => "Successfully redeemed {$points} points for €{$discountAmount} discount.",
                'discountAmount' => $discountAmount,
                'pointsRedeemed' => $points,
                'discountCodes' => $apiResult['discountCodes']
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error redeeming points for discount', [
                'email' => $email,
                'points' => $points,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->createErrorResponse('An error occurred while redeeming points: ' . $e->getMessage());
        }
    }

    /**
     * Remove loyalty points discount from cart
     *
     * @param SalesChannelContext $context
     * @return array Result with success status and message
     */
    public function removePointsDiscount(SalesChannelContext $context): array
    {
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            
            // Find and remove loyalty discount line item
            $lineItems = $cart->getLineItems();
            $removed = false;

            foreach ($lineItems as $lineItem) {
                if ($lineItem->getType() === self::LOYALTY_DISCOUNT_LINE_ITEM_TYPE) {
                    $cart->remove($lineItem->getId());
                    $removed = true;
                }
            }

            if ($removed) {
                $this->cartPersister->save($cart, $context);
                return $this->createSuccessResponse('Loyalty points discount removed from cart.');
            }

            return $this->createErrorResponse('No loyalty points discount found in cart.');

        } catch (\Exception $e) {
            $this->logger->error('Error removing points discount from cart', [
                'error' => $e->getMessage()
            ]);

            return $this->createErrorResponse('Failed to remove discount: ' . $e->getMessage());
        }
    }

    /**
     * Get redemption configuration and customer points info
     *
     * @param string $email Customer email
     * @param SalesChannelContext $context
     * @return array Configuration and customer data
     */
    public function getRedemptionInfo(string $email, SalesChannelContext $context): array
    {
        $isEnabled = $this->loyaltyEngageApiService->isPointsRedemptionEnabled();
        
        if (!$isEnabled) {
            return [
                'enabled' => false,
                'message' => 'Points redemption is not enabled.'
            ];
        }

        $customerPoints = $this->getCustomerAvailablePoints($email, $context->getContext());
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $cartTotal = $cart->getPrice()->getTotalPrice();

        $pointsPerEuro = $this->loyaltyEngageApiService->getPointsPerEuro();
        $minPoints = $this->loyaltyEngageApiService->getMinPointsToRedeem();
        $maxPoints = $this->loyaltyEngageApiService->getMaxPointsPerOrder();
        $maxDiscountPercentage = $this->loyaltyEngageApiService->getMaxDiscountPercentage();

        // Calculate maximum redeemable points based on cart total and limits
        $maxRedeemableByCart = (int) ($cartTotal * $pointsPerEuro);
        
        if ($maxDiscountPercentage > 0) {
            $maxByPercentage = (int) (($cartTotal * $maxDiscountPercentage / 100) * $pointsPerEuro);
            $maxRedeemableByCart = min($maxRedeemableByCart, $maxByPercentage);
        }

        if ($maxPoints > 0) {
            $maxRedeemableByCart = min($maxRedeemableByCart, $maxPoints);
        }

        $maxRedeemable = min($customerPoints ?? 0, $maxRedeemableByCart);

        // Check if there's already a loyalty discount in cart
        $existingDiscount = $this->getExistingDiscountFromCart($cart);

        return [
            'enabled' => true,
            'customerPoints' => $customerPoints ?? 0,
            'pointsPerEuro' => $pointsPerEuro,
            'minPointsToRedeem' => $minPoints,
            'maxPointsPerOrder' => $maxPoints,
            'maxDiscountPercentage' => $maxDiscountPercentage,
            'cartTotal' => $cartTotal,
            'maxRedeemablePoints' => $maxRedeemable,
            'maxDiscountAmount' => $maxRedeemable / $pointsPerEuro,
            'existingDiscount' => $existingDiscount
        ];
    }

    /**
     * Apply discount line item to cart
     *
     * @param Cart $cart
     * @param float $discountAmount
     * @param SalesChannelContext $context
     */
    private function applyDiscountToCart(Cart $cart, float $discountAmount, SalesChannelContext $context): void
    {
        // Remove any existing loyalty discount first
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() === self::LOYALTY_DISCOUNT_LINE_ITEM_TYPE) {
                $cart->remove($lineItem->getId());
            }
        }

        // Create discount line item
        $discountLineItem = new LineItem(
            self::LOYALTY_DISCOUNT_LINE_ITEM_KEY,
            self::LOYALTY_DISCOUNT_LINE_ITEM_TYPE,
            null,
            1
        );

        $discountLineItem->setLabel('Loyalty Points Discount');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(true);

        // Set negative price for discount
        $discountLineItem->setPriceDefinition(
            new AbsolutePriceDefinition(-$discountAmount)
        );

        // Add custom payload for tracking
        $discountLineItem->setPayload([
            'discountType' => 'loyalty_points',
            'discountAmount' => $discountAmount,
            'appliedAt' => (new \DateTime())->format('c')
        ]);

        $cart->add($discountLineItem);
        $this->cartPersister->save($cart, $context);
    }

    /**
     * Get customer's available loyalty points from custom fields
     *
     * @param string $email
     * @param Context $context
     * @return int|null
     */
    private function getCustomerAvailablePoints(string $email, Context $context): ?int
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $email));
            
            $customer = $this->customerRepository->search($criteria, $context)->first();

            if (!$customer) {
                $this->logger->warning('Customer not found for points lookup', ['email' => $email]);
                return null;
            }

            $customFields = $customer->getCustomFields() ?? [];
            
            // Use le_available_coins as the redeemable currency
            return (int) ($customFields['le_available_coins'] ?? 0);

        } catch (\Exception $e) {
            $this->logger->error('Error getting customer points', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get existing loyalty discount from cart if any
     *
     * @param Cart $cart
     * @return array|null
     */
    private function getExistingDiscountFromCart(Cart $cart): ?array
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() === self::LOYALTY_DISCOUNT_LINE_ITEM_TYPE) {
                $payload = $lineItem->getPayload();
                return [
                    'amount' => abs($lineItem->getPrice()?->getTotalPrice() ?? 0),
                    'discountAmount' => $payload['discountAmount'] ?? 0,
                    'appliedAt' => $payload['appliedAt'] ?? null
                ];
            }
        }

        return null;
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
