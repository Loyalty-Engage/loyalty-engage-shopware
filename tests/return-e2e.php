<?php declare(strict_types=1);

// Commits fixtures only in a disposable le_stage_* database. All provider requests are mocked.
// php tests/return-e2e.php /var/www/html SALES_CHANNEL_ID --confirm-local
if (($argv[3] ?? '') !== '--confirm-local' || !in_array(getenv('APP_URL'), ['http://shopware.test:8081', 'http://localhost:8082'], true)) {
    throw new RuntimeException('Only the explicitly confirmed local environment is supported.');
}
$root = $argv[1];
$channel = $argv[2];
$loader = require $root . '/vendor/autoload.php';
$_SERVER['PROJECT_ROOT'] = $root;
$plugins = new Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader($loader, null, Shopware\Core\Kernel::getConnection());
if (class_exists(Shopware\Core\Framework\Adapter\Kernel\KernelFactory::class)) {
    $kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create(environment: 'prod', debug: false, classLoader: $loader, pluginLoader: $plugins);
} else {
    $httpKernel = new Shopware\Core\HttpKernel('prod', false, $loader);
    $httpKernel->setPluginLoader($plugins);
    $kernel = $httpKernel->getKernel();
}
$kernel->boot();
$container = $kernel->getContainer();
$db = Shopware\Core\Kernel::getConnection();
$ctx = Shopware\Core\Framework\Context::createDefaultContext();
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS $label\n"; }
function prop(object $object, string $name): mixed
{
    if (method_exists($object, 'initializeLazyObject')) { $object->initializeLazyObject(); }
    $class = new ReflectionClass($object);
    do {
        if ($class->hasProperty($name)) { return $class->getProperty($name)->getValue($object); }
    } while ($class = $class->getParentClass());
    throw new RuntimeException('Missing test dependency: ' . $name);
}
function uuid(): string { return Shopware\Core\Framework\Uuid\Uuid::randomHex(); }

check(str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'le_stage_'), 'Disposable upgrade database required');
$controller = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyShopController::class);
$service = prop($controller, 'loyaltyShopService');
$api = prop($service, 'loyaltyEngageApiService');
$carts = prop($service, 'cartService');
$ruleLoader = prop(prop($carts, 'calculator'), 'cartRuleLoader');
$catalogController = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyCatalogController::class);
$factory = prop(prop($catalogController, 'productFeed'), 'contexts');

$requests = [];
$mock = new Symfony\Component\HttpClient\MockHttpClient(static function ($method, $url, $options) use (&$requests) {
    $requests[] = [$method, $url, json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR)];
    return new Symfony\Component\HttpClient\Response\MockResponse('{}', ['http_code' => 200]);
});
(new ReflectionProperty($api, 'httpClient'))->setValue($api, $mock);
$settings = $container->get(Shopware\Core\System\SystemConfig\SystemConfigService::class);
$settings->set('LoyaltyEngage.config.returnEvent', true);
    $sc = $db->fetchAssociative('SELECT LOWER(HEX(currency_id)) currency, LOWER(HEX(customer_group_id)) customerGroup, LOWER(HEX(payment_method_id)) payment FROM sales_channel WHERE id = UNHEX(?)', [$channel]);
    check((bool) $sc, 'Local sales channel found');
    $salutation = $db->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
    $country = $db->fetchOne('SELECT LOWER(HEX(country_id)) FROM sales_channel_country WHERE sales_channel_id = UNHEX(?) LIMIT 1', [$channel]);
    $customer = uuid(); $address = uuid(); $email = 'voucher-' . $customer . '@example.test';
    $container->get('customer.repository')->create([[
        'id' => $customer, 'email' => $email, 'password' => 'Local-QA-Only-987!', 'firstName' => 'Voucher', 'lastName' => 'QA',
        'customerNumber' => $customer, 'active' => true, 'guest' => false, 'salutationId' => $salutation,
        'salesChannelId' => $channel, 'groupId' => $sc['customerGroup'], 'defaultPaymentMethodId' => $sc['payment'],
        'defaultBillingAddressId' => $address, 'defaultShippingAddressId' => $address,
        'addresses' => [['id' => $address, 'firstName' => 'Voucher', 'lastName' => 'QA', 'salutationId' => $salutation,
            'countryId' => $country, 'street' => 'Teststraat 1', 'zipcode' => '1234AB', 'city' => 'Test']],
    ]], $ctx);
    $tax = $db->fetchAssociative('SELECT LOWER(HEX(id)) id, tax_rate rate FROM tax LIMIT 1');
    $product = uuid();
    $products = [$product, uuid()];
    foreach ($products as $product) { $container->get('product.repository')->create([[
        'id' => $product, 'productNumber' => 'QA-' . $product, 'name' => 'Voucher checkout QA product', 'active' => true,
        'stock' => 100, 'taxId' => $tax['id'], 'price' => [['currencyId' => $sc['currency'], 'gross' => 25,
            'net' => round(25 / (1 + $tax['rate'] / 100), 2), 'linked' => true]],
        'visibilities' => [['salesChannelId' => $channel, 'visibility' => 30]],
        'customFields' => ['le_shop_enabled' => false, 'le_shop_regular_purchase' => true],
    ]], $ctx);
    }
    $context = $factory->create(uuid(), $channel, ['customerId' => $customer]);
    $cart = $carts->getCart($context->getToken(), $context);

