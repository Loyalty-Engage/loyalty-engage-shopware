<?php declare(strict_types=1);

// Disposable local database only; all writes are rolled back, all LE HTTP is mocked.
// php tests/voucher-checkout-integration.php /var/www/html SALES_CHANNEL_ID --confirm-local
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
function promos($cart): array { return array_values(array_filter(iterator_to_array($cart->getLineItems()), static fn ($item) => $item->getType() === 'promotion' && $item->getPrice() !== null && $item->getPrice()->getTotalPrice() < 0)); }

$controller = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyShopController::class);
$service = prop($controller, 'loyaltyShopService');
(new ReflectionProperty($service, 'logger'))->setValue($service, new class extends Psr\Log\AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void {
        if (in_array($level, ['error', 'warning'], true)) { echo $message . ' ' . json_encode($context) . "\n"; }
    }
});
$api = prop($service, 'loyaltyEngageApiService');
$carts = prop($service, 'cartService');
$ruleLoader = prop(prop($carts, 'calculator'), 'cartRuleLoader');
$catalogController = $container->get(LoyaltyEngage\Controller\Storefront\LoyaltyCatalogController::class);
$factory = prop(prop($catalogController, 'productFeed'), 'contexts');
$requests = [];
$sku = 'QA-VOUCHER-' . uuid();
$code = 'QA-CODE-' . uuid();
$response = ['discountCode' => $code, 'discountAmount' => 10.0, 'discountPercentage' => null, 'discountCurrency' => 'EUR',
    'discountMinimumOrderValue' => 50.0, 'product' => ['title' => 'Testvoucher tien euro'], 'spentCoins' => 100, 'availableCoins' => 900];
