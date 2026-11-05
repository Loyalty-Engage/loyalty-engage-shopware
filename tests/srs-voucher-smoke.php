<?php declare(strict_types=1);

// Run with: php tests/srs-voucher-smoke.php /path/to/shopware/vendor/autoload.php
require $argv[1];
require ($argv[2] ?? dirname(__DIR__)) . '/src/Service/LoyaltyEngageApiService.php';

use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class VoucherTestConfig extends SystemConfigService
{
    public array $values = [];

    public function __construct() {}

    public function get(string $key, ?string $salesChannelId = null)
    {
        return $this->values[$salesChannelId ?? 'global'][$key] ?? null;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = new VoucherTestConfig();
$config->values['global'] = [
    'LoyaltyEngage.config.tenantId' => 'global-client',
    'LoyaltyEngage.config.bearerToken' => 'global-secret',
    'LoyaltyEngage.config.srsVoucherCreationEnabled' => true,
    'LoyaltyEngage.config.srsTenantId' => 'global-tenant',
];
$config->values['srs-channel'] = [
    'LoyaltyEngage.config.tenantId' => 'channel-client',
    'LoyaltyEngage.config.bearerToken' => 'channel-secret',
    'LoyaltyEngage.config.srsTenantId' => 'channel-tenant',
];
$config->values['direct-channel']['LoyaltyEngage.config.srsVoucherCreationEnabled'] = false;
$requests = [];
$body = ['success' => true, 'barcode' => '2400060000312', 'value' => 7.5];
$status = 200;
$client = new MockHttpClient(function ($method, $url, $options) use (&$requests, &$body, &$status) {
    $requests[] = compact('method', 'url', 'options');
    return new MockResponse(is_string($body) ? $body : json_encode($body), ['http_code' => $status]);
});
$api = new LoyaltyEngageApiService($config, $client, new NullLogger());
$result = $api->buyDiscountCodeProduct('customer@example.test', 'voucher-discount-7.50', 'srs-channel');
check($requests[0]['url'] === 'https://prod.bridge.loyaltyengage.tech/api/srs/v1/create-voucher', 'Production bridge URL');
check($requests[0]['method'] === 'POST', 'Bridge method');
check(json_decode($requests[0]['options']['body'], true) === [
    'tenant' => 'channel-tenant', 'email' => 'customer@example.test', 'voucher_type' => 'voucher-discount-7.50',
], 'Bridge payload uses channel tenant, customer email and exact SKU');
check(in_array('Authorization: Basic ' . base64_encode('channel-client:channel-secret'), $requests[0]['options']['headers'], true), 'Channel Basic Auth');
check($result['discountCode'] === '2400060000312' && $result['discountAmount'] === 7.5 && $result['discountPercentage'] === null, 'Absolute discount normalization');
$api->buyDiscountCodeProduct('customer@example.test', 'voucher-discount-7.50', 'inherited-channel');
check(in_array('Authorization: Basic ' . base64_encode('global-client:global-secret'), $requests[1]['options']['headers'], true), 'Global credentials fallback');
check(json_decode($requests[1]['options']['body'], true)['tenant'] === 'global-tenant', 'Global tenant fallback');
$body = ['discountCode' => 'DIRECT', 'discountPercentage' => 10];
$result = $api->buyDiscountCodeProduct('customer@example.test', 'DISCOUNT10', 'direct-channel');
check(str_contains($requests[2]['url'], '/cart/buy_discount_code'), 'Explicit false preserves direct route');
check($result['discountCode'] === 'DIRECT', 'Direct response unchanged');
$config->values['global']['LoyaltyEngage.config.srsTenantId'] = '';
$count = count($requests);
check($api->buyDiscountCodeProduct('customer@example.test', 'SKU', 'inherited-channel')['success'] === false, 'Missing tenant fails');
check(count($requests) === $count, 'Missing tenant makes no request');
$config->values['global']['LoyaltyEngage.config.srsTenantId'] = 'global-tenant';
$config->values['global']['LoyaltyEngage.config.bearerToken'] = '';
check($api->buyDiscountCodeProduct('customer@example.test', 'SKU', 'inherited-channel')['success'] === false, 'Missing credentials fail');
check(count($requests) === $count, 'Missing credentials make no request');
$config->values['global']['LoyaltyEngage.config.bearerToken'] = 'global-secret';
$status = 400;
$body = ['success' => false, 'error' => 'insufficient_balance'];
$result = $api->buyMultipleDiscountCodeProducts('customer@example.test', 'SKU', 3, 'srs-channel');
check($result['success'] === false && $result['successCount'] === 0 && $result['failCount'] === 1, 'Bulk purchase stops on bridge failure');
check(count($requests) === $count + 1, 'No retry or direct fallback');
$status = 200;
$body = ['success' => true];
check($api->buyDiscountCodeProduct('customer@example.test', 'SKU', 'srs-channel')['success'] === false, 'Incomplete success response rejected');
$body = 'not-json';
check($api->buyDiscountCodeProduct('customer@example.test', 'SKU', 'srs-channel')['success'] === false, 'Invalid response rejected');
$status = 201;
$body = ['success' => true, 'barcode' => '2400060000312', 'value' => 7.5];
check($api->buyDiscountCodeProduct('customer@example.test', 'SKU', 'srs-channel')['discountAmount'] === 7.5, 'HTTP 201 creation accepted');
echo "SRS voucher smoke checks passed\n";
