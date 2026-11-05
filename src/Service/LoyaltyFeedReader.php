<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LoyaltyFeedReader
{
    private array $memo = [];

    public function __construct(
        private readonly LoyaltyShopConfig $config,
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger
    ) {}

    public function products(string $channel, bool $refresh = false): array
    {
        $url = trim((string) $this->config->get('shopSourceFeedUrl', $channel, ''));
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'app.loyaltyengage.tech'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443) || !str_starts_with($parts['path'] ?? '', '/feed/v1/')) {
            throw new LoyaltyFeedUnavailable('Configureer een geldige HTTPS-feed-URL van app.loyaltyengage.tech.');
        }
        $key = 'le_source_' . hash('sha256', $channel . $url);
        if ($refresh) {
            unset($this->memo[$key]);
            $this->cache->deleteItem($key);
        }
        if (isset($this->memo[$key]) && $this->memo[$key]['expires'] > time()) { return $this->memo[$key]['products']; }
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $snapshot = $item->get();
            if (is_array($snapshot) && ($snapshot['expires'] ?? 0) > time()) {
                $this->memo[$key] = $snapshot;
                return $snapshot['products'];
            }
        }
        $status = null;
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/x-ndjson, application/json'],
                'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) { throw new \RuntimeException('Feed returned a non-200 status.'); }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) { throw new \RuntimeException('Feed download timed out.'); }
                $body .= $chunk->getContent();
                if (strlen($body) > 8 * 1024 * 1024) { $response->cancel(); throw new \RuntimeException('Feed exceeds 8 MB.'); }
            }
            $products = $this->parse($body);
            $ttl = max(60, min(3600, (int) $this->config->get('shopSourceFeedTtl', $channel, 300)));
            $snapshot = ['products' => $products, 'expires' => time() + $ttl];
            $item->set($snapshot)->expiresAfter($ttl);
            $this->cache->save($item);
            $this->memo[$key] = $snapshot;
            return $products;
        } catch (\Throwable $exception) {
            // The URL contains a private feed identifier. Never log it or raw transport errors.
            $this->logger->error('Loyalty shop: source feed could not be loaded', ['salesChannelId' => $channel, 'status' => $status, 'exceptionType' => get_class($exception)]);
            throw new LoyaltyFeedUnavailable('De loyaltybeloningen zijn tijdelijk niet beschikbaar. Probeer het later opnieuw.', 0, $exception);
        }
    }

    public function parse(string $body): array
    {
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body));
        if ($body === '') { return []; }
        try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { $decoded = null; }
        if (is_array($decoded)) {
            if (isset($decoded['sku'])) { $rows = [$decoded]; }
            elseif (isset($decoded['products']) && is_array($decoded['products']) && array_is_list($decoded['products'])) { $rows = $decoded['products']; }
            elseif (array_is_list($decoded)) { $rows = $decoded; }
            else { throw new \UnexpectedValueException('Unknown feed document shape.'); }
        } else {
            $rows = [];
            foreach (preg_split('/\R/', $body) as $line) {
                if (trim($line) !== '') { $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR); }
            }
        }
        if (count($rows) > 20000) { throw new \UnexpectedValueException('Too many feed rows.'); }
        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['sku'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/D', $row['sku'])
                || !in_array($row['type'] ?? null, ['physical', 'discount_code'], true)
                || (!is_int($row['coin_price'] ?? null) && !is_string($row['coin_price'] ?? null))
                || filter_var($row['coin_price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \UnexpectedValueException('Invalid reward SKU, type or coin price.');
            }
            $row['coin_price'] = (int) $row['coin_price'];
            $row += ['discount_percentage' => null, 'discount_amount' => null, 'discount_currency' => null, 'minimum_required_tier_id' => null];
            if ($row['minimum_required_tier_id'] !== null && filter_var($row['minimum_required_tier_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \UnexpectedValueException('Invalid tier ID.');
            }
            if ($row['type'] === 'discount_code') {
                $percentage = $row['discount_percentage'];
                $amount = $row['discount_amount'];
                if (($percentage === null) === ($amount === null)) { throw new \UnexpectedValueException('A voucher needs exactly one discount value.'); }
                if ($percentage !== null && (!is_numeric($percentage) || !is_finite((float) $percentage) || $percentage <= 0 || $percentage > 100)) {
                    throw new \UnexpectedValueException('Invalid discount percentage.');
                }
                if ($amount !== null && (!is_numeric($amount) || !is_finite((float) $amount) || $amount <= 0 || !is_string($row['discount_currency']) || !preg_match('/^[A-Z]{3}$/D', $row['discount_currency']))) {
                    throw new \UnexpectedValueException('Invalid discount amount or currency.');
                }
            }
            if (isset($products[$row['sku']]) && $products[$row['sku']] !== $row) { throw new \UnexpectedValueException('Conflicting duplicate SKU.'); }
            $products[$row['sku']] = $row;
        }
        return $products;
    }
}
