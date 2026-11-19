<?php declare(strict_types=1);

$loader = require $argv[1];
$loader->addPsr4('LoyaltyEngage\\', dirname(__DIR__) . '/src/');
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\NullLogger;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$config = new class extends SystemConfigService {
    public array $data = [];
    public function __construct() {}
    public function get(string $key, ?string $salesChannelId = null) { return $this->data[$key] ?? null; }
};
$requests = [];
$http = new Symfony\Component\HttpClient\MockHttpClient(static function ($method, $url, $options) use (&$requests) {
    $requests[] = [$url, $options];
    return new Symfony\Component\HttpClient\Response\MockResponse('{}', ['http_code' => 200]);
});
$config->data = ['LoyaltyEngage.config.tenantId' => 'test-client', 'LoyaltyEngage.config.bearerToken' => 'test-secret'];
$api = new LoyaltyEngageApiService($config, $http, new NullLogger());
foreach ([-10, 0, 100] as $ratio) {
    $config->data['LoyaltyEngage.config.pointsPerEuro'] = $ratio;
    check($api->getPointsPerEuro() === max(1, $ratio), 'Points conversion ratio must be positive');
}
$config->data['LoyaltyEngage.config.pointsRedemptionEnabled'] = true;
$config->data['LoyaltyEngage.config.discountProductSku'] = 'ONE-EURO';
$pointsService = (new ReflectionClass(LoyaltyEngage\Service\PointsRedemptionService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($pointsService, 'loyaltyEngageApiService'))->setValue($pointsService, $api);
$emptyContext = (new ReflectionClass(SalesChannelContext::class))->newInstanceWithoutConstructor();
$fractional = $pointsService->redeemPointsForDiscount('qa@example.test', 150, $emptyContext);
check($fractional['success'] === false && str_contains($fractional['message'], 'multiples'), 'No fractional extra discount without the corresponding voucher purchase');
foreach (['https://app.loyaltyengage.tech.evil.test', 'https://app.loyaltyengage.tech@evil.test', 'http://app.loyaltyengage.tech',
    'https://app.loyaltyengage.tech:444', 'https://app.loyaltyengage.tech/?secret=must-not-leak',
    'https://app.loyaltyengage.tech/path', 'https://app.loyaltyengage.tech/'] as $url) {
    $config->data['LoyaltyEngage.config.loyaltyApiUrl'] = $url;
    check($api->addToCart('qa@example.test', 'SKU', 'local') === 200, 'Mock request succeeds');
    [$destination, $options] = $requests[count($requests) - 1];
    check(parse_url($destination, PHP_URL_HOST) === 'app.loyaltyengage.tech', 'Credentials never sent to a prefix lookalike');
    check($options['max_redirects'] === 0 && $options['timeout'] === 10.0, 'Bounded requests and no redirects');
}
$context = new class extends SalesChannelContext {
    public ?Shopware\Core\Checkout\Customer\CustomerEntity $testCustomer = null;
    public function __construct() {}
    public function getSalesChannelId(): string { return 'local'; }
    public function getContext(): Context { return Context::createDefaultContext(); }
    public function getCustomer(): ?Shopware\Core\Checkout\Customer\CustomerEntity { return $this->testCustomer; }
};
$guest = new Shopware\Core\Checkout\Customer\CustomerEntity();
$guest->setId(str_repeat('a',32)); $guest->setEmail('guest@example.test'); $guest->setGuest(true);
$context->testCustomer = $guest;
$controller = (new ReflectionClass(LoyaltyEngage\Controller\Storefront\LoyaltyShopController::class))->newInstanceWithoutConstructor();
foreach (['addToCart','claimDiscount'] as $method) {
    check($controller->$method(new Symfony\Component\HttpFoundation\Request(), $context)->getStatusCode() === 401, 'Guest cannot spend contact points');
}
foreach ([LoyaltyEngage\Controller\Api\LoyaltyCartController::class, LoyaltyEngage\Controller\Api\PointsRedemptionController::class] as $class) {
    $reflection = new ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();
    check((new ReflectionMethod($class, 'getCustomerEmail'))->invoke($instance, 'victim@example.test', $context) === null, 'Guest email is not authenticated identity');
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_ends_with($method->getName(), 'Api')) { continue; }
        $parameters = $method->getParameters(); $last = $parameters[count($parameters)-1];
        check($last->getName() === 'email' && $last->isDefaultValueAvailable() && $last->getDefaultValue() === null, 'Store API can resolve absent optional email');
    }
}
$authorization = new class extends LoyaltyEngage\Service\RewardAuthorization {
    public function __construct() {}
    public function valid(LineItem $item, SalesChannelContext $context): bool { return false; }
};
$shopConfig = new LoyaltyShopConfig($config);
$calculator = (new ReflectionClass(Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator::class))->newInstanceWithoutConstructor();
$processor = new LoyaltyEngage\Cart\LoyaltyFreeProductProcessor($calculator, $shopConfig, $authorization);
$cart = new Cart('security-test');
$product = new LineItem(str_repeat('b',32), 'product', str_repeat('c',32));
$product->setPayloadValue('loyaltyFreeProduct', true);
$product->setPrice(new Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(25,25,
    new Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(), new Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection()));
