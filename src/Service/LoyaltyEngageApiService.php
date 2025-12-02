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
     * Generate Basic Auth string
     */
    private function getBasicAuth(): string
    {
        $tenantId = $this->getTenantId();
        $bearerToken = $this->getBearerToken();

        return base64_encode($tenantId . ':' . $bearerToken);
    }

    /**
     * Add a product to the loyalty cart
     */
    public function addToCart(string $email, string $sku): int
    {
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/loyalty/shop/' . $email . '/cart/add';
        $payload = [
            'sku' => $sku,
            'quantity' => 1
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            // Log if logging is enabled in config
            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage Add to Cart Response:', [
                    'email' => $email,
                    'sku' => $sku,
                    'quantity' => 1,
                    'response_code' => $statusCode,
                    'response_body' => $content
                ]);
            }

            return $statusCode;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage Add to Cart Error:', [
                    'email' => $email,
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
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/loyalty/shop/' . $email . '/cart/remove';
        $data = [
            'sku' => $sku,
            'quantity' => $quantity
        ];

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
                'json' => $data,
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage Remove Item Error:', [
                    'email' => $email,
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
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/loyalty/shop/' . $email . '/cart';

        try {
            $response = $this->httpClient->request('DELETE', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage Remove All Items Error:', [
                    'email' => $email,
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
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/loyalty/shop/' . $email . '/cart/purchase';
        $data = [
            'orderId' => $orderId,
            'products' => $products
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
                'json' => $data,
            ]);

            return $response->getStatusCode();
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage Place Order Error:', [
                    'email' => $email,
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
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/events';

        // Always log the event being sent for debugging
        $this->logger->info('LoyaltyEngage sending event to API', [
            'url' => $url,
            'payload' => $payload,
            'apiUrl' => $apiUrl,
            'tenantId' => $this->getTenantId(),
            'loggerEnabled' => $this->getLoggerStatus()
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            // Always log the response for debugging
            $this->logger->info('LoyaltyEngage API response', [
                'statusCode' => $statusCode,
                'content' => $content
            ]);

            return $statusCode;
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            // Always log errors for debugging
            $this->logger->error('LoyaltyEngage Send Event Error:', [
                'url' => $url,
                'payload' => $payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Claim a discount from the loyalty system
     */
    public function claimDiscount(string $email, float $discount): ?array
    {
        // Get the base URL from config, or use the default if not set
        $apiUrl = $this->getApiUrl();
        if (!$apiUrl) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL not set in config, using default', [
                'defaultUrl' => $apiUrl
            ]);
        }
        
        // Ensure the URL starts with https://app.loyaltyengage.tech
        if (strpos($apiUrl, 'https://app.loyaltyengage.tech') !== 0) {
            $apiUrl = 'https://app.loyaltyengage.tech';
            $this->logger->warning('LoyaltyEngage API URL does not start with the required base URL, using default', [
                'configuredUrl' => $this->getApiUrl(),
                'defaultUrl' => $apiUrl
            ]);
        }
        
        $url = rtrim($apiUrl, '/') . '/api/v1/discount/' . $email . '/claim';
        $payload = ['discount' => $discount];

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage claiming discount', [
                'url' => $url,
                'email' => $email,
                'discount' => $discount
            ]);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->getBasicAuth(),
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($this->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage Discount Claim Response:', [
                    'email' => $email,
                    'discount' => $discount,
                    'response_code' => $statusCode,
                    'response_body' => $content
                ]);
            }

            if ($statusCode !== 200) {
                return null;
            }

            return json_decode($content, true);
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            if ($this->getLoggerStatus()) {
                $this->logger->error('LoyaltyEngage Claim Discount Error:', [
                    'email' => $email,
                    'discount' => $discount,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }
}
