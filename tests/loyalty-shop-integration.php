<?php declare(strict_types=1);

// Local test only: php tests/loyalty-shop-integration.php /var/www/html SALES_CHANNEL_ID
// Uses real Shopware carts and a mocked HTTP client. Never contacts Loyalty Engage.
$root = $argv[1];
$channelId = $argv[2];
$loader = require $root . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
$_SERVER['PROJECT_ROOT'] = $root;
$plugins = new Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader($loader, null, Shopware\Core\Kernel::getConnection());
$kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create(environment: 'prod', debug: false, classLoader: $loader, pluginLoader: $plugins);
$kernel->boot();
$container = $kernel->getContainer();
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
$customerId = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM customer WHERE guest = 0 AND active = 1 LIMIT 1');
ensure((bool) $customerId, 'A local non-guest customer is needed for the cart integration test.');
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
