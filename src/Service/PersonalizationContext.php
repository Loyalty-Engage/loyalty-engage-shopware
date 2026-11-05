<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class PersonalizationContext
{
    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly LoyaltyShopConfig $settings,
        private readonly CartService $cartService,
        private readonly SalesChannelRepository $products,
        private readonly EntityRepository $currencies
    ) {}

    public static function identifier(?string $email, bool $authenticated): ?string
    {
        return $authenticated && $email !== null && trim($email) !== ''
            ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : null;
    }

    public static function convert(float $amount, float $sourceFactor, float $targetFactor): ?float
    {
        if (!is_finite($amount) || !is_finite($sourceFactor) || !is_finite($targetFactor) || $sourceFactor <= 0 || $targetFactor <= 0) {
            return null;
        }
        $converted = max(0, $amount) / $sourceFactor * $targetFactor;
        return is_finite($converted) ? round($converted, 2) : null;
    }

    public function load(SalesChannelContext $context, array $keys, string $scope, string $productId, int $quantity): array
    {
        $rules = array_filter($this->config->storefront($context->getSalesChannelId())['rules'],
            static fn (array $rule): bool => in_array($rule['key'], $keys, true));
        $customer = $context->getCustomer();
        $identifier = self::identifier($customer?->getEmail(), $customer !== null && !$customer->getGuest());
        $needsValue = count(array_filter($rules, static fn (array $rule): bool => $rule['use_cart_value'])) > 0;
        $value = $needsValue ? $this->value($context, $scope, $productId, $quantity) : null;
        $result = [];
        foreach ($rules as $rule) {
            $data = [];
            if ($rule['personalized'] && $identifier !== null) { $data['identifier'] = $identifier; }
            if ($rule['use_cart_value'] && $value !== null) { $data['cartValue'] = $value; }
            $result[$rule['key']] = (object) $data;
        }
        return $result;
    }

    private function value(SalesChannelContext $context, string $scope, string $productId, int $quantity): ?float
    {
        $currencyId = $this->settings->get('personalizationCurrencyId', $context->getSalesChannelId());
        if (!is_string($currencyId) || !preg_match('/^[a-f0-9]{32}$/iD', $currencyId)) { return null; }
        $currency = $this->currencies->search(new Criteria([$currencyId]), $context->getContext())->first();
        if ($currency === null) { return null; }
        if ($scope === 'product') {
            if (!preg_match('/^[a-f0-9]{32}$/iD', $productId)) { return null; }
            $product = $this->products->search(new Criteria([$productId]), $context)->first();
            if ($product === null || !$product->getActive()) { return null; }
            $price = $product->getCalculatedPrice();
            // Shopware stores tier upper bounds; the final entry is the open-ended tier.
            foreach ($product->getCalculatedPrices() as $tier) {
                $price = $tier;
                if ($quantity <= $tier->getQuantity()) { break; }
            }
            $amount = $price->getUnitPrice();
        } elseif (in_array($scope, ['cart', 'checkout'], true)) {
            $amount = 0.0;
            $discount = 0.0;
            foreach ($this->cartService->getCart($context->getToken(), $context)->getLineItems() as $line) {
                if ($line->getType() === 'product') {
                    $amount += ($line->getPrice()?->getUnitPrice() ?? 0) * $line->getQuantity();
                } elseif ($line->getType() === 'promotion') {
                    $discount += abs($line->getPrice()?->getTotalPrice() ?? 0);
                }
            }
            // Match PurchaseSubscriber: product value after promotions, no shipping, existing tax basis.
            $amount = max(0, $amount - $discount);
        } else {
            return null;
        }
        return self::convert($amount, $context->getCurrency()->getFactor(), $currency->getFactor());
    }
}
