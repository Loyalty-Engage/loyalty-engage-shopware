<?php declare(strict_types=1);

// Local test only: php tests/loyalty-shop-integration.php /var/www/html SALES_CHANNEL_ID
// Uses real Shopware carts and a mocked HTTP client. Never contacts Loyalty Engage.
$root = $argv[1];
$channelId = $argv[2];
$loader = require $root . '/vendor/autoload.php';
if (is_file($root . '/.env')) { (new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env'); }
if (!in_array(getenv('APP_URL'), ['http://shopware.test:8081', 'http://localhost:8082'], true)) {
    throw new RuntimeException('Disposable local environment required.');
}
$_SERVER['PROJECT_ROOT'] = $root;
$plugins = new Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader($loader, null, Shopware\Core\Kernel::getConnection());
if (class_exists(Shopware\Core\Framework\Adapter\Kernel\KernelFactory::class)) {
    $kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create(environment: 'prod', debug: false, classLoader: $loader, pluginLoader: $plugins);
} else {
    $http = new Shopware\Core\HttpKernel('prod', false, $loader);
    $http->setPluginLoader($plugins);
    $kernel = $http->getKernel();
}
$kernel->boot();
$container = $kernel->getContainer();
$db = Shopware\Core\Kernel::getConnection();
$db->beginTransaction();
register_shutdown_function(static function () use ($db): void { if ($db->isTransactionActive()) { $db->rollBack(); } });
$settings = $container->get(Shopware\Core\System\SystemConfig\SystemConfigService::class);
$previousSource = $settings->get('LoyaltyEngage.config.shopProductSource', $channelId);
$settings->set('LoyaltyEngage.config.shopProductSource', 'shopware', $channelId);
register_shutdown_function(static function () use ($settings, $previousSource, $channelId): void {
    $settings->set('LoyaltyEngage.config.shopProductSource', $previousSource, $channelId);
});
function dependency(object $object, string $name) { return (new ReflectionProperty($object, $name))->getValue($object); }
function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$controller = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyShopController::class);
$managed = dependency($controller, 'rewards');
$catalogController = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyCatalogController::class);
$feed = dependency($catalogController, 'productFeed');
$factory = dependency($feed, 'contexts');
$connection = dependency($managed, 'connection');
$customerId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
$addressId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
$sc = $connection->fetchAssociative('SELECT LOWER(HEX(currency_id)) currency, LOWER(HEX(customer_group_id)) customerGroup, LOWER(HEX(payment_method_id)) payment FROM sales_channel WHERE id = UNHEX(?)', [$channelId]);
$salutation = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
$country = $connection->fetchOne('SELECT LOWER(HEX(country_id)) FROM sales_channel_country WHERE sales_channel_id = UNHEX(?) LIMIT 1', [$channelId]);
$container->get('customer.repository')->create([[
    'id' => $customerId, 'email' => 'qa-' . $customerId . '@example.test', 'password' => 'Local-QA-only-987!',
    'firstName' => 'Local', 'lastName' => 'QA', 'customerNumber' => $customerId, 'active' => true, 'guest' => false,
    'salesChannelId' => $channelId, 'groupId' => $sc['customerGroup'], 'defaultPaymentMethodId' => $sc['payment'],
    'salutationId' => $salutation, 'defaultBillingAddressId' => $addressId, 'defaultShippingAddressId' => $addressId,
    'addresses' => [['id' => $addressId, 'firstName' => 'Local', 'lastName' => 'QA', 'salutationId' => $salutation,
        'countryId' => $country, 'street' => 'Teststraat 1', 'zipcode' => '1234AB', 'city' => 'Test']],
]], Shopware\Core\Framework\Context::createDefaultContext());
$context = $factory->create(Shopware\Core\Framework\Uuid\Uuid::randomHex(), $channelId, ['customerId' => $customerId]);
$requests = [];
$http = new Symfony\Component\HttpClient\MockHttpClient(static function ($method, $url, $options) use (&$requests) {
    $requests[] = [$method, $url];
    return new Symfony\Component\HttpClient\Response\MockResponse('{}', ['http_code' => 200]);
});
$api = new LoyaltyEngage\Service\LoyaltyEngageApiService($container->get(Shopware\Core\System\SystemConfig\SystemConfigService::class), $http, new Psr\Log\NullLogger());
$service = new LoyaltyEngage\Service\ManagedRewardService(
    dependency($managed, 'catalog'), dependency($managed, 'eligibility'), dependency($managed, 'legacyShop'), $api,
    dependency($managed, 'carts'), $connection, new Psr\Log\NullLogger(), dependency($managed, 'authorization')
);
$sku = 'LE-DEMO-TOY-' . substr($channelId, 0, 6);
$requestId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
$result = $service->redeem($sku, 'physical', $requestId, $context);
ensure($result['success'], 'Physical reward add: ' . $result['message']);
ensure(count($requests) === 1, 'One reservation request');
$result = $service->redeem($sku, 'physical', $requestId, $context);
ensure($result['success'] && count($requests) === 1, 'Replayed request does not reserve twice');
$cart = dependency($managed, 'carts')->getCart($context->getToken(), $context);
$reward = null;
foreach ($cart->getLineItems() as $item) { if ($item->getPayloadValue('leManagedReward') === true) { $reward = $item; } }
ensure($reward !== null && $reward->getPrice()->getTotalPrice() === 0.0, 'Shopware calculates reward at zero');
ensure(dependency($managed, 'authorization')->valid($reward, $context), 'Reservation authorization is valid');
$result = $service->redeem($sku, 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure(!$result['success'] && count($requests) === 1, 'Duplicate SKU does not charge twice');
$result = $service->redeem('LE-DEMO-EMPTY-' . substr($channelId, 0, 6), 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure(!$result['success'] && count($requests) === 1, 'Out-of-stock reward makes no reservation');
$result = $service->redeem('NOT-A-REWARD', 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure(!$result['success'] && count($requests) === 1, 'Unselected reward makes no reservation');
$anonymous = $factory->create(Shopware\Core\Framework\Uuid\Uuid::randomHex(), $channelId);
$result = $service->redeem($sku, 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $anonymous);
ensure(!$result['success'] && count($requests) === 1, 'Anonymous request makes no reservation');
$rows = iterator_to_array($feed->rows($anonymous));
ensure(!array_key_exists('entity', $rows[0]), 'Feed never exposes the storefront product entity');
$listing = dependency($managed, 'catalog')->listing($anonymous);
ensure($listing['products'][0]['entity'] instanceof Shopware\Core\Content\Product\ProductEntity, 'Native PLP cards receive a Shopware product entity');
ensure(count(array_filter($rows, static fn (array $row): bool => str_starts_with($row['id'], 'LE-DEMO-EMPTY-'))) > 0, 'Out-of-stock product stays in the feed');
$copies = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] === $sku));
foreach ($copies as $copy) {
    $stable = array_diff_key($copy, array_flip(['title', 'short_description', 'language']));
    ensure($stable === array_diff_key($copies[0], array_flip(['title', 'short_description', 'language'])), 'Only permitted translated fields differ');
}
// Exercise removal against the same mocked API, then reject replay of its proof.
$lifecycle = new LoyaltyEngage\Subscriber\ManagedRewardLifecycleSubscriber(dependency($managed, 'authorization'), $api);
$lifecycle->remove(new Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent($reward, $cart, $context));
ensure(count($requests) === 2 && $requests[1][0] === 'DELETE' && str_ends_with($requests[1][1], '/cart/remove'), 'Removal releases only the matching reward');
ensure(!dependency($managed, 'authorization')->valid($reward, $context), 'Removed reservation cannot be replayed');
$cart = dependency($managed, 'carts')->recalculate($cart, $context);
ensure($cart->getErrors()->count() > 0, 'Revoked authorization blocks checkout');
echo "Loyalty shop integration checks passed; external requests were mocked.\n";
