<?php declare(strict_types=1);

namespace LoyaltyEngage\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Cart processor that forces loyalty free products to €0.
 *
 * Shopware's default product processor overwrites any manually set price
 * on PRODUCT_LINE_ITEM_TYPE items by fetching the price from the product
 * repository. This processor runs AFTER the default product processor
 * (lower priority number = runs later) and resets the price to €0 for
 * any line item that has the 'loyaltyFreeProduct' payload flag set to true.
 */
class LoyaltyFreeProductProcessor implements CartProcessorInterface
{
    private QuantityPriceCalculator $calculator;

    public function __construct(QuantityPriceCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems() as $lineItem) {
            if (!$this->isLoyaltyFreeProduct($lineItem)) {
                continue;
            }

            // Build a zero-price definition using the existing tax rules
            // (tax rules are already set by the standard product processor)
            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            $zeroPriceDefinition = new QuantityPriceDefinition(
                0.0,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $zeroPrice = $this->calculator->calculate($zeroPriceDefinition, $context);
            $lineItem->setPrice($zeroPrice);
        }
    }

    /**
     * Check whether a line item is a loyalty free product.
     */
    private function isLoyaltyFreeProduct(LineItem $lineItem): bool
    {
        $payload = $lineItem->getPayload();

        return isset($payload['loyaltyFreeProduct']) && $payload['loyaltyFreeProduct'] === true;
    }
}
