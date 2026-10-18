<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class LoyaltyProductCatalog
{
    private array $locales = [];
    public function __construct(
        private readonly EntityRepository $products,
        private readonly EntityRepository $languages,
        private readonly LoyaltyShopConfig $config,
        private readonly LoggerInterface $logger,
        private readonly ?LoyaltyFeedReader $feed = null
    ) {}

    public function criteria(SalesChannelContext $context, ?string $categoryId = null): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), 10));
        if ($this->usesFeed($context->getSalesChannelId())) {
            $skus = array_keys($this->feedProducts($context->getSalesChannelId()));
            $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus ?: ['__NO_LOYALTY_REWARDS__']));
        } else {
            $criteria->addFilter(new EqualsFilter('customFields.le_shop_enabled', true));
        }
        $criteria->addFilter(new EqualsFilter('childCount', 0));
        if ($categoryId) {
            $criteria->addFilter(new EqualsFilter('categoryTree', $categoryId));
        }
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('deliveryTime');
        $criteria->addSorting(new FieldSorting('productNumber'));
        return $criteria;
    }

    public function usesFeed(string $channel): bool
    {
        return $this->config->get('shopProductSource', $channel, 'shopware') === 'loyalty_feed';
    }

    public function feedProducts(string $channel): array
    {
        if ($this->feed === null) { throw new LoyaltyFeedUnavailable('De loyaltyfeed is niet geconfigureerd.'); }
        return $this->feed->products($channel);
    }

    public function fields(ProductEntity $product, ?string $channel = null): array
    {
        $fields = $product->getTranslation('customFields') ?? $product->getCustomFields() ?? [];
        if ($channel !== null && $this->usesFeed($channel)) {
            $reward = $this->feedProducts($channel)[$product->getProductNumber()] ?? null;
            return array_replace($fields, [
                'le_shop_enabled' => $reward !== null,
                'le_shop_coin_price' => $reward['coin_price'] ?? null,
                'le_shop_type' => $reward['type'] ?? null,
                'le_shop_tier_id' => $reward['minimum_required_tier_id'] ?? null,
                'le_shop_discount_type' => ($reward['discount_percentage'] ?? null) !== null ? 'percentage' : 'absolute',
                'le_shop_discount_value' => $reward['discount_percentage'] ?? $reward['discount_amount'] ?? null,
                'le_shop_discount_currency' => $reward['discount_currency'] ?? null,
            ]);
        }
        return $fields;
    }

    public function validationError(ProductEntity $product, string $channel): ?string
    {
        $fields = $this->fields($product, $channel);
        if (empty($fields['le_shop_enabled'])) {
            return 'Dit product is geen loyaltybeloning meer.';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,255}$/D', $product->getProductNumber())) {
            return 'Het loyaltyproduct heeft een ongeldige SKU.';
        }
        if (empty($product->getTranslation('name') ?? $product->getName())) {
            return 'Het loyaltyproduct heeft geen naam.';
        }
        if (filter_var($fields['le_shop_coin_price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return 'Het loyaltyproduct heeft geen geldige puntenprijs.';
        }
        if (!in_array($fields['le_shop_type'] ?? 'physical', ['physical', 'discount_code'], true)) {
            return 'Het loyaltyproduct heeft een ongeldig beloningstype.';
        }
        if ($this->config->get('shopTiersEnabled', $channel, false)
            && (!$this->usesFeed($channel) || $fields['le_shop_tier_id'] !== null)
            && !isset($this->config->tiers($channel)[(int) ($fields['le_shop_tier_id'] ?? 0)])) {
            return 'Het loyaltyproduct heeft geen geconfigureerd minimaal tier.';
        }
        return null;
    }

    public function find(string $sku, SalesChannelContext $context): ?ProductEntity
    {
        $criteria = $this->criteria($context);
        $criteria->addFilter(new EqualsFilter('productNumber', $sku));
        $product = $this->products->search($criteria, $context->getContext())->first();
        if (!$product && $this->usesFeed($context->getSalesChannelId())) {
            $reward = $this->feedProducts($context->getSalesChannelId())[$sku] ?? null;
            if (($reward['type'] ?? null) === 'discount_code') { return $this->voucher($reward, $context->getSalesChannelId()); }
        }
        return $product;
    }

    private function voucher(array $reward, string $channel): ProductEntity
    {
        $presentations = json_decode((string) $this->config->get('shopVoucherPresentation', $channel, '{}'), true);
        $presentation = is_array($presentations) && is_array($presentations[$reward['sku']] ?? null) ? $presentations[$reward['sku']] : [];
        $title = $presentation['title'] ?? $reward['title'] ?? ($reward['discount_percentage'] !== null
            ? $reward['discount_percentage'] . '% korting' : $reward['discount_amount'] . ' ' . $reward['discount_currency'] . ' korting');
        $product = new ProductEntity();
        $product->setId(md5('le-voucher-' . $channel . $reward['sku']));
        $product->setProductNumber($reward['sku']);
        $product->setName(is_string($title) ? mb_substr($title, 0, 255) : $reward['sku']);
        $description = $presentation['description'] ?? $reward['short_description'] ?? '';
        $product->setTranslated(['name' => $product->getName(), 'description' => is_string($description) ? $description : '']);
        $product->setCustomFields([]);
        $image = $presentation['image'] ?? $reward['image_link'] ?? null;
        if (!is_string($image) || !filter_var($image, FILTER_VALIDATE_URL) || !in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true)) { $image = null; }
        $product->addExtension('leVirtualReward', new ArrayStruct(['image' => $image]));
        return $product;
    }

    /** Page valid products consistently; never filter the feed by stock. */
    public function all(SalesChannelContext $context, ?string $categoryId = null): \Generator
    {
        $criteria = $this->criteria($context, $categoryId);
        $criteria->setLimit(100);
        $offset = 0;
        $seen = [];
        do {
            $criteria->setOffset($offset);
            $batch = $this->products->search($criteria, $context->getContext());
            foreach ($batch as $product) {
                $error = $this->validationError($product, $context->getSalesChannelId());
                if ($error !== null) {
                    $this->logger->warning('Loyalty shop: product excluded', ['sku' => $product->getProductNumber(), 'reason' => $error]);
                    continue;
                }
                $seen[$product->getProductNumber()] = true;
                yield $product;
            }
            $offset += 100;
        } while ($batch->count() === 100);
        if (!$categoryId && $this->usesFeed($context->getSalesChannelId())) {
            foreach ($this->feedProducts($context->getSalesChannelId()) as $reward) {
                if ($reward['type'] === 'discount_code' && !isset($seen[$reward['sku']])) {
                    $product = $this->voucher($reward, $context->getSalesChannelId());
                    if ($this->validationError($product, $context->getSalesChannelId()) === null) { yield $product; }
                }
            }
        }
    }

    public function row(ProductEntity $product, SalesChannelContext $context): array
    {
        $fields = $this->fields($product, $context->getSalesChannelId());
        $price = $product->getPrice()?->getCurrencyPrice($context->getCurrencyId());
        // Shopware's default currency amount is converted when no explicit price exists.
        $factor = $price && $price->getCurrencyId() !== $context->getCurrencyId() ? $context->getCurrency()->getFactor() : 1;
        if (!isset($this->locales[$context->getLanguageId()])) {
            $languageCriteria = (new Criteria([$context->getLanguageId()]))->addAssociation('locale');
            $this->locales[$context->getLanguageId()] = $this->languages->search($languageCriteria, $context->getContext())->first()?->getLocale()?->getCode() ?? 'en-GB';
        }
        $locale = $this->locales[$context->getLanguageId()];
        $image = $product->getCover()?->getMedia()?->getUrl() ?? $product->getExtension('leVirtualReward')?->get('image');
        return [
            'id' => $product->getProductNumber(),
            'title' => mb_substr((string) ($product->getTranslation('name') ?? $product->getName()), 0, 255),
            'price' => number_format(($price?->getGross() ?? 0) * $factor, 2, '.', ''),
            'short_description' => mb_substr(trim(html_entity_decode(strip_tags((string) ($product->getTranslation('description') ?? $product->getDescription())), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 2048),
            'image_link' => $image ? mb_substr($image, 0, 2048) : [],
            'language' => str_replace('-', '_', $locale),
            'type' => $fields['le_shop_type'] ?? 'physical',
            'coin_price' => (string) $fields['le_shop_coin_price'],
            'minimum_required_tier_id' => $this->config->get('shopTiersEnabled', $context->getSalesChannelId(), false)
                ? ($fields['le_shop_tier_id'] !== null ? (string) $fields['le_shop_tier_id'] : null) : null,
        ];
    }

    public function listing(SalesChannelContext $context, int $page = 1, string $sort = 'name', ?string $categoryId = null): array
    {
        if (!$this->config->enabled($context->getSalesChannelId())) {
            return ['products' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $products = [];
        try {
            foreach ($this->all($context, $categoryId) as $product) {
                $row = $this->row($product, $context);
                $row['productId'] = $product->getId();
                $row['entity'] = $product;
                $row['available'] = $row['type'] === 'discount_code' || !$product->getIsCloseout() || $product->getAvailableStock() >= ($product->getMinPurchase() ?? 1);
                $products[] = $row;
            }
        } catch (LoyaltyFeedUnavailable $exception) {
            return ['products' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'error' => $exception->getMessage()];
        }
        usort($products, static function (array $a, array $b) use ($sort): int {
            $comparison = $sort === 'points' ? (int) $a['coin_price'] <=> (int) $b['coin_price'] : strnatcasecmp($a['title'], $b['title']);
            return $comparison ?: strcmp($a['id'], $b['id']);
        });
        $pages = max(1, (int) ceil(count($products) / 24));
        $page = max(1, min($pages, $page));
        return ['products' => array_slice($products, ($page - 1) * 24, 24), 'total' => count($products), 'page' => $page, 'pages' => $pages];
    }
}
