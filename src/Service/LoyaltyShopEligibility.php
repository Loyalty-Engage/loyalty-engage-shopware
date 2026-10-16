<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class LoyaltyShopEligibility
{
    public function __construct(private readonly LoyaltyShopConfig $config, private readonly LoyaltyProductCatalog $catalog) {}

    public function cartError(Cart $cart, SalesChannelContext $context, bool $adding = false): ?string
    {
        $channel = $context->getSalesChannelId();
        if (!$this->config->enabled($channel)) { return 'De loyaltywinkel is uitgeschakeld.'; }
        if (!$context->getCustomer() || $context->getCustomer()->getGuest()) { return 'Log in om punten in te wisselen.'; }
        $total = 0.0;
        $count = 0;
        foreach ($cart->getLineItems()->getFlat() as $item) {
            if ($item->getPayloadValue('loyaltyFreeProduct') === true) {
                $count += $item->getQuantity();
            } elseif ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $total += $item->getPrice()?->getTotalPrice() ?? 0;
            } elseif (!$this->config->get('shopAllowPromotions', $channel, true) && in_array($item->getType(), ['promotion', 'loyalty_points_discount'], true)) {
                return 'Puntenbeloningen kunnen niet worden gecombineerd met kortingscodes.';
            }
        }
        if ($total + 0.00001 < (float) $this->config->get('shopMinimumOrder', $channel, 0)) {
            return sprintf('Voeg minimaal %.2f aan reguliere producten toe om punten in te wisselen.', $this->config->get('shopMinimumOrder', $channel, 0));
        }
        $maximum = (int) $this->config->get('shopMaximumRewards', $channel, 0);
        if ($maximum > 0 && $count + ($adding ? 1 : 0) > $maximum) {
            return sprintf('U kunt maximaal %d loyaltybeloningen per bestelling kiezen.', $maximum);
        }
        return null;
    }

    public function productError(ProductEntity $product, SalesChannelContext $context): ?string
    {
        $error = $this->catalog->validationError($product, $context->getSalesChannelId());
        if ($error) { return $error; }
        $fields = $this->catalog->fields($product);
        if (($fields['le_shop_type'] ?? 'physical') === 'physical') {
            if (($product->getMinPurchase() ?? 1) !== 1 || ($product->getPurchaseSteps() ?? 1) !== 1) {
                return 'Deze beloning moet per stuk bestelbaar zijn.';
            }
            if ($product->getIsCloseout() && $product->getAvailableStock() < 1) {
                return 'Deze beloning is niet op voorraad.';
            }
        }
        if ($this->config->get('shopTiersEnabled', $context->getSalesChannelId(), false)) {
            $tiers = $this->config->tiers($context->getSalesChannelId());
            $customerTier = ($context->getCustomer()?->getCustomFields() ?? [])['le_current_tier'] ?? null;
            $customerRank = false;
            foreach ($tiers as $id => $label) {
                if ((string) $customerTier === (string) $id || $customerTier === $label) {
                    $customerRank = array_search($id, array_keys($tiers), true);
                    break;
                }
            }
            $minimumRank = array_search((int) $fields['le_shop_tier_id'], array_keys($tiers), true);
            if ($customerRank === false || $minimumRank === false || $customerRank < $minimumRank) {
                return 'Deze beloning is beschikbaar vanaf een hoger loyaltytier.';
            }
        }
        return null;
    }
}
