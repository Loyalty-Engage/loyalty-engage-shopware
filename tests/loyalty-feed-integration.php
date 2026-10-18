<?php declare(strict_types=1);

// Run in the existing local demo environment with its source URL configured.
// Reservation and voucher APIs are mocked. Native cart validation may read the source feed.
require __DIR__ . '/loyalty-shop-integration.php';
$settings->set('LoyaltyEngage.config.shopProductSource', 'loyalty_feed', $channelId);
$catalogRoot = dependency($managed, 'catalog');
$config = dependency($catalogRoot, 'config');
$body = file_get_contents(($argv[3] ?? dirname(__DIR__)) . '/tests/fixtures/loyalty-products.ndjson');
$sourceClient = new Symfony\Component\HttpClient\MockHttpClient(static function () use (&$body) {
    return new Symfony\Component\HttpClient\Response\MockResponse($body);
});
$source = new LoyaltyEngage\Service\LoyaltyFeedReader($config, $sourceClient, new Symfony\Component\Cache\Adapter\ArrayAdapter(), new Psr\Log\NullLogger());
$catalog = new LoyaltyEngage\Service\LoyaltyProductCatalog(dependency($catalogRoot, 'products'), dependency($catalogRoot, 'languages'), $config, new Psr\Log\NullLogger(), $source);
$eligibility = new LoyaltyEngage\Service\LoyaltyShopEligibility($config, $catalog);
$voucherCalls = [];
$voucherShop = new class extends LoyaltyEngage\Service\LoyaltyShopService {
    public array $calls = [];
    public function __construct() {}
    public function claimDiscountCodeProduct(string $email, string $sku, float $discount, Shopware\Core\System\SalesChannel\SalesChannelContext $context, string $fallbackDiscountType = 'percentage', bool $managed = false): array
    {
        $this->calls[] = [$sku, $discount, $fallbackDiscountType, $managed];
        return ['success' => true, 'message' => 'Mocked voucher'];
    }
};
$service = new LoyaltyEngage\Service\ManagedRewardService($catalog, $eligibility, $voucherShop, $api, dependency($managed, 'carts'), $connection, new Psr\Log\NullLogger(), dependency($managed, 'authorization'));
$context = $factory->create(Shopware\Core\Framework\Uuid\Uuid::randomHex(), $channelId, ['customerId' => $customerId]);
$listing = $catalog->listing($context);
ensure(!isset($listing['error']), 'Feed-backed listing loads');
$vouchers = array_values(array_filter($listing['products'], static fn (array $row): bool => $row['type'] === 'discount_code'));
ensure(count($vouchers) === 2, 'Both vouchers are shown without custom-field selection');
$result = $service->redeem('DISCOUNT_PER_10', 'discount_code', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure($result['success'] && $voucherShop->calls[0] === ['DISCOUNT_PER_10', 10.0, 'percentage', true], 'Percentage voucher gets server-side feed value');
$result = $service->redeem('DISCOUNT_SKU_10', 'discount_code', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure($result['success'] && $voucherShop->calls[1] === ['DISCOUNT_SKU_10', 10.0, 'absolute', true], 'Absolute voucher gets server-side feed value');
$usd = $connection->fetchOne("SELECT LOWER(HEX(id)) FROM currency WHERE iso_code = 'USD'");
if ($usd) {
    $foreignContext = $factory->create(Shopware\Core\Framework\Uuid\Uuid::randomHex(), $channelId, ['customerId' => $customerId, 'currencyId' => $usd]);
    $result = $service->redeem('DISCOUNT_SKU_10', 'discount_code', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $foreignContext);
    ensure(!$result['success'] && count($voucherShop->calls) === 2, 'Currency mismatch cannot claim a fixed-currency voucher');
}
$before = count($requests);
$result = $service->redeem('24-MB01', 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure($result['success'], 'Matched physical feed reward can be reserved: ' . $result['message']);
ensure(count($requests) === $before + 1, 'One mocked physical reservation');
$before = count($requests);
$result = $service->redeem('DISCOUNT_PER_10', 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure(!$result['success'] && count($requests) === $before, 'Voucher cannot be redeemed as a physical product');
$body = json_encode(array_values(array_filter($source->parse($body), static fn (array $row): bool => $row['type'] === 'discount_code')));
$source->products($channelId, true);
ensure($catalog->find('24-MB01', $context) === null, 'Removed feed SKU disappears from catalog');
$result = $service->redeem('24-MB01', 'physical', Shopware\Core\Framework\Uuid\Uuid::randomHex(), $context);
ensure(!$result['success'] && count($requests) === $before, 'Removed feed SKU makes no reservation request');
$cart = dependency($managed, 'carts')->getCart($context->getToken(), $context);
$lifecycle = new LoyaltyEngage\Subscriber\ManagedRewardLifecycleSubscriber(dependency($managed, 'authorization'), $api);
foreach ($cart->getLineItems() as $item) {
    if ($item->getPayloadValue('leManagedReward') === true) {
        $lifecycle->remove(new Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent($item, $cart, $context));
    }
}
echo "Loyalty feed integration checks passed; no real rewards were claimed.\n";
