const { Component, Mixin } = Shopware;

Component.register('le-personalization-rules', {
    inject: ['repositoryFactory'],
    model: { prop: 'value', event: 'change' },
    props: {
        value: { required: false, default: undefined },
        modelValue: { required: false, default: undefined },
        disabled: { type: Boolean, default: false },
    },
    emits: ['change', 'update:value', 'update:modelValue'],
    computed: {
        pageRepository() { return this.repositoryFactory.create('cms_page'); },
        rules() {
            const value = this.value !== undefined ? this.value : this.modelValue;
            return Array.isArray(value) ? value : [];
        },
    },
    methods: {
        emitRules(rules) {
            this.$emit('change', rules);
            this.$emit('update:value', rules);
            this.$emit('update:modelValue', rules);
        },
        update(index, field, value) {
            this.emitRules(this.rules.map((rule, i) => i === index ? { ...rule, [field]: value } : rule));
        },
        add() {
            let number = this.rules.length + 1;
            while (this.rules.some(rule => rule.key === `personalization-${number}`)) number += 1;
            this.emitRules([...this.rules, { key: `personalization-${number}`, public_id: '', enabled: false,
                placement: 'manual', page_scope: 'product', pages: [], personalized: false, use_cart_value: false }]);
        },
        remove(index) { this.emitRules(this.rules.filter((_, i) => i !== index)); },
    },
    template: `
        <div class="le-personalization-rules">
            <p>Frontend personalizations. Add the Public ID from Loyalty Engage, not the tenant UUID.
                Automatic placements appear above the page content. For precise placement, use the CMS element.</p>
            <fieldset v-for="(rule, index) in rules" :key="index" :disabled="disabled"
                style="padding:20px;margin:16px 0;border:1px solid #d1d9e0;border-radius:4px">
                <legend>Personalization {{ index + 1 }}</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <label>Unique key (lowercase)<input type="text" :value="rule.key" maxlength="64"
                        style="display:block;width:100%;padding:10px" @input="update(index, 'key', $event.target.value)"></label>
                    <label>Public ID (e.g. bp-12)<input type="text" :value="rule.public_id" maxlength="128"
                        style="display:block;width:100%;padding:10px" @input="update(index, 'public_id', $event.target.value)"></label>
                    <label>Placement<select :value="rule.placement" style="display:block;width:100%;padding:10px"
                        @change="update(index, 'placement', $event.target.value)">
                        <option value="manual">Manual (CMS element)</option><option value="automatic">Automatic</option>
                    </select></label>
                    <label v-if="rule.placement === 'automatic'">Page scope<select :value="rule.page_scope"
                        style="display:block;width:100%;padding:10px" @change="update(index, 'page_scope', $event.target.value)">
                        <option value="product">Product detail</option><option value="cart">Cart</option>
                        <option value="checkout">Checkout (register / confirm)</option><option value="pages">Selected CMS pages</option>
                    </select></label>
                </div>
                <sw-entity-multi-id-select v-if="rule.placement === 'automatic' && rule.page_scope === 'pages'"
                    :repository="pageRepository" :ids="rule.pages" :value="rule.pages" label="Selected CMS pages" :disabled="disabled"
                    @change="update(index, 'pages', $event)" @update:value="update(index, 'pages', $event)" />
                <div style="display:flex;flex-wrap:wrap;gap:20px;margin:16px 0">
                    <label><input type="checkbox" :checked="rule.enabled" @change="update(index, 'enabled', $event.target.checked)"> Enabled</label>
                    <label><input type="checkbox" :checked="rule.personalized" @change="update(index, 'personalized', $event.target.checked)"> Send logged-in customer identifier</label>
                    <label><input type="checkbox" :checked="rule.use_cart_value" @change="update(index, 'use_cart_value', $event.target.checked)"> Send product / cart value</label>
                </div>
                <p v-if="rule.placement === 'manual'">CMS element: <strong>Loyalty Engage personalization</strong>, key: <code>{{ rule.key }}</code></p>
                <button type="button" :disabled="disabled" @click="remove(index)">Remove personalization</button>
            </fieldset>
            <button type="button" :disabled="disabled || rules.length >= 100" @click="add">Add personalization</button>
            <p>Use “replace content” in Loyalty Engage for targets that update. No custom scripts are stored here.</p>
        </div>`,
});

Component.register('sw-cms-el-le-personalization', {
    template: '<div style="padding:24px;border:1px dashed #94a3b8"><strong>Loyalty Engage personalization</strong><p>{{ element.config.personalizationKey.value || "Select a configured personalization key" }}</p><small>Content is rendered by Loyalty Engage in the storefront, not in the editor.</small></div>',
    mixins: [Mixin.getByName('cms-element')],
    created() { this.initElementConfig('le-personalization'); },
});
Component.register('sw-cms-el-preview-le-personalization', {
    template: '<div style="padding:16px">Loyalty Engage<br>Personalization</div>',
});
Component.register('sw-cms-el-config-le-personalization', {
    template: '<div><label>Personalization key<input type="text" :value="element.config.personalizationKey.value" style="display:block;width:100%;padding:10px" @input="element.config.personalizationKey.value = $event.target.value"></label><p>Copy the key from the plugin settings. Only enabled manual personalizations are rendered. Each CMS slot gets a unique target.</p></div>',
    mixins: [Mixin.getByName('cms-element')],
    created() { this.initElementConfig('le-personalization'); },
});
Shopware.Service('cmsService').registerCmsElement({
    name: 'le-personalization', label: 'Loyalty Engage personalization',
    component: 'sw-cms-el-le-personalization', configComponent: 'sw-cms-el-config-le-personalization',
    previewComponent: 'sw-cms-el-preview-le-personalization',
    defaultConfig: { personalizationKey: { source: 'static', value: '' } },
});
Component.register('sw-cms-block-le-personalization', { template: '<div><slot name="content"></slot></div>' });
Component.register('sw-cms-preview-le-personalization', { template: '<div style="padding:16px">Loyalty Engage personalization</div>' });
Shopware.Service('cmsService').registerCmsBlock({
    name: 'le-personalization', label: 'Loyalty Engage personalization', category: 'commerce',
    component: 'sw-cms-block-le-personalization', previewComponent: 'sw-cms-preview-le-personalization',
    defaultConfig: { marginBottom: '20px', marginTop: '20px', marginLeft: '0', marginRight: '0', sizingMode: 'boxed' },
    slots: { content: 'le-personalization' },
});
