<?php declare(strict_types=1);

namespace LoyaltyEngage\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use LoyaltyEngage\Service\PointsRedemptionService;

/**
 * Cart processor to handle loyalty points discount line items
 * This ensures the discount is properly calculated and persisted
 */
class LoyaltyDiscountCollector implements CartDataCollectorInterface, CartProcessorInterface
{
    /**
     * @var AbsolutePriceCalculator
     */
    private $calculator;

    public function __construct(AbsolutePriceCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // Nothing to collect - the discount is already in the cart
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // Find loyalty discount line items and calculate their prices
        foreach ($original->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== PointsRedemptionService::LOYALTY_DISCOUNT_LINE_ITEM_TYPE) {
                continue;
            }

            // Get the price definition
            $priceDefinition = $lineItem->getPriceDefinition();
            
            if (!$priceDefinition instanceof AbsolutePriceDefinition) {
                continue;
            }

            // Calculate the price
            $price = $this->calculator->calculate(
                $priceDefinition->getPrice(),
                $toCalculate->getLineItems()->getPrices(),
                $context
            );

            // Set the calculated price
            $lineItem->setPrice($price);

            // Add to the calculated cart
            $toCalculate->add($lineItem);
        }
    }
}
