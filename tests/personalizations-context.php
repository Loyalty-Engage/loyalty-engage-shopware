<?php declare(strict_types=1);

$loader = require $argv[1];
$loader->addPsr4('LoyaltyEngage\\', dirname(__DIR__) . '/src/');

use LoyaltyEngage\Controller\Storefront\PersonalizationController;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use LoyaltyEngage\Service\PersonalizationConfig;
use LoyaltyEngage\Service\PersonalizationContext;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

function contextCheck(bool $value, string $label): void { if (!$value) { throw new RuntimeException($label); } }
function contextPrice(float $unit, int $quantity = 1): CalculatedPrice
{
    return new CalculatedPrice($unit, $unit * $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity);
}
$configSource = new class extends SystemConfigService {
    public array $values = [];
    public function __construct() {}
    public function get(string $key, ?string $salesChannelId = null) { return $this->values[$key] ?? null; }
};
$rule = ['key' => 'test', 'public_id' => 'bp-12', 'enabled' => true, 'placement' => 'manual',
    'page_scope' => 'product', 'personalized' => true, 'use_cart_value' => true, 'pages' => []];
$configSource->values = [
    'LoyaltyEngage.config.personalizationTenant' => '804bd81b-a77e-42ee-a70c-53e62eadf4ae',
    'LoyaltyEngage.config.personalizationRules' => [$rule, array_replace($rule, ['key' => 'anonymous', 'personalized' => false, 'use_cart_value' => false])],
    'LoyaltyEngage.config.personalizationCurrencyId' => str_repeat('c', 32),
];
$settings = new LoyaltyShopConfig($configSource);
$context = new class extends SalesChannelContext {
    public ?CustomerEntity $testCustomer = null;
    public function __construct() {}
    public function getSalesChannelId(): string { return 'test-channel'; }
    public function getContext(): Context { return Context::createDefaultContext(); }
    public function getCustomer(): ?CustomerEntity { return $this->testCustomer; }
    public function getToken(): string { return 'test-cart'; }
    public function getCurrency(): CurrencyEntity { $c = new CurrencyEntity(); $c->setFactor(1.2); return $c; }
};
$currencies = new class extends EntityRepository {
    public function __construct() {}
    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        $currency = new CurrencyEntity();
        $currency->setId(str_repeat('c', 32));
        $currency->setFactor(1);
        return new EntitySearchResult('currency', 1, new EntityCollection([$currency]), null, $criteria, $context);
    }
};
$products = new class extends SalesChannelRepository {
    public function __construct() {}
    public function search(Criteria $criteria, SalesChannelContext $context): EntitySearchResult
    {
        $product = new SalesChannelProductEntity();
        $product->setId(str_repeat('a', 32));
        $product->setActive(true);
        $product->setCalculatedPrice(contextPrice(120));
        $product->setCalculatedPrices(new PriceCollection([contextPrice(120, 4), contextPrice(96, 5)]));
        return new EntitySearchResult('product', 1, new EntityCollection([$product]), null, $criteria, $context->getContext());
    }
};
$carts = new class extends CartService {
    public function __construct() {}
    public function getCart(string $token, SalesChannelContext $context, bool $caching = true, bool $taxed = false): Cart
    {
        $cart = new Cart($token);
        foreach ([['regular', 'product', 60.0, 2], ['discount', 'promotion', -24.0, 1], ['shipping', 'delivery', 12.0, 1]] as [$id, $type, $price, $quantity]) {
            $line = new LineItem($id, $type, null, $quantity);
            $line->setPrice(contextPrice($price, $quantity));
            $cart->add($line);
        }
        return $cart;
    }
};
$service = new PersonalizationContext(new PersonalizationConfig($settings), $settings, $carts, $products, $currencies);
$guest = $service->load($context, ['test', 'anonymous', 'unknown'], 'product', str_repeat('a', 32), 1);
contextCheck((array) $guest['test'] === ['cartValue' => 100.0], 'Guest PDP unit price converted, no identifier');
contextCheck((array) $guest['anonymous'] === [] && !isset($guest['unknown']), 'Only requested authorized rule context');
$tier = $service->load($context, ['test'], 'product', str_repeat('a', 32), 6);
contextCheck($tier['test']->cartValue === 80.0, 'Selected quantity price tier');
$cart = $service->load($context, ['test'], 'cart', '', 1);
contextCheck($cart['test']->cartValue === 80.0, 'Product value after promotion, shipping excluded, currency converted');
$checkout = $service->load($context, ['test'], 'checkout', '', 1);
contextCheck($checkout['test']->cartValue === $cart['test']->cartValue, 'Checkout agrees with cart');
$customer = new CustomerEntity();
$customer->setEmail(' Test@Example.com ');
$customer->setGuest(false);
$context->testCustomer = $customer;
$loggedIn = $service->load($context, ['test', 'anonymous'], 'pages', '', 1);
contextCheck((array) $loggedIn['test'] === ['identifier' => hash('sha256', 'test@example.com')], 'Logged-in customer only, unsupported price scope omitted');
contextCheck((array) $loggedIn['anonymous'] === [], 'Anonymous rule never receives identifier');
$customer->setGuest(true);
contextCheck(!isset($service->load($context, ['test'], 'pages', '', 1)['test']->identifier), 'Guest checkout account excluded');
$configSource->values['LoyaltyEngage.config.personalizationCurrencyId'] = null;
contextCheck(!isset($service->load($context, ['test'], 'cart', '', 1)['test']->cartValue), 'No organization currency means no cartValue');
$response = (new PersonalizationController($service))->context(new Request(['keys' => 'test', 'scope' => 'pages']), $context);
contextCheck($response->headers->get('Cache-Control') === 'max-age=0, no-store, private', 'Session endpoint cannot be shared cached');
contextCheck($response->headers->get('Vary') === 'Cookie', 'Cookie varies response');
echo "Personalization product, tier, cart, checkout, currency and session context checks passed\n";
