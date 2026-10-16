<?php declare(strict_types=1);

namespace LoyaltyEngage\Cart;

use LoyaltyEngage\Service\LoyaltyProductCatalog;
use LoyaltyEngage\Service\LoyaltyShopEligibility;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use LoyaltyEngage\Service\RewardAuthorization;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class LoyaltyShopCartValidator implements CartValidatorInterface
{
    public function __construct(private readonly LoyaltyProductCatalog $catalog, private readonly LoyaltyShopEligibility $eligibility, private readonly LoyaltyShopConfig $config, private readonly RewardAuthorization $authorization) {}

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        foreach ($cart->getLineItems()->getFlat() as $item) {
            if ($item->getPayloadValue('loyaltyFreeProduct') === true
                && ($item->getPayloadValue('leManagedReward') === true || $this->config->enabled($context->getSalesChannelId()))
                && !$this->authorization->valid($item, $context)) {
                $errors->add(new GenericCartError('le-authorization-' . $item->getId(), 'loyaltyShopBlocked', ['reason' => 'Deze puntenbeloning heeft geen geldige reservering. Verwijder de beloning en voeg deze opnieuw toe.'], 20, true, false, true));
            }
            if ($item->getType() === 'product' && $item->getPayloadValue('loyaltyFreeProduct') !== true && $this->config->enabled($context->getSalesChannelId())) {
                $product = $this->catalog->find((string) $item->getPayloadValue('productNumber'), $context);
                $fields = $product ? $this->catalog->fields($product) : [];
                if (($fields['le_shop_regular_purchase'] ?? true) === false) {
                    $errors->add(new GenericCartError('le-regular-' . $item->getId(), 'loyaltyShopBlocked', ['reason' => 'Dit product kan uitsluitend als puntenbeloning worden besteld.'], 20, true, false, true));
                }
            }
            if ($item->getPayloadValue('leManagedReward') !== true) { continue; }
            $product = $this->catalog->find((string) $item->getPayloadValue('productNumber'), $context);
            $reason = $this->eligibility->cartError($cart, $context);
            $reason ??= $product ? $this->eligibility->productError($product, $context) : 'Deze loyaltybeloning is niet meer beschikbaar.';
            if ($item->getQuantity() !== 1) { $reason = 'Loyaltybeloningen moeten per stuk worden toegevoegd.'; }
            if ($reason) {
                $errors->add(new GenericCartError('le-reward-' . $item->getId(), 'loyaltyShopBlocked', ['reason' => $reason], 20, true, false, true));
            }
        }
    }
}