foreach ($products as $i => $product) {
    $item = new Shopware\Core\Checkout\Cart\LineItem\LineItem($product, 'product', $product, $i === 0 ? 3 : 1);
    $item->setStackable(true);
    $cart = $carts->add($cart, $item, $context);
}
    $route = $container->get(Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute::class);
    $order = $route->order($cart, $context, new Shopware\Core\Framework\Validation\DataBag\RequestDataBag(['tos' => true]))->getOrder();

$oid = $order->getId();
$criteria = new Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
$criteria->addFilter(new Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('orderId', $oid));
$criteria->addAssociation('positions.orderLineItem');
$repository = $container->get('order_delivery.repository');
$original = $repository->search($criteria, $ctx)->first();
$split = uuid(); $empty = uuid();
foreach ([$split, $empty] as $newId) {
    $repository->create([['id' => $newId, 'orderId' => $oid, 'shippingOrderAddressId' => $original->getShippingOrderAddressId(),
        'shippingMethodId' => $original->getShippingMethodId(), 'stateId' => $original->getStateId(), 'trackingCodes' => [],
        'shippingDateEarliest' => $original->getShippingDateEarliest(), 'shippingDateLatest' => $original->getShippingDateLatest(),
        'shippingCosts' => $original->getShippingCosts()]], $ctx);
}
foreach ($original->getPositions() as $position) {
    if ($position->getOrderLineItem()->getProductId() !== $products[0]) { continue; }
    $positions = $container->get('order_delivery_position.repository');
    $price = $position->getPrice();
    $splitPrice = static fn (int $qty) => new Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
        $price->getUnitPrice(), $price->getUnitPrice() * $qty, $price->getCalculatedTaxes(), $price->getTaxRules(), $qty
    );
    $positions->update([['id' => $position->getId(), 'price' => $splitPrice(1)]], $ctx);
    $positions->create([['id' => uuid(), 'orderDeliveryId' => $split, 'orderLineItemId' => $position->getOrderLineItemId(),
        'price' => $splitPrice(2)]], $ctx);
}
$registry = prop($container->get(Shopware\Core\System\StateMachine\Api\StateMachineActionController::class), 'stateMachineRegistry');
$transition = static function ($id, $action) use ($ctx, $registry) {
    $registry->transition(new Shopware\Core\System\StateMachine\Transition('order_delivery', $id, $action, 'stateId'), $ctx);
};
$outbox = new LoyaltyEngage\Service\DeliveryOutbox($db, $api, new Psr\Log\NullLogger());
$count = static fn () => (int) $db->fetchOne("SELECT COUNT(*) FROM le_delivery_outbox WHERE kind='return'");
$before = $count(); $requests = [];
foreach ([$empty, $split, $original->getId()] as $id) { $transition($id, 'ship'); }
$transition($empty, 'retour');
$transition($original->getId(), 'retour_partially');
check($count() === $before && !$requests, 'No outbox entry or HTTP request for empty returned delivery or ambiguous partial status');
foreach ([$split => [$products[0] => 2], $original->getId() => [$products[0] => 1, $products[1] => 1]] as $id => $expected) {
    $requests = [];
    $transition($id, 'retour');
    $key = hash('sha256', 'return:' . $channel . ':' . $id);
    $outbox->process($key);
    check(count($requests) === 1 && $requests[0][0] === 'POST' && str_ends_with($requests[0][1], '/api/v1/events'), 'Exactly one events request after real delivery transition');
    $event = $requests[0][2][0];
    check($event['orderId'] === $order->getOrderNumber() && $event['event'] === 'Return', 'Original purchase orderId on Return');
    $actual = [];
    foreach ($event['products'] as $row) { $actual[$row['sku']] = ($actual[$row['sku']] ?? 0) + $row['quantity']; }
    foreach ($expected as $pid => $qty) { check(($actual['QA-' . $pid] ?? null) === $qty, 'Actual HTTP body contains correct returned quantity'); }
    check(count($actual) === count($expected), 'No unreturned SKU in HTTP body');
    $outbox->process($key);
    check(count($requests) === 1, 'Completed return cannot be redelivered');
}