$mock = new Symfony\Component\HttpClient\MockHttpClient(static function ($method, $url, $options) use (&$requests, &$response) {
    $requests[] = [$method, $url];
    return new Symfony\Component\HttpClient\Response\MockResponse(json_encode(str_contains($url, 'buy_discount_code') ? $response : ['success' => true]), ['http_code' => 200]);
});
$oldClient = prop($api, 'httpClient');
(new ReflectionProperty($api, 'httpClient'))->setValue($api, $mock);
$settings = $container->get(Shopware\Core\System\SystemConfig\SystemConfigService::class);
$oldSrs = $settings->get('LoyaltyEngage.config.srsVoucherCreationEnabled', $channel);
$db->beginTransaction();
try {
    $settings->set('LoyaltyEngage.config.srsVoucherCreationEnabled', false, $channel);
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
    $container->get('product.repository')->create([[
        'id' => $product, 'productNumber' => 'QA-' . $product, 'name' => 'Voucher checkout QA product', 'active' => true,
        'stock' => 100, 'taxId' => $tax['id'], 'price' => [['currencyId' => $sc['currency'], 'gross' => 25,
            'net' => round(25 / (1 + $tax['rate'] / 100), 2), 'linked' => true]],
        'visibilities' => [['salesChannelId' => $channel, 'visibility' => 30]],
        'customFields' => ['le_shop_enabled' => false, 'le_shop_regular_purchase' => true],
    ]], $ctx);
    $context = $factory->create(uuid(), $channel, ['customerId' => $customer]);
    $cart = $carts->getCart($context->getToken(), $context);
    $item = new Shopware\Core\Checkout\Cart\LineItem\LineItem($product, 'product', $product, 1);
    $item->setStackable(true); $item->setRemovable(true);
    $cart = $carts->add($cart, $item, $context);
    $claimed = $service->claimDiscountCodeProduct($email, $sku, 10, $context, 'absolute');
    check($claimed['success'] === true && $claimed['discountCode'] === $code, 'Voucher claimed through mocked API without spending real coins');
    $cart = $carts->getCart($context->getToken(), $context);
    check(count(promos($cart)) === 0, 'Voucher does not discount a cart below minimum');
    $promotion = $db->fetchOne('SELECT LOWER(HEX(promotion_id)) FROM promotion_individual_code WHERE code = ?', [$code]);
    check((bool) $promotion, 'Promotion and individual code persisted');
    $srsApi = new class extends LoyaltyEngage\Service\LoyaltyEngageApiService {
        public int $checks = 0;
        public function __construct() {}
        public function isSrsDiscountSyncEnabled(?string $salesChannelId = null): bool { return true; }
        public function checkSrsVoucherStatus(string $barcode, ?string $salesChannelId = null): array { ++$this->checks; return []; }
    };
    $srsSubscriber = new LoyaltyEngage\Subscriber\SrsDiscountAddedSubscriber($srsApi,
        $container->get('promotion_individual_code.repository'), $container->get('promotion.repository'), new Psr\Log\NullLogger());
    $placeholder = new Shopware\Core\Checkout\Cart\LineItem\LineItem(uuid(), 'promotion', $code);
    $srsSubscriber->onBeforeLineItemAdded(new Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent($placeholder, $cart, $context));
    check($srsApi->checks === 1, 'Renamed voucher still receives SRS status validation');
    $cart = $carts->changeQuantity($cart, $product, 3, $context);
    check(count(promos($cart)) === 1, 'Voucher activates above minimum after quantity change');
    check(promos($cart)[0]->getLabel() === 'Testvoucher tien euro', 'Cart displays API voucher title');
    check(promos($cart)[0]->getPrice()->getTotalPrice() === -10.0, 'Correct absolute discount');
    $cart = $carts->changeQuantity($cart, $product, 1, $context);
    check(count(promos($cart)) === 0, 'Reducing cart below minimum removes discount');
    $response['discountMinimumOrderValue'] = 60.0;
    $response['product']['title'] = 'Bijgewerkte vouchernaam';
    $response['discountCode'] = 'QA-UPDATE-' . uuid();
    $updatedClaim = $service->claimDiscountCodeProduct($email, $sku, 10, $context, 'absolute');
    check(($updatedClaim['discountCode'] ?? null) === $response['discountCode'], 'Second API response used');
    $updatedPromotion = $db->fetchOne('SELECT LOWER(HEX(promotion_id)) FROM promotion_individual_code WHERE code = ?', [$response['discountCode']]);
    check($updatedPromotion === $promotion, 'Renamed voucher reuses existing promotion');
    // Simulate the next HTTP request: Shopware resets its request-local rule list.
    $ruleLoader->reset();
    $cart = $carts->getCart($context->getToken(), $context, false);
    $cart = $carts->changeQuantity($cart, $product, 2, $context);
    check(count(promos($cart)) === 0, 'Updated higher minimum is enforced on existing promotion');
    $sync = new ReflectionMethod($service, 'findOrCreatePromotion');
    $response['discountMinimumOrderValue'] = 0.0;
    $sync->invoke($service, $sku, 10.0, 'absolute', $context, false, $response);
    $ruleLoader->reset();
    // Shopware may drop invalid codes while reloading. Enter the existing code again.
    (new ReflectionMethod($service, 'applyPromotionToCart'))->invoke($service, $code, $context);
    $cart = $carts->getCart($context->getToken(), $context, false);
    $cart = $carts->changeQuantity($cart, $product, 1, $context);
    check(count(promos($cart)) === 1, 'Zero minimum removes the existing threshold');
    $response['discountMinimumOrderValue'] = 60.0;
    $sync->invoke($service, $sku, 10.0, 'absolute', $context, false, $response);
    $ruleLoader->reset();
    $cart = $carts->getCart($context->getToken(), $context, false);
    $cart = $carts->changeQuantity($cart, $product, 3, $context);
    (new ReflectionMethod($service, 'applyPromotionToCart'))->invoke($service, $code, $context);
    $cart = $carts->getCart($context->getToken(), $context, false);
    check(count(promos($cart)) >= 1 && promos($cart)[0]->getLabel() === 'Bijgewerkte vouchernaam', 'Updated name visible in recalculated cart');
    $cart = $carts->recalculate($cart, $context);
    check(count(promos($cart)) === 1, 'Single voucher ready for checkout');
    $checkoutCode = promos($cart)[0]->getReferencedId();
    $route = $container->get(Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute::class);
    $order = $route->order($cart, $context, new Shopware\Core\Framework\Validation\DataBag\RequestDataBag(['tos' => true]))->getOrder();
    $orderPromos = array_values(array_filter(iterator_to_array($order->getLineItems()), static fn ($line) => $line->getType() === 'promotion'));
    check(count($orderPromos) === 1 && $orderPromos[0]->getLabel() === 'Bijgewerkte vouchernaam', 'Actual checkout order has the correct voucher name');
    check($orderPromos[0]->getTotalPrice() === -10.0, 'Actual order stores the voucher discount');
    check(!(bool) $db->fetchOne('SELECT id FROM promotion_individual_code WHERE code = ?', [$checkoutCode]), 'Redeemed individual code removed to prevent reuse');
    check(count(array_filter($requests, static fn ($request) => str_contains($request[1], '/redeem'))) === 1, 'Renamed voucher redeemed exactly once via mocked API');
    $fresh = $factory->create(uuid(), $channel, ['customerId' => $customer]);
    $freshCart = $carts->getCart($fresh->getToken(), $fresh);
    $freshItem = new Shopware\Core\Checkout\Cart\LineItem\LineItem($product, 'product', $product, 3);
    $freshItem->setStackable(true); $freshItem->setRemovable(true);
    $freshCart = $carts->add($freshCart, $freshItem, $fresh);
    (new ReflectionMethod($service, 'applyPromotionToCart'))->invoke($service, $checkoutCode, $fresh);
    $freshCart = $carts->getCart($fresh->getToken(), $fresh);
    check(count(promos($freshCart)) === 0, 'Redeemed code cannot discount a new cart');
    echo "Voucher checkout integration passed; no external HTTP requests were made.\n";
} finally {
    $settings->set('LoyaltyEngage.config.srsVoucherCreationEnabled', $oldSrs, $channel);
    $db->rollBack();
    (new ReflectionProperty($api, 'httpClient'))->setValue($api, $oldClient);
    echo "Local test database writes rolled back.\n";
}
