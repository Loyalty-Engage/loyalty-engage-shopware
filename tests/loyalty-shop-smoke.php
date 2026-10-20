<?php declare(strict_types=1);

// php tests/loyalty-shop-smoke.php /path/to/shopware/vendor/autoload.php [plugin-root]
$loader = require $argv[1];
$loader->addPsr4('LoyaltyEngage\\', ($argv[2] ?? dirname(__DIR__)) . '/src/');

use LoyaltyEngage\Service\LoyaltyProductCatalog;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use LoyaltyEngage\Service\LoyaltyShopEligibility;
use LoyaltyEngage\Service\RewardAuthorization;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\NullLogger;

class ShopTestConfig extends SystemConfigService
{
    public array $values = [];
    public function __construct() {}
    public function get(string $key, ?string $salesChannelId = null) { return $this->values[$salesChannelId ?? 'global'][$key] ?? null; }
}

class ShopTestContext extends SalesChannelContext
{
    public function __construct(public ?CustomerEntity $testCustomer, public string $channel = 'test-channel') {}
    public function getSalesChannelId(): string { return $this->channel; }
    public function getCustomer(): ?CustomerEntity { return $this->testCustomer; }
    public function getToken(): string { return 'test-cart'; }
    public function getLanguageId(): string { return Defaults::LANGUAGE_SYSTEM; }
    public function getCurrencyId(): string { return Defaults::CURRENCY; }
    public function getCurrency(): CurrencyEntity { $currency = new CurrencyEntity(); $currency->setFactor(1); return $currency; }
}

function verify(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$source = new ShopTestConfig();
$source->values['global'] = [
    'LoyaltyEngage.config.shopEnabled' => true,
    'LoyaltyEngage.config.shopMinimumOrder' => 35,
    'LoyaltyEngage.config.shopMaximumRewards' => 1,
    'LoyaltyEngage.config.shopTiers' => "13|Brons\n1|Gold",
];
$source->values['disabled']['LoyaltyEngage.config.shopEnabled'] = false;
$config = new LoyaltyShopConfig($source);
verify($config->enabled('test-channel') && !$config->enabled('disabled'), 'Channel fallback and explicit false');
verify(array_keys($config->tiers('test-channel')) === [13, 1], 'Tier order does not depend on numeric IDs');
$repository = (new ReflectionClass(EntityRepository::class))->newInstanceWithoutConstructor();
$catalog = new LoyaltyProductCatalog($repository, $repository, $config, new NullLogger());
(new ReflectionProperty($catalog, 'locales'))->setValue($catalog, [Defaults::LANGUAGE_SYSTEM => 'en-GB']);
$product = new ProductEntity();
$product->setId(str_repeat('a', 32));
$product->setProductNumber('REWARD_1');
$product->setName('Reward');
$product->setTranslated(['name' => 'Reward', 'description' => '<b>Reward description</b>']);
$product->setCustomFields(['le_shop_enabled' => true, 'le_shop_coin_price' => 75, 'le_shop_type' => 'physical', 'le_shop_tier_id' => 1]);
$product->setPrice(new PriceCollection([new Price(Defaults::CURRENCY, 4, 5, true)]));
$product->setMinPurchase(1);
$product->setPurchaseSteps(1);
$product->setIsCloseout(true);
$product->setAvailableStock(0);
$customer = new CustomerEntity();
$customer->setId(str_repeat('b', 32));
$customer->setGuest(false);
$customer->setCustomFields(['le_current_tier' => 'Gold']);
$context = new ShopTestContext($customer);
verify($catalog->validationError($product, 'test-channel') === null, 'Stock must not exclude feed products');
$row = $catalog->row($product, $context);
verify($row['id'] === 'REWARD_1' && $row['coin_price'] === '75' && $row['price'] === '5.00', 'Feed mappings');
verify($row['language'] === 'en_GB' && $row['short_description'] === 'Reward description' && $row['minimum_required_tier_id'] === null, 'Feed formatting and optional tiers');
$product->setProductNumber('NOT VALID!');
verify($catalog->validationError($product, 'test-channel') !== null, 'Invalid SKU excluded');
$product->setProductNumber('REWARD_1');
$fields = $product->getCustomFields();
$fields['le_shop_coin_price'] = 0;
$product->setCustomFields($fields);
verify($catalog->validationError($product, 'test-channel') !== null, 'Zero coin price excluded');
$fields['le_shop_coin_price'] = 75;
$product->setCustomFields($fields);
$eligibility = new LoyaltyShopEligibility($config, $catalog);
verify($eligibility->productError($product, $context) !== null, 'Out-of-stock closeout cannot be redeemed');
$product->setAvailableStock(10);
verify($eligibility->productError($product, $context) === null, 'In-stock reward');
$source->values['global']['LoyaltyEngage.config.shopTiersEnabled'] = true;
verify($eligibility->productError($product, $context) === null, 'Configured customer tier accepted');
$customer->setCustomFields(['le_current_tier' => 'Brons']);
verify($eligibility->productError($product, $context) !== null, 'Insufficient tier blocked');
$cart = new Cart('test-cart');
verify($eligibility->cartError($cart, $context) !== null, 'Minimum regular subtotal');
$regular = new LineItem('regular', 'product', str_repeat('c', 32));
$regular->setPrice(new CalculatedPrice(35, 35, new CalculatedTaxCollection(), new TaxRuleCollection()));
$cart->add($regular);
verify($eligibility->cartError($cart, $context, true) === null, 'Minimum reached without shipping');
$reward = new LineItem('reward', 'product', $product->getId());
$reward->setPayloadValue('loyaltyFreeProduct', true);
$cart->add($reward);
verify($eligibility->cartError($cart, $context, true) !== null, 'Maximum reward quantity');
$source->values['global']['LoyaltyEngage.config.shopAllowPromotions'] = false;
$cart->add(new LineItem('discount', 'promotion', 'CODE'));
verify($eligibility->cartError($cart, $context) !== null, 'Promotion combination blocked');
$connection = (new ReflectionClass(Doctrine\DBAL\Connection::class))->newInstanceWithoutConstructor();
$authorization = new RewardAuthorization('test-secret', $connection);
$signature = $authorization->sign($reward, $context);
$reward->setPayloadValue('productNumber', 'OTHER_SKU');
verify($signature !== $authorization->sign($reward, $context), 'Authorization binds SKU');
verify(!$authorization->valid($reward, $context), 'Unsigned reward rejected before database lookup');
echo "Loyalty shop smoke checks passed\n";
