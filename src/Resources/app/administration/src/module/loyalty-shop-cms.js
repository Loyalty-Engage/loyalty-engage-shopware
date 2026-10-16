const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-loyalty-shop', {
    template: '<div style="padding:24px;border:1px dashed #94a3b8"><h3>Loyalty shop</h3><p>Rewards, points prices and stock from your selected Shopware products.</p><div style="display:flex;gap:16px"><div v-for="n in 3" :key="n" style="padding:24px;background:#f4f6f8;flex:1">Reward<br>100 points</div></div></div>',
    mixins: [Mixin.getByName('cms-element')],
    created() { this.initElementConfig('loyalty-shop'); },
});
Component.register('sw-cms-el-preview-loyalty-shop', {
    template: '<div style="padding:16px"><strong>Loyalty shop</strong><p>Rewards &amp; points</p></div>',
});
Component.register('sw-cms-el-config-loyalty-shop', {
    template: '<div><sw-entity-single-select v-model:value="element.config.category.value" entity="category" label="Limit to category (optional)" :allow-clear="true" /><p>Products are configured in the Loyalty shop custom fields. Without a category, all loyalty rewards of this sales channel are shown.</p></div>',
    mixins: [Mixin.getByName('cms-element')],
    created() { this.initElementConfig('loyalty-shop'); },
});
Shopware.Service('cmsService').registerCmsElement({
    name: 'loyalty-shop', label: 'Loyalty shop', component: 'sw-cms-el-loyalty-shop',
    configComponent: 'sw-cms-el-config-loyalty-shop', previewComponent: 'sw-cms-el-preview-loyalty-shop',
    defaultConfig: { category: { source: 'static', value: null } },
});
Component.register('sw-cms-block-loyalty-shop', { template: '<div><slot name="content"></slot></div>' });
Component.register('sw-cms-preview-loyalty-shop', { template: '<div style="padding:16px">Loyalty shop<br>Rewards &amp; points</div>' });
Shopware.Service('cmsService').registerCmsBlock({
    name: 'loyalty-shop', label: 'Loyalty shop', category: 'commerce',
    component: 'sw-cms-block-loyalty-shop', previewComponent: 'sw-cms-preview-loyalty-shop',
    defaultConfig: { marginBottom: '20px', marginTop: '20px', marginLeft: '0', marginRight: '0', sizingMode: 'boxed' },
    slots: { content: 'loyalty-shop' },
});
