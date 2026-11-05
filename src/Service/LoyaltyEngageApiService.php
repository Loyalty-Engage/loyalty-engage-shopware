<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class LoyaltyEngageApiService
{
    private const HTTP_OK = 200;
    private const DEFAULT_API_URL = 'https://app.loyaltyengage.tech';
    private const REQUIRED_API_BASE = 'https://app.loyaltyengage.tech';
    private const SRS_BRIDGE_URL = 'https://prod.bridge.loyaltyengage.tech';

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var HttpClientInterface
     */
    private $httpClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SystemConfigService $systemConfigService
     * @param HttpClientInterface $httpClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Get API URL from config
     */
    public function getApiUrl(): ?string
    {
        return $this->systemConfigService->get('LoyaltyEngage.config.loyaltyApiUrl');
    }

    /**
     * Get Tenant ID from config
     */
    public function getTenantId(?string $salesChannelId = null): ?string
    {
        return $this->getConfigValueWithFallback('LoyaltyEngage.config.tenantId', $salesChannelId);
    }

    /**
     * Get Bearer Token from config
     */
    public function getBearerToken(?string $salesChannelId = null): ?string
    {
        return $this->getConfigValueWithFallback('LoyaltyEngage.config.bearerToken', $salesChannelId);
    }

    /**
     * Get Logger Status from config
     */
    public function getLoggerStatus(): bool
    {
        return (bool) $this->systemConfigService->get('LoyaltyEngage.config.loggerEnable');
    }

    /**
     * Get Cart Expiry Time from config
     */
    public function getExpiryTime(): int
    {
        return (int) $this->systemConfigService->get('LoyaltyEngage.config.cartExpiryTime');
    }

    /**
     * Get Loyalty Order Retrieve Limit from config
     */
    public function getLoyaltyOrderRetrieveLimit(): int
    {
        return (int) $this->systemConfigService->get('LoyaltyEngage.config.loyaltyOrderRetrieveLimit');
    }

    /**
     * Check if SRS Discount Sync is enabled
     */
    public function isSrsDiscountSyncEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->getConfigValueWithFallback('LoyaltyEngage.config.srsDiscountSyncEnabled', $salesChannelId);
    }

    public function isSrsVoucherCreationEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->getConfigValueWithFallback('LoyaltyEngage.config.srsVoucherCreationEnabled', $salesChannelId);
    }

    /**
     * Get SRS Tenant ID from config
     */
    public function getSrsTenantId(?string $salesChannelId = null): ?string
    {
        return $this->getConfigValueWithFallback('LoyaltyEngage.config.srsTenantId', $salesChannelId);
    }

    /**
     * Get SRS platform key from config.
     */
    public function getSrsPlatformKey(?string $salesChannelId = null): ?string
    {
        return $this->getConfigValueWithFallback('LoyaltyEngage.config.srsPlatformKey', $salesChannelId);
    }

    /**
     * Get SRS branch/filiaal ID from config.
     */
    public function getSrsBranchId(?string $salesChannelId = null): ?string
    {
        return $this->getConfigValueWithFallback('LoyaltyEngage.config.srsBranchId', $salesChannelId) ?: '1';
    }

    /**
     * Check if enough SRS redeem configuration is present to call the bridge.
     */
    public function canRedeemSrsVoucher(?string $salesChannelId = null): bool
    {
        return !empty($this->getSrsTenantId($salesChannelId))
            && !empty($this->getSrsPlatformKey($salesChannelId))
            && !empty($this->getSrsBranchId($salesChannelId));
    }

    /**
     * Check voucher status via SRS API.
     *
     * Returns the decoded response array on success, or an empty array on failure.
     * The caller should check srs_check_success === true && srs_status === 0 for "already redeemed".
     */
    public function checkSrsVoucherStatus(string $barcode, ?string $salesChannelId = null): array
    {
        $tenantId = $this->getSrsTenantId($salesChannelId);
        if (!$tenantId) {
            $this->logger->warning('LoyaltyEngage: SRS Tenant ID not configured, skipping voucher check', [
                'barcode' => $barcode,
            ]);
            return [];
        }

        $platformKey = $this->getSrsPlatformKey($salesChannelId);
        if (!$platformKey) {
            $this->logger->warning('LoyaltyEngage: SRS Platform Key not configured, skipping voucher check', [
                'barcode' => $barcode,
            ]);
            return [];
        }

        // SRS API uses a dedicated bridge URL, not the main loyalty API URL
        $url = self::SRS_BRIDGE_URL . '/api/srs/v1/voucher-status';

        $payload = [
            'tenant'  => $tenantId,
            'barcode' => $barcode,
        ];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: SRS voucher status check', [
                'url'     => $url,
                'barcode' => $barcode,
                'tenant'  => $tenantId,
            ]);
        }

        try {
            $response   = $this->httpClient->request('POST', $url, [
                'headers' => $this->getSrsHeaders($salesChannelId),
                'json'    => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content    = $response->getContent(false);
            $decoded    = json_decode($content, true);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: SRS voucher status response', [
                    'barcode'    => $barcode,
                    'statusCode' => $statusCode,
                    'response'   => $decoded,
                ]);
            }

            if ($statusCode === self::HTTP_OK && is_array($decoded)) {
                return $decoded;
            }

            $this->logger->warning('LoyaltyEngage: SRS voucher status unexpected response', [
                'barcode'    => $barcode,
                'statusCode' => $statusCode,
                'response'   => $content,
            ]);

            return [];
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: SRS voucher status request failed', [
                'barcode' => $barcode,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Mark a voucher as redeemed in the SRS bridge.
     */
    public function redeemSrsVoucher(string $barcode, ?string $salesChannelId = null): bool
    {
        $tenantId = $this->getSrsTenantId($salesChannelId);
        $platformKey = $this->getSrsPlatformKey($salesChannelId);
        $branchId = $this->getSrsBranchId($salesChannelId);

        if (!$tenantId || !$platformKey || !$branchId) {
            $this->logger->warning('LoyaltyEngage: SRS redeem skipped due to missing configuration', [
                'barcode'     => $barcode,
                'hasTenantId' => !empty($tenantId),
                'hasPlatformKey' => !empty($platformKey),
                'hasBranchId' => !empty($branchId),
            ]);

            return false;
        }

        $url = self::SRS_BRIDGE_URL . '/api/srs/v1/redeem-voucher';
        $payload = [
            'tenant'     => (string) $tenantId,
            'barcode'    => $barcode,
            'filiaal_id' => (string) $branchId,
        ];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: SRS redeem voucher request', [
                'url'        => $url,
                'barcode'    => $barcode,
                'tenant'     => $tenantId,
                'filiaal_id' => $branchId,
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'X-PLATFORM-KEY' => $platformKey,
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $decoded = json_decode($content, true);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: SRS redeem voucher response', [
                    'barcode'     => $barcode,
                    'statusCode'  => $statusCode,
                    'response'    => $decoded,
                ]);
            }

            return $statusCode === self::HTTP_OK || $statusCode === 204;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: SRS redeem voucher request failed', [
                'barcode' => $barcode,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Returns the block reason for a voucher code, or null when the code may be used.
     *
     * Known return values:
     *  - "verlopen"
     *  - "al gebruikt"
     *  - null
     */
    public function getVoucherBlockState(string $barcode): ?string
    {
        $srsResult = $this->checkSrsVoucherStatus($barcode);
        return $this->getVoucherBlockStateFromSrsResult($srsResult);
    }

    /**
     * Resolve the block reason from an SRS result payload.
     *
     * Business rule: only local_redeemed from LoyaltyEngage should block the voucher.
     * SRS status labels like "redeemed" or "expired" are ignored for cart validation.
     */
    public function getVoucherBlockStateFromSrsResult(array $srsResult): ?string
    {
        if (empty($srsResult)) {
            return null;
        }

        $localRedeemed = $srsResult['local_redeemed'] ?? false;

        if ($localRedeemed === true) {
            return 'al gebruikt';
        }

        return null;
    }

    /**
     * Check if Return Export is enabled
     */
    public function isReturnExportEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('LoyaltyEngage.config.returnEvent');
    }

    /**
     * Check if Purchase Export is enabled
     */
    public function isPurchaseExportEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('LoyaltyEngage.config.purchaseEvent');
    }

    /**
     * Check if Points Redemption is enabled
     */
    public function isPointsRedemptionEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('LoyaltyEngage.config.pointsRedemptionEnabled');
    }

    /**
     * Get Discount Product SKU from config
     */
    public function getDiscountProductSku(): ?string
    {
        return $this->systemConfigService->get('LoyaltyEngage.config.discountProductSku');
    }

    /**
     * Get Points per Euro ratio from config
     */
    public function getPointsPerEuro(): int
    {
        return (int) ($this->systemConfigService->get('LoyaltyEngage.config.pointsPerEuro') ?: 1);
    }

    /**
     * Get Minimum Points to Redeem from config
     */
    public function getMinPointsToRedeem(): int
    {
        return (int) ($this->systemConfigService->get('LoyaltyEngage.config.minPointsToRedeem') ?: 1);
    }

    /**
     * Get Maximum Points per Order from config
     */
    public function getMaxPointsPerOrder(): int
    {
        return (int) ($this->systemConfigService->get('LoyaltyEngage.config.maxPointsPerOrder') ?: 0);
    }

    /**
     * Get Maximum Discount Percentage from config
     */
    public function getMaxDiscountPercentage(): int
    {
        return (int) ($this->systemConfigService->get('LoyaltyEngage.config.maxDiscountPercentage') ?: 0);
    }

    /**
     * Get Maximum Loyalty Products per Cart from config.
     * Returns 0 for unlimited.
     */
    public function getMaxLoyaltyProductsPerCart(): int
    {
        return (int) ($this->systemConfigService->get('LoyaltyEngage.config.maxLoyaltyProductsPerCart') ?? 1);
    }

    /**
     * Get the validated and sanitized API base URL.
     * Always returns a URL starting with the required base.
     */
    private function getValidatedApiUrl(): string
    {
        $apiUrl = $this->getApiUrl();

        if (!$apiUrl || strpos($apiUrl, self::REQUIRED_API_BASE) !== 0) {
            if ($apiUrl) {
                $this->logger->warning('LoyaltyEngage: API URL does not match required base, using default', [
                    'configuredUrl' => $apiUrl,
                    'defaultUrl' => self::DEFAULT_API_URL
                ]);
            }
            return self::DEFAULT_API_URL;
        }

        return rtrim($apiUrl, '/');
    }

    /**
     * Generate Basic Auth string
     */
    private function getBasicAuth(?string $salesChannelId = null): string
    {
        $tenantId = $this->getTenantId($salesChannelId);
        $bearerToken = $this->getBearerToken($salesChannelId);

        return base64_encode($tenantId . ':' . $bearerToken);
    }

    /**
     * Get default HTTP headers for API requests
     */
    private function getDefaultHeaders(?string $salesChannelId = null): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->getBasicAuth($salesChannelId),
        ];
    }

    /**
     * Resolve a plugin config value with sales-channel-first fallback.
     */
    private function getConfigValueWithFallback(string $key, ?string $salesChannelId = null)
    {
        if ($salesChannelId) {
            $salesChannelValue = $this->systemConfigService->get($key, $salesChannelId);

            if ($salesChannelValue !== null && $salesChannelValue !== '') {
                return $salesChannelValue;
            }
        }

        return $this->systemConfigService->get($key);
    }

    /**
     * Get default HTTP headers for SRS bridge requests.
     */
    private function getSrsHeaders(?string $salesChannelId = null): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-PLATFORM-KEY' => (string) $this->getSrsPlatformKey($salesChannelId),
        ];
    }

    /**
     * Add a product to the loyalty cart
     */
    public function addToCart(string $email, string $sku, ?string $salesChannelId = null): int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/add';
        $payload = [
            'sku' => $sku,
            'quantity' => 1
        ];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: Add to Cart Request', [
                'url'   => $url,
                'email' => $email,
                'sku'   => $sku,
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Add to Cart Response', [
                    'sku'           => $sku,
                    'response_code' => $statusCode,
                    'response_body' => $content,
                ]);
            }

            return $statusCode;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: Add to Cart Error', [
                'sku'   => $sku,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Remove a product from the loyalty cart
     */
    public function removeItem(string $email, string $sku, int $quantity, ?string $salesChannelId = null): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/remove';
        $data = [
            'sku' => $sku,
            'quantity' => $quantity
        ];

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => $data,
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage: Remove Item Error', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * Remove all products from the loyalty cart
     */
    public function removeAllItems(string $email, ?string $salesChannelId = null): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart';

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage: Remove All Items Error', [
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * Place an order in the loyalty system
     */
    public function placeOrder(string $email, string $orderId, array $products, ?string $salesChannelId = null): ?int
    {
        $result = $this->placeOrderWithResponse($email, $orderId, $products, $salesChannelId);
        return $result['statusCode'] ?? null;
    }

    /**
     * Place an order in the loyalty system and return both status code and response body.
     *
     * @return array{statusCode: int|null, body: string|null}
     */
    public function placeOrderWithResponse(string $email, string $orderId, array $products, ?string $salesChannelId = null): array
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/purchase';
        $data = [
            'orderId'  => $orderId,
            'products' => $products,
        ];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: placeOrder request', [
                'url'     => $url,
                'orderId' => $orderId,
                'payload' => $data,
            ]);
        }

        try {
            $response   = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json'    => $data,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: placeOrder response', [
                    'orderId'    => $orderId,
                    'statusCode' => $statusCode,
                    'body'       => $body,
                ]);
            }

            return ['statusCode' => $statusCode, 'body' => $body];
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: Place Order Error', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            return ['statusCode' => null, 'body' => $e->getMessage()];
        }
    }

    /**
     * Send event to the loyalty system
     */
    public function sendEvent(array $payload, ?string $salesChannelId = null): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/events';

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: Sending event to API', [
                'url'     => $url,
                'payload' => $payload,
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: API event response', [
                    'statusCode' => $statusCode,
                    'content'    => $content,
                ]);
            }

            return $statusCode;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: Send Event Error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Create a voucher through the bridge; never retry through the direct API.
     */
    private function createSrsVoucher(string $email, string $sku, ?string $salesChannelId): ?array
    {
        $tenant = trim((string) $this->getSrsTenantId($salesChannelId));
        if ($tenant === '' || empty($this->getTenantId($salesChannelId)) || empty($this->getBearerToken($salesChannelId))) {
            return ['success' => false, 'message' => 'SRS requires a Tenant ID and Loyalty Engage Client ID and Client Secret.'];
        }

        try {
            $response = $this->httpClient->request('POST', self::SRS_BRIDGE_URL . '/api/srs/v1/create-voucher', [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => ['tenant' => $tenant, 'email' => $email, 'voucher_type' => $sku],
                'timeout' => 35,
                'max_duration' => 35,
            ]);
            $status = $response->getStatusCode();
            $result = json_decode($response->getContent(false), true);
            if ($status < 200 || $status >= 300 || !is_array($result) || ($result['success'] ?? false) !== true) {
                $this->logger->warning('LoyaltyEngage: SRS voucher creation failed', ['status' => $status, 'sku' => $sku]);
                return ['success' => false, 'message' => ($result['error'] ?? null) === 'insufficient_balance'
                    ? 'Onvoldoende punten om deze voucher aan te maken.'
                    : 'De SRS-voucher kon niet worden aangemaakt.'];
            }

            $code = $result['barcode'] ?? $result['discountCode'] ?? $result['code'] ?? null;
            $amount = $result['value'] ?? $result['discountAmount'] ?? null;
            if ((!is_string($code) && !is_int($code)) || (string) $code === '' || !is_numeric($amount) || (float) $amount <= 0) {
                $this->logger->error('LoyaltyEngage: SRS voucher created but response lacks a valid code or amount', ['sku' => $sku]);
                return ['success' => false, 'message' => 'De bridge meldt dat de voucher is aangemaakt, maar geeft geen geldige code of waarde terug. Neem contact op voordat u opnieuw probeert.'];
            }

            $result['discountCode'] = (string) $code;
            $result['discountAmount'] = (float) $amount;
            $result['discountPercentage'] = null;
            return $result;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: SRS voucher request failed', ['sku' => $sku, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'De bridge kon niet worden bereikt. Controleer of de voucher is aangemaakt voordat u opnieuw probeert.'];
        }
    }

    /**
     * Buy a discount code product from the loyalty system
     *
     * @param string $email Customer email/identifier
     * @param string $sku SKU of the discount code product
     * @return array|null Response with discount code info or null on failure
     */
    public function buyDiscountCodeProduct(string $email, string $sku, ?string $salesChannelId = null): ?array
    {
        if ($this->isSrsVoucherCreationEnabled($salesChannelId)) {
            return $this->createSrsVoucher($email, $sku, $salesChannelId);
        }

        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/buy_discount_code';
        $payload = [
            'sku' => $sku
        ];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: Buying discount code product', [
                'sku' => $sku
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Buy Discount Code Response', [
                    'sku' => $sku,
                    'response_code' => $statusCode,
                    'response_body' => $content
                ]);
            }

            if ($statusCode !== self::HTTP_OK) {
                $this->logger->error('LoyaltyEngage: Buy Discount Code failed', [
                    'sku' => $sku,
                    'statusCode' => $statusCode
                ]);
                return null;
            }

            $result = json_decode($content, true);
            return $result ?: ['success' => true, 'statusCode' => $statusCode];
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: Buy Discount Code Error', [
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Buy multiple discount code products (for redeeming multiple points)
     *
     * @param string $email Customer email/identifier
     * @param string $sku SKU of the discount code product
     * @param int $quantity Number of times to purchase the discount product
     * @return array Result with success status and discount codes
     */
    public function buyMultipleDiscountCodeProducts(string $email, string $sku, int $quantity, ?string $salesChannelId = null): array
    {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        $discountCodes = [];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: Buying multiple discount code products', [
                'sku' => $sku,
                'quantity' => $quantity
            ]);
        }

        for ($i = 0; $i < $quantity; $i++) {
            $result = $this->buyDiscountCodeProduct($email, $sku, $salesChannelId);

            if ($result !== null && ($result['success'] ?? true) !== false) {
                $successCount++;
                $results[] = $result;

                if (isset($result['discountCode'])) {
                    $discountCodes[] = $result['discountCode'];
                } elseif (isset($result['code'])) {
                    $discountCodes[] = $result['code'];
                }
            } else {
                $failCount++;
                $this->logger->warning('LoyaltyEngage: Stopping bulk purchase due to failure', [
                    'sku' => $sku,
                    'successCount' => $successCount,
                    'failedAt' => $i + 1,
                    'totalRequested' => $quantity
                ]);
                break;
            }
        }

        return [
            'success' => $failCount === 0 && $successCount === $quantity,
            'successCount' => $successCount,
            'failCount' => $failCount,
            'totalRequested' => $quantity,
            'discountCodes' => $discountCodes,
            'results' => $results
        ];
    }

    /**
     * Claim a discount from the loyalty system
     */
    public function claimDiscount(string $email, float $discount, ?string $salesChannelId = null): ?array
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/discount/' . urlencode($email) . '/claim';
        $payload = ['discount' => $discount];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: Claiming discount', [
                'discount' => $discount
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Discount Claim Response', [
                    'discount' => $discount,
                    'response_code' => $statusCode,
                    'response_body' => $content
                ]);
            }

            if ($statusCode !== self::HTTP_OK) {
                return null;
            }

            return json_decode($content, true);
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage: Claim Discount Error', [
                    'discount' => $discount,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * Mark a discount code as redeemed in the LoyaltyEngage API.
     *
     * Called after a customer successfully places an order using a LoyaltyEngage
     * discount code. This prevents the code from being reused on the LoyaltyEngage
     * side as well.
     *
     * @param string $email       Customer email address
     * @param string $code        The discount code that was redeemed
     * @return bool               True on success, false on failure
     */
    public function redeemDiscountCode(string $email, string $code, ?string $salesChannelId = null): bool
    {
        // PUT /api/v1/discount/{discountCode}/redeem
        // body: { "identifier": "{email}" }
        $url = $this->getValidatedApiUrl() . '/api/v1/discount/' . urlencode($code) . '/redeem';

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage: redeemDiscountCode request', [
                'email' => $email,
                'code'  => $code,
                'url'   => $url,
            ]);
        }

        try {
            $response = $this->httpClient->request('PUT', $url, [
                'headers' => $this->getDefaultHeaders($salesChannelId),
                'json' => [
                    'identifier' => $email,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: redeemDiscountCode response', [
                    'email'         => $email,
                    'code'          => $code,
                    'response_code' => $statusCode,
                ]);
            }

            $success = $statusCode === self::HTTP_OK || $statusCode === 204;

            if ($success) {
                $canRedeemSrs = $this->canRedeemSrsVoucher($salesChannelId);

                if ($this->getLoggerStatus()) {
                    $this->logger->info('LoyaltyEngage: redeemDiscountCode SRS redeem decision', [
                        'email'          => $email,
                        'code'           => $code,
                        'canRedeemSrs'   => $canRedeemSrs,
                        'hasTenantId'    => !empty($this->getSrsTenantId($salesChannelId)),
                        'hasPlatformKey' => !empty($this->getSrsPlatformKey($salesChannelId)),
                        'hasBranchId'    => !empty($this->getSrsBranchId($salesChannelId)),
                    ]);
                }

                if ($canRedeemSrs) {
                    if ($this->getLoggerStatus()) {
                        $this->logger->info('LoyaltyEngage: redeemDiscountCode starting SRS redeem', [
                            'email' => $email,
                            'code'  => $code,
                        ]);
                    }

                    $srsRedeemed = $this->redeemSrsVoucher($code, $salesChannelId);

                    if ($this->getLoggerStatus()) {
                        $this->logger->info('LoyaltyEngage: redeemDiscountCode finished SRS redeem', [
                            'email'       => $email,
                            'code'        => $code,
                            'srsRedeemed' => $srsRedeemed,
                        ]);
                    }

                    if (!$srsRedeemed) {
                        $this->logger->warning('LoyaltyEngage: redeemDiscountCode succeeded but SRS redeem failed', [
                            'email' => $email,
                            'code'  => $code,
                        ]);
                    }
                } else {
                    $this->logger->warning('LoyaltyEngage: redeemDiscountCode skipped SRS redeem because configuration is incomplete', [
                        'email' => $email,
                        'code'  => $code,
                        'hasTenantId'    => !empty($this->getSrsTenantId($salesChannelId)),
                        'hasPlatformKey' => !empty($this->getSrsPlatformKey($salesChannelId)),
                        'hasBranchId'    => !empty($this->getSrsBranchId($salesChannelId)),
                    ]);
                }
            }

            return $success;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->logger->error('LoyaltyEngage: redeemDiscountCode error', [
                'email' => $email,
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