$cart->add($product);
$processor->process(new Shopware\Core\Checkout\Cart\LineItem\CartDataCollection(), $cart, $cart, $context, new Shopware\Core\Checkout\Cart\CartBehavior());
check($product->getPrice()->getTotalPrice() === 25.0, 'Forged free-product flag cannot zero a regular product while native shop is disabled');
$catalog = (new ReflectionClass(LoyaltyEngage\Service\LoyaltyProductCatalog::class))->newInstanceWithoutConstructor();
$eligibility = new LoyaltyEngage\Service\LoyaltyShopEligibility($shopConfig, $catalog);
$validator = new LoyaltyEngage\Cart\LoyaltyShopCartValidator($catalog, $eligibility, $shopConfig, $authorization);
$errors = new ErrorCollection();
$validator->validate($cart, $errors, $context);
check($errors->count() === 1, 'Unproven free-product flag blocks checkout even in legacy mode');

$promotion = new Shopware\Core\Checkout\Promotion\PromotionEntity();
$promotion->setId(str_repeat('d',32)); $promotion->setName('Customer-facing title');
$promotion->setCustomFields(['le_voucher_identity' => str_repeat('e',64)]);
$individual = new Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeEntity();
$individual->setId(str_repeat('f',32)); $individual->setPromotion($promotion);
$repository = new class($individual) extends Shopware\Core\Framework\DataAbstractionLayer\EntityRepository {
    public function __construct(private readonly Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeEntity $code) {}
    public function search(Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria $criteria, Context $context): Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult {
        return new Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult('promotion_individual_code', 1,
            new Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeCollection([$this->code]), null, $criteria, $context);
    }
};
$srs = new class extends LoyaltyEngageApiService {
    public int $calls = 0;
    public function __construct() {}
    public function isSrsDiscountSyncEnabled(?string $salesChannelId = null): bool { return true; }
    public function checkSrsVoucherStatus(string $barcode, ?string $salesChannelId = null): array { ++$this->calls; return ['local_redeemed' => true]; }
};
$cart = new Cart('srs-test'); $line = new LineItem('voucher', 'promotion', 'CODE'); $line->setPayloadValue('code','CODE'); $cart->add($line);
$errors = new ErrorCollection();
(new LoyaltyEngage\Cart\SrsDiscountValidator($srs, $repository, $repository, new NullLogger()))->validate($cart, $errors, $context);
check($srs->calls === 1 && $errors->count() === 1, 'Renamed, already-used SRS voucher is blocked during checkout');
check($errors->first()->blockOrder(), 'Blocked SRS voucher must prevent actual order placement');
$cleanupLogger = new class extends Psr\Log\AbstractLogger {
    public array $entries = [];
    public function log($level, string|Stringable $message, array $context = []): void { $this->entries[] = [$message, $context]; }
};
(new LoyaltyEngage\Message\Handler\FreeProductRemoveMessageHandler($cleanupLogger))(
    new LoyaltyEngage\Message\FreeProductRemoveMessage('qa@example.test', 'local')
);
check(count($cleanupLogger->entries) === 1 && !str_contains(json_encode($cleanupLogger->entries), 'qa@example.test'), 'Old unscoped cleanup logs reconciliation without clearing newer reservations or exposing email');
echo "Security smoke passed: credential host boundary, request limits, guest identity, API argument resolution, free-product forgery and renamed SRS voucher checks.\n";
