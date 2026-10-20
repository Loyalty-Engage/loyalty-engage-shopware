<?php declare(strict_types=1);

namespace LoyaltyEngage\Cms;

use LoyaltyEngage\Service\LoyaltyProductCatalog;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;

class LoyaltyShopCmsResolver extends AbstractCmsElementResolver
{
    public function __construct(private readonly LoyaltyProductCatalog $catalog, private readonly LoyaltyShopConfig $config) {}

    public function getType(): string { return 'loyalty-shop'; }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection { return null; }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $context = $resolverContext->getSalesChannelContext();
        $request = $resolverContext->getRequest();
        $category = $slot->getFieldConfig()->get('category')?->getValue();
        $data = $this->catalog->listing($context, max(1, $request->query->getInt('lePage', 1)), $request->query->get('leSort', 'name'), $category ?: null);
        $data['pointsName'] = $this->config->get('shopPointsName', $context->getSalesChannelId(), 'Punten');
        $data['enabled'] = $this->config->enabled($context->getSalesChannelId());
        $slot->setData(new ArrayStruct($data));
    }
}
