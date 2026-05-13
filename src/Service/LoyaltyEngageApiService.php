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
    public function getTenantId(): ?string
    {
        return $this->systemConfigService->get('LoyaltyEngage.config.tenantId');
    }

    /**
     * Get Bearer Token from config
     */
    public function getBearerToken(): ?string
    {
        return $this->systemConfigService->get('LoyaltyEngage.config.bearerToken');
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
    private function getBasicAuth(): string
    {
        $tenantId = $this->getTenantId();
        $bearerToken = $this->getBearerToken();

        return base64_encode($tenantId . ':' . $bearerToken);
    }

    /**
     * Get default HTTP headers for API requests
     */
    private function getDefaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->getBasicAuth(),
        ];
    }

    /**
     * Add a product to the loyalty cart
     */
    public function addToCart(string $email, string $sku): int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/add';
        $payload = [
            'sku' => $sku,
            'quantity' => 1
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders(),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Add to Cart Response', [
                    'sku' => $sku,
                    'response_code' => $statusCode,
                    'response_body' => $content
                ]);
            }

            return $statusCode;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage: Add to Cart Error', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        }
    }

    /**
     * Remove a product from the loyalty cart
     */
    public function removeItem(string $email, string $sku, int $quantity): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/remove';
        $data = [
            'sku' => $sku,
            'quantity' => $quantity
        ];

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => $this->getDefaultHeaders(),
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
    public function removeAllItems(string $email): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart';

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => $this->getDefaultHeaders(),
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
    public function placeOrder(string $email, string $orderId, array $products): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/loyalty/shop/' . urlencode($email) . '/cart/purchase';
        $data = [
            'orderId' => $orderId,
            'products' => $products
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders(),
                'json' => $data,
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage: Place Order Error', [
                    'orderId' => $orderId,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * Send event to the loyalty system
     */
    public function sendEvent(array $payload): ?int
    {
        $url = $this->getValidatedApiUrl() . '/api/v1/events';

        $this->logger->info('LoyaltyEngage: Sending event to API', [
            'url' => $url,
            'payload' => $payload,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getDefaultHeaders(),
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $this->logger->info('LoyaltyEngage: API event response', [
                'statusCode' => $statusCode,
                'content' => $content
            ]);

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
     * Buy a discount code product from the loyalty system
     *
     * @param string $email Customer email/identifier
     * @param string $sku SKU of the discount code product
     * @return array|null Response with discount code info or null on failure
     */
    public function buyDiscountCodeProduct(string $email, string $sku): ?array
    {
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
                'headers' => $this->getDefaultHeaders(),
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
    public function buyMultipleDiscountCodeProducts(string $email, string $sku, int $quantity): array
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
            $result = $this->buyDiscountCodeProduct($email, $sku);

            if ($result !== null) {
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
    public function claimDiscount(string $email, float $discount): ?array
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
                'headers' => $this->getDefaultHeaders(),
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
    public function redeemDiscountCode(string $email, string $code): bool
    {
        // PUT /api/v1/discount/{discountCode}/redeem
        // body: { "identifier": "{email}" }
        $url = $this->getValidatedApiUrl() . '/api/v1/discount/' . urlencode($code) . '/redeem';

        try {
            $response = $this->httpClient->request('PUT', $url, [
                'headers' => $this->getDefaultHeaders(),
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

            return $statusCode === self::HTTP_OK || $statusCode === 204;
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
