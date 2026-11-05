<?php declare(strict_types=1);

$loader = require $argv[1];
$root = $argv[2] ?? dirname(__DIR__);
$loader->addPsr4('LoyaltyEngage\\', $root . '/src/');

use LoyaltyEngage\Service\LoyaltyFeedReader;
use LoyaltyEngage\Service\LoyaltyFeedUnavailable;
use LoyaltyEngage\Service\LoyaltyProductCatalog;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Psr\Log\NullLogger;

class FeedSmokeConfig extends SystemConfigService
{
    public array $values = [];
    public function __construct() {}
    public function get(string $key, ?string $salesChannelId = null) { return $this->values[$salesChannelId ?? 'global'][$key] ?? null; }
}
function expectFeed(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$configSource = new FeedSmokeConfig();
$configSource->values['global'] = [
    'LoyaltyEngage.config.shopProductSource' => 'loyalty_feed',
    'LoyaltyEngage.config.shopSourceFeedUrl' => 'https://app.loyaltyengage.tech/feed/v1/test/loyalty_shop_products.ndjson',
];
$config = new LoyaltyShopConfig($configSource);
$body = file_get_contents($root . '/tests/fixtures/loyalty-products.ndjson');
$calls = 0;
$status = 200;
$http = new MockHttpClient(static function ($method, $url, $options) use (&$body, &$calls, &$status) {
    $calls++;
    expectFeed($method === 'GET', 'Source is read only');
    expectFeed(($options['max_redirects'] ?? null) === 0, 'Redirects disabled');
    return new MockResponse($body, ['http_code' => $status]);
});
$reader = new LoyaltyFeedReader($config, $http, new ArrayAdapter(), new NullLogger());
$products = $reader->products('channel-a');
expectFeed(count($products) === 6 && $products['24-MB01']['coin_price'] === 800, 'NDJSON fixture mappings');
expectFeed($products['DISCOUNT_PER_10']['discount_percentage'] === 10 && $products['DISCOUNT_SKU_10']['discount_currency'] === 'EUR', 'Voucher percentage and amount');
expectFeed($reader->products('channel-a') === $products && $calls === 1, 'Repeated lookup uses cache');
$reader->products('channel-b');
expectFeed($calls === 2, 'Cache isolated by channel');
expectFeed(count($reader->parse(json_encode(array_values($products)))) === 6, 'JSON array accepted');
expectFeed(count($reader->parse(json_encode(['products' => array_values($products)]))) === 6, 'JSON product wrapper accepted');
$repository = (new ReflectionClass(EntityRepository::class))->newInstanceWithoutConstructor();
$catalog = new LoyaltyProductCatalog($repository, $repository, $config, new NullLogger(), $reader);
$product = new ProductEntity();
$product->setProductNumber('24-MB01');
$product->setName('Shopware product');
$product->setCustomFields(['le_shop_enabled' => false, 'le_shop_coin_price' => 1, 'le_shop_type' => 'discount_code']);
expectFeed($catalog->validationError($product, 'channel-a') === null, 'Local selection is irrelevant in feed mode');
expectFeed($catalog->fields($product, 'channel-a')['le_shop_coin_price'] === 800, 'Feed overrides local coin price');
$product->setProductNumber('NOT-IN-FEED');
expectFeed($catalog->validationError($product, 'channel-a') !== null, 'Unknown SKU cannot be redeemed');
$body = "{broken json}\n";
try { $reader->products('channel-a', true); throw new RuntimeException('Malformed feed was accepted'); }
catch (LoyaltyFeedUnavailable $exception) {}
try { $reader->products('channel-a'); throw new RuntimeException('Old snapshot was reused after failed refresh'); }
catch (LoyaltyFeedUnavailable $exception) {}
$body = '';
expectFeed($reader->products('channel-a', true) === [], 'Empty feed removes all rewards');
$status = 301;
try { $reader->products('channel-a', true); throw new RuntimeException('Redirect response accepted'); } catch (LoyaltyFeedUnavailable $exception) {}
$status = 200;
$body = '{"sku":"SKU","type":"physical","coin_price":0}';
try { $reader->parse($body); throw new RuntimeException('Zero price accepted'); } catch (UnexpectedValueException $exception) {}
$body = '{"sku":"SKU","type":"discount_code","coin_price":10,"discount_percentage":101}';
try { $reader->parse($body); throw new RuntimeException('Invalid percentage accepted'); } catch (UnexpectedValueException $exception) {}
$body = "{\"sku\":\"SKU\",\"type\":\"physical\",\"coin_price\":10}\n{\"sku\":\"SKU\",\"type\":\"physical\",\"coin_price\":20}";
try { $reader->parse($body); throw new RuntimeException('Conflicting SKU accepted'); } catch (UnexpectedValueException $exception) {}
$configSource->values['blocked']['LoyaltyEngage.config.shopSourceFeedUrl'] = 'http://127.0.0.1/private';
$before = $calls;
try { $reader->products('blocked'); throw new RuntimeException('Untrusted URL accepted'); } catch (LoyaltyFeedUnavailable $exception) {}
expectFeed($calls === $before, 'Untrusted URL never requested');
echo "Loyalty feed smoke checks passed\n";
