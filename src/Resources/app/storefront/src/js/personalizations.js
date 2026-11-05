const KEY = /^[a-z][a-z0-9_-]{0,63}$/;
const TENANT = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;

export class Personalizations {
    constructor() {
        this.ids = new WeakMap();
        this.sent = new WeakMap();
        this.subscribed = new WeakSet();
        this.counter = 0;
        this.revision = 0;
        this.schedule = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.scan(), 100);
        };
        this.refresh = () => {
            this.revision += 1;
            this.log('context changed');
            this.schedule();
        };
        this.observer = new MutationObserver(this.schedule);
        this.observer.observe(document.body, { childList: true, subtree: true, attributes: true,
            attributeFilter: ['data-le-personalization-config', 'data-le-personalization', 'hidden', 'class', 'style'] });
        if (window.IntersectionObserver) {
            this.visibility = new IntersectionObserver(this.schedule);
        }
        document.addEventListener('loyaltyengage:context-changed', this.refresh);
        window.addEventListener('popstate', this.refresh);
        window.addEventListener('pageshow', this.refresh);
        document.addEventListener('change', event => {
            if (event.target.matches('[name="quantity"], [name^="lineItems["][name$="[quantity]"]')) this.refresh();
        });
        this.scan();
    }

    log(message, rule, target) {
        if (this.config?.debug) console.debug('[LoyaltyEngage personalizations]', message, { key: rule?.key, target });
    }

    readConfig() {
        const element = document.querySelector('[data-le-personalization-config]');
        if (!element) return null;
        try {
            const config = JSON.parse(element.dataset.lePersonalizationConfig);
            if (!TENANT.test(config.tenant) || !Array.isArray(config.rules)) return null;
            const url = new URL(config.contextUrl, window.location.href);
            if (url.origin !== window.location.origin) return null;
            return config;
        } catch (_) { return null; }
    }

    subscribeCart() {
        ['OffCanvasCart', 'AddToCart', 'VariantSwitch'].forEach(name => {
            let instances;
            try { instances = window.PluginManager?.getPluginInstances(name); } catch (_) { return; }
            if (!instances) return;
            instances.forEach(instance => {
                if (this.subscribed.has(instance)) return;
                this.subscribed.add(instance);
                ['offCanvasOpened', 'fetchCartWidgets', 'openOffCanvasCart', 'onChange'].forEach(event => {
                    instance.$emitter?.subscribe(event, this.refresh);
                });
            });
        });
    }

    targets() {
        const rules = new Map(this.config.rules.filter(rule => rule.enabled && KEY.test(rule.key)).map(rule => [rule.key, rule]));
        const targets = [];
        document.querySelectorAll('[data-le-personalization]').forEach(element => {
            const rule = rules.get(element.dataset.lePersonalization);
            if (!rule) return;
            const automatic = (element.dataset.lePersonalizationPlacement ||
                (element.id.startsWith('le-personalization-auto-') ? 'automatic' : 'manual')) === 'automatic';
            if ((rule.placement === 'automatic') !== automatic) return;
            if (automatic && rule.page_scope !== this.config.scope) return;
            if (automatic && rule.page_scope === 'pages' && !rule.pages?.includes(this.config.cmsPage)) return;
            element.dataset.lePersonalizationPlacement = automatic ? 'automatic' : 'manual';
            if (!this.ids.has(element)) {
                this.ids.set(element, ++this.counter);
                this.visibility?.observe(element);
            }
            // Empty targets have no height; width and computed visibility still distinguish hidden blocks.
            if (!element.isConnected || !element.getClientRects().length || getComputedStyle(element).visibility === 'hidden') return;
            if (!element.id || document.getElementById(element.id) !== element) {
                element.id = `le-personalization-runtime-${this.ids.get(element)}`;
            }
            targets.push({ element, rule, selector: `#${CSS.escape(element.id)}` });
            this.log('target found', rule, `#${element.id}`);
        });
        return targets;
    }

    ensureLoader() {
        const src = `https://le-cloud.app/f/t/${this.config.tenant}.js`;
        if (window.__lePersonalizationTenant && window.__lePersonalizationTenant !== this.config.tenant) {
            this.log('tenant changed; full page reload required');
            return false;
        }
        window._lecloud = window._lecloud || { event: [], personalization: [] };
        if (!window._lecloud.personalization || typeof window._lecloud.personalization.push !== 'function') return false;
        window._lecloud.event = window._lecloud.event || [];
        const loaders = [...document.querySelectorAll('script[src]')].filter(script => script.src.startsWith('https://le-cloud.app/f/t/'));
        if (loaders.some(script => script.src !== src)) return false;
        window.__lePersonalizationTenant = this.config.tenant;
        if (!loaders.length) {
            const script = document.createElement('script');
            script.type = 'module';
            script.async = true;
            script.src = src;
            script.addEventListener('error', () => this.log('loader failed (network or CSP)'));
            document.head.appendChild(script);
            this.log('loader started');
        }
        return true;
    }

    async scan() {
        this.config = this.readConfig();
        if (!this.config || !this.config.rules.length) {
            this.abort?.abort();
            this.fingerprint = null;
            return;
        }
        this.subscribeCart();
        const targets = this.targets();
        const quantity = document.querySelector('form[data-add-to-cart] [name$="[quantity]"], .product-detail-buy [name="quantity"]')?.value || '1';
        const product = document.querySelector('form[data-add-to-cart] [name$="[id]"]')?.value || this.config.product;
        const fingerprint = JSON.stringify([this.config, product, quantity, this.revision,
            targets.map(item => [this.ids.get(item.element), item.rule.key, item.selector])]);
        if (fingerprint === this.fingerprint) return;
        this.fingerprint = fingerprint;
        this.abort?.abort();
        this.abort = new AbortController();
        const signal = this.abort.signal;
        if (!targets.length || !this.ensureLoader()) return;
        // Independent public rules must keep working if session-context loading fails.
        targets.filter(item => !item.rule.personalized && !item.rule.use_cart_value)
            .forEach(item => this.pushTarget(item, {}));
        let context = {};
        const keys = [...new Set(targets.filter(item => item.rule.personalized || item.rule.use_cart_value).map(item => item.rule.key))];
        if (keys.length) {
            const timeout = setTimeout(() => this.abort?.signal === signal && this.abort.abort(), 10000);
            try {
                const url = new URL(this.config.contextUrl, window.location.href);
                url.searchParams.set('keys', keys.join(','));
                url.searchParams.set('scope', this.config.scope);
                url.searchParams.set('product', product || '');
                url.searchParams.set('quantity', quantity);
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('context unavailable');
                context = (await response.json()).context || {};
            } catch (error) {
                if (error.name !== 'AbortError') this.log('context unavailable; trigger skipped');
                return;
            } finally {
                clearTimeout(timeout);
            }
        }
        if (signal.aborted) return;
        targets.filter(item => item.rule.personalized || item.rule.use_cart_value)
            .forEach(item => {
                const data = context[item.rule.key];
                if (Object.prototype.hasOwnProperty.call(context, item.rule.key) && data && typeof data === 'object') {
                    this.pushTarget(item, data);
                }
            });
    }

    pushTarget({ element, rule, selector }, data) {
        if (!element.isConnected) return;
        const trigger = { id: rule.public_id, target: selector };
        if (rule.personalized && typeof data.identifier === 'string' && data.identifier) trigger.identifier = data.identifier;
        if (rule.use_cart_value && typeof data.cartValue === 'number' && Number.isFinite(data.cartValue) && data.cartValue >= 0) trigger.cartValue = data.cartValue;
        const signature = JSON.stringify(trigger);
        if (this.sent.get(element) === signature) return;
        try {
            window._lecloud.personalization.push(trigger);
            this.sent.set(element, signature);
            this.log('event pushed', rule, selector);
        } catch (_) {
            this.log('trigger rejected by loader', rule, selector);
        }
    }
}

export function startPersonalizations() {
    const start = () => {
        if (!window.__lePersonalizations) window.__lePersonalizations = new Personalizations();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
