<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;

class ProductFeedService
{
    private EntityRepository $productRepository;
    private LoggerInterface $logger;

    private const BATCH_SIZE = 100;

    public function __construct(
        EntityRepository $productRepository,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->logger            = $logger;
    }

    public function streamBatch(Criteria $criteria, Context $context, string $language): int
    {
        $result   = $this->productRepository->search($criteria, $context);
        $products = $result->getEntities();

        foreach ($products as $product) {
            $row = $this->mapProduct($product, $language);
            if ($row !== null) {
                echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                flush();
            }
        }

        return $products->count();
    }

    private function mapProduct(ProductEntity $product, string $language): ?array
    {
        $sku = $product->getProductNumber();
        if ($sku === null || $sku === '') {
            return null;
        }

        $price = 0.0;
        $prices = $product->getPrice();
        if ($prices !== null) {
            $first = $prices->first();
            if ($first !== null) {
                $price = $first->getGross();
            }
        }

        $title = $product->getTranslation('name') ?? $product->getName() ?? '';

        $rawDescription = $product->getTranslation('description') ?? $product->getDescription() ?? '';
        $shortDescription = mb_substr(strip_tags((string) $rawDescription), 0, 2048);

        $imageLinks = [];
        $cover = $product->getCover();
        if ($cover !== null) {
            $media = $cover->getMedia();
            if ($media !== null && $media->getUrl() !== null) {
                $imageLinks[] = $media->getUrl();
            }
        }

        $tags = [];
        $tagCollection = $product->getTags();
        if ($tagCollection !== null) {
            foreach ($tagCollection as $tag) {
                $tagName = $tag->getName();
                if ($tagName !== null && $tagName !== '') {
                    $tags[] = mb_substr($tagName, 0, 255);
                }
            }
        }

        $customFields = $product->getCustomFields() ?? [];

        $minimumTierId = $customFields['loyalty_minimum_tier_id'] ?? null;
        if ($minimumTierId !== null) {
            $minimumTierId = (int) $minimumTierId > 0 ? (string)(int)$minimumTierId : null;
        }

        $coinPrice = $customFields['loyalty_coin_price'] ?? null;
        if ($coinPrice !== null) {
            $coinPrice = (int) $coinPrice > 0 ? (string)(int)$coinPrice : null;
        }

        return [
            'id'                       => mb_substr($sku, 0, 255),
            'title'                    => mb_substr((string) $title, 0, 255),
            'price'                    => number_format($price, 2, '.', ''),
            'short_description'        => $shortDescription,
            'image_link'               => count($imageLinks) === 1 ? $imageLinks[0] : $imageLinks,
            'tag'                      => $tags,
            'language'                 => $language,
            'type'                     => 'physical',
            'minimum_required_tier_id' => $minimumTierId,
            'coin_price'               => $coinPrice,
        ];
    }
}
