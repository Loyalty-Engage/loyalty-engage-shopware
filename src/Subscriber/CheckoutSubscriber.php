<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    private const LOYALTY_DISCOUNT_LINE_ITEM_TYPE = 'loyalty_points_discount';

    /**
     * @var LoyaltyEngageApiService
     */
    private $loyaltyEngageApiService;

    /**
     * @var EntityRepository
     */
    private $customerRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $customerRepository,
        LoggerInterface $logger
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCheckoutCartPageLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmPageLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onOffcanvasCartPageLoaded',
        ];
    }

    public function onCheckoutCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $this->addLoyaltyDataToPage($event);
    }

    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->addLoyaltyDataToPage($event);
    }

    public function onOffcanvasCartPageLoaded(OffcanvasCartPageLoadedEvent $event): void
    {
        $this->addLoyaltyDataToPage($event);
    }

    /**
     * Add loyalty points data to the checkout page
     */
    private function addLoyaltyDataToPage($event): void
    {
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();

        // Only show for logged-in customers
        if (!$customer) {
            return;
        }

        // Check if points redemption is enabled
        if (!$this->loyaltyEngageApiService->isPointsRedemptionEnabled()) {
            return;
        }

        try {
            // Get customer loyalty data
            $criteria = new Criteria([$customer->getId()]);
            /** @var CustomerEntity|null $customerEntity */
            $customerEntity = $this->customerRepository->search($criteria, $context->getContext())->first();

            if (!$customerEntity) {
                return;
            }

            $customFields = $customerEntity->getCustomFields() ?? [];
            $availableCoins = (int) ($customFields['le_available_coins'] ?? 0);
            $reservedCoins = (int) ($customFields['le_reserved_coins'] ?? 0);
            $currentTier = $customFields['le_current_tier'] ?? null;
            $totalPoints = (int) ($customFields['le_points'] ?? 0);

            // Get configuration
            $pointsPerEuro = $this->loyaltyEngageApiService->getPointsPerEuro();
            $minPoints = $this->loyaltyEngageApiService->getMinPointsToRedeem();
            $maxPoints = $this->loyaltyEngageApiService->getMaxPointsPerOrder();
            $maxDiscountPercentage = $this->loyaltyEngageApiService->getMaxDiscountPercentage();

            // Get cart info
            $cart = $event->getPage()->getCart();
            $cartTotal = $cart->getPrice()->getTotalPrice();

            // Calculate max redeemable points
            $maxRedeemableByCart = (int) ($cartTotal * $pointsPerEuro);
            
            if ($maxDiscountPercentage > 0) {
                $maxByPercentage = (int) (($cartTotal * $maxDiscountPercentage / 100) * $pointsPerEuro);
                $maxRedeemableByCart = min($maxRedeemableByCart, $maxByPercentage);
            }

            if ($maxPoints > 0) {
                $maxRedeemableByCart = min($maxRedeemableByCart, $maxPoints);
            }

            $maxRedeemable = min($availableCoins, $maxRedeemableByCart);

            // Check for existing loyalty discount in cart
            $existingDiscount = $this->getExistingDiscountFromCart($cart);

            // Build loyalty data array
            $loyaltyData = [
                'enabled' => true,
                'customerEmail' => $customer->getEmail(),
                'availableCoins' => $availableCoins,
                'totalPoints' => $totalPoints,
                'currentTier' => $currentTier,
                'pointsPerEuro' => $pointsPerEuro,
                'minPointsToRedeem' => $minPoints,
                'maxPointsPerOrder' => $maxPoints,
                'maxDiscountPercentage' => $maxDiscountPercentage,
                'cartTotal' => $cartTotal,
                'maxRedeemablePoints' => $maxRedeemable,
                'maxDiscountAmount' => $maxRedeemable / $pointsPerEuro,
                'reservedCoins' => $reservedCoins,
                'existingDiscount' => $existingDiscount,
                'canRedeem' => $availableCoins >= $minPoints && $maxRedeemable > 0,
            ];

            // Add to page extensions
            $event->getPage()->addExtension('loyaltyPointsRedemption', new \Shopware\Core\Framework\Struct\ArrayStruct($loyaltyData));

        } catch (\Exception $e) {
            $this->logger->error('Error loading loyalty data for checkout', [
                'customerId' => $customer->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get existing loyalty discount from cart if any
     */
    private function getExistingDiscountFromCart(Cart $cart): ?array
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() === self::LOYALTY_DISCOUNT_LINE_ITEM_TYPE) {
                $payload = $lineItem->getPayload();
                $price = $lineItem->getPrice();
                return [
                    'amount' => abs($price ? $price->getTotalPrice() : 0),
                    'discountAmount' => $payload['discountAmount'] ?? 0,
                    'appliedAt' => $payload['appliedAt'] ?? null
                ];
            }
        }

        return null;
    }
}
