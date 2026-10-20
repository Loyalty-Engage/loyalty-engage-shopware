<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class LoyaltyProductFeed
{
    public function __construct(
        private readonly LoyaltyProductCatalog $catalog,
        private readonly LoyaltyShopConfig $config,
        private readonly AbstractSalesChannelContextFactory $contexts,
        private readonly EntityRepository $channels
    ) {}

    public function rows(SalesChannelContext $visitor): \Generator
    {
        $channel = $this->channels->search((new Criteria([$visitor->getSalesChannelId()]))->addAssociation('languages'), $visitor->getContext())->first();
        $options = [
            'languageId' => $channel->getLanguageId(),
            'currencyId' => $this->config->get('shopFeedCurrencyId', $channel->getId(), $channel->getCurrencyId()),
        ];
        // Never derive feed prices or language from a visiting customer's cookie.
        $baseContext = $this->contexts->create(Uuid::randomHex(), $channel->getId(), $options);
        $languages = [];
        foreach ($channel->getLanguages() ?? [] as $language) {
            if ($language->getId() === $options['languageId']) { continue; }
            $languages[] = $this->contexts->create(Uuid::randomHex(), $channel->getId(), array_merge($options, ['languageId' => $language->getId()]));
        }
        foreach ($this->catalog->all($baseContext) as $product) {
            $baseRow = $this->catalog->row($product, $baseContext);
            yield $baseRow;
            foreach ($languages as $context) {
                $translation = $this->catalog->find($product->getProductNumber(), $context);
                if (!$translation) { continue; }
                $translated = $this->catalog->row($translation, $context);
                yield array_replace($baseRow, array_intersect_key($translated, array_flip(['language', 'title', 'short_description'])));
            }
        }
    }
}
