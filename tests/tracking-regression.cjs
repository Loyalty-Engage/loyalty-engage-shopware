// node tests/tracking-regression.cjs /path/to/node_modules [unpacked-plugin-root]
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(path.join(process.argv[2], 'jsdom'));
const root = path.resolve(process.argv[3] || path.join(__dirname, '..'));
const resources = path.join(root, 'src/Resources');
const forbidden = /squeezely|sqzl|SQ-25652921|custom-header/i;
let checked = 0;
function audit(directory) {
    if (!fs.existsSync(directory)) return;
    for (const item of fs.readdirSync(directory, { withFileTypes: true })) {
        if (['node_modules', 'vendor'].includes(item.name)) continue;
        const file = path.join(directory, item.name);
        if (item.isDirectory()) audit(file);
        else if (/\.(?:js|twig|json)$/.test(item.name)) {
            assert.equal(forbidden.test(fs.readFileSync(file, 'utf8')), false, `Unexpected tracker/reference in ${file}`);
            checked++;
        }
    }
}
// Include generated bundles: fixing only source while shipping a stale bundle is unsafe.
audit(path.join(resources, 'app/storefront'));
audit(path.join(resources, 'public'));
audit(path.join(resources, 'views'));
const sourceDir = path.join(resources, 'app/storefront/src');
const entry = fs.readFileSync(path.join(sourceDir, 'main.js'), 'utf8');
const personalizations = fs.readFileSync(path.join(sourceDir, 'js/personalizations.js'), 'utf8')
    .replace('export class Personalizations', 'class Personalizations')
    .replace('export function startPersonalizations', 'function startPersonalizations');
const imports = [...entry.matchAll(/^import\s+.*?['"]([^'"]+)['"];$/gm)].map(match => match[1]);
assert.deepEqual(imports.sort(), ['./js/loyalty-cart', './js/native-loyalty-shop', './js/personalizations']);
const tick = () => new Promise(resolve => setTimeout(resolve, 180));
const tenant = '804bd81b-a77e-42ee-a70c-53e62eadf4ae';
async function run({ existingPixel = false, config = null, target = false, compiled = false } = {}) {
    const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
        url: 'http://shopware.test:8081/loyaltyshop', runScripts: 'outside-only',
    });
    const { window } = dom;
    const loaded = [];
    const registered = [];
    const originalQueue = [];
    const append = window.document.head.appendChild.bind(window.document.head);
    window.document.head.appendChild = element => {
        if (element.tagName === 'SCRIPT') loaded.push(element.src);
        return append(element);
    };
    window.fetch = () => { throw new Error('Unexpected network request'); };
    window.PluginManager = { register: name => registered.push(name), initializePlugins() {}, getPluginInstances: () => [] };
    window.router = { 'frontend.cookie.offcanvas': '/cookie/offcanvas' };
    window.PluginBaseClass = class {};
    window.CSS = { escape: value => value };
    window.HTMLElement.prototype.getClientRects = () => [{}];
    if (existingPixel) {
        window._sqzl = originalQueue;
        const pixel = window.document.createElement('script');
        pixel.src = 'https://squeezely.tech/tracker/SQ-SHOP-OWNED/sqzl.js';
        window.document.head.appendChild(pixel);
        loaded.length = 0;
    }
    if (config) {
        const element = window.document.createElement('div');
        element.dataset.lePersonalizationConfig = JSON.stringify(config);
        window.document.body.appendChild(element);
    }
    if (target) {
        const element = window.document.createElement('div');
        element.dataset.lePersonalization = 'test';
        window.document.body.appendChild(element);
    }
    try {
        if (compiled) {
            const legacy = path.join(resources, 'app/storefront/dist/storefront/js/loyalty-engage.js');
            if (fs.existsSync(legacy)) {
                window.eval(fs.readFileSync(legacy, 'utf8'));
            } else {
                window.eval(fs.readFileSync(path.join(resources, 'app/storefront/dist/storefront/js/loyalty-engage/loyalty-engage.js'), 'utf8'));
            }
        } else {
            window.eval(`const LoyaltyCartPlugin = class {}; const NativeLoyaltyShop = class {}; const module = {};
                ${personalizations}\n${entry.replace(/^import\s+.*?;\s*$/gm, '')}`);
        }
        await tick();
        assert.deepEqual(registered, ['LoyaltyCartPlugin', 'NativeLoyaltyShop']);
        assert.equal(loaded.some(url => /squeezely|sqzl/i.test(url)), false, 'Never inject a Squeezely pixel');
        if (existingPixel) {
            assert.equal(window._sqzl, originalQueue, 'Preserve the shop-owned queue');
            assert.equal(window.document.querySelectorAll('script[src*="squeezely"]').length, 1, 'Do not duplicate/remove the shop pixel');
        } else assert.equal('_sqzl' in window, false, 'Do not create a tracking queue');
        return loaded;
    } finally { window.close(); }
}
(async () => {
    const eventsDom = new JSDOM('<button class="loyalty-claim-discount-button">Claim</button>', { runScripts: 'outside-only' });
    const eventWindow = eventsDom.window;
    const subscriptions = [];
    eventWindow.document.$emitter = { subscribe: (name, handler) => subscriptions.push(handler) };
    const cartSource = fs.readFileSync(path.join(sourceDir, 'js/loyalty-cart.js'), 'utf8')
        .replace(/^import .*;$/gm, '').replace('export default class LoyaltyCartPlugin', 'window.TestCart = class LoyaltyCartPlugin');
    eventWindow.eval(`const COOKIE_CONFIGURATION_UPDATE = 'cookies'; const Plugin = class {}; const HttpClient = class {};
        const DomAccess = { querySelectorAll: (root, selector) => root.querySelectorAll(selector) }; ${cartSource}`);
    const cart = new eventWindow.TestCart();
    cart.options = eventWindow.TestCart.options;
    let clicks = 0;
    cart._onClaimDiscountButtonClick = () => clicks++;
    cart.init();
    for (let i = 0; i < 5; i++) subscriptions[0]();
    eventWindow.document.querySelector('button').click();
    assert.equal(subscriptions.length, 1, 'Cookie updates never duplicate subscriptions');
    assert.equal(clicks, 1, 'One click triggers one voucher action after repeated cookie updates');
    eventWindow.close();
    assert.deepEqual(await run(), [], 'Default startup must not load external scripts');
    assert.deepEqual(await run({ existingPixel: true }), [], 'Existing shop pixel is left alone');
    const base = { tenant, scope: 'product', product: 'a'.repeat(32), contextUrl: '/loyalty/personalizations/context' };
    assert.deepEqual(await run({ config: { ...base, rules: [] } }), [], 'No rules: no loader');
    const rule = { key: 'test', public_id: 'test-only', enabled: true, placement: 'manual', page_scope: 'product', personalized: false, use_cart_value: false };
    assert.deepEqual(await run({ config: { ...base, rules: [{ ...rule, enabled: false }] }, target: true }), [], 'Disabled rule: no loader');
    assert.deepEqual(await run({ config: { ...base, rules: [rule] } }), [], 'No matching target: no loader');
    assert.deepEqual(await run({ existingPixel: true, config: { ...base, rules: [rule] }, target: true }),
        [`https://le-cloud.app/f/t/${tenant}.js`], 'Only explicitly configured LE loader, not an extra tracker');
    if (process.argv[3]) {
        assert.deepEqual(await run({ compiled: true }), [], 'Compiled default startup: no external script');
        assert.deepEqual(await run({ compiled: true, existingPixel: true }), [], 'Compiled bundle preserves existing shop pixel');
        assert.deepEqual(await run({ compiled: true, config: { ...base, rules: [] } }), [], 'Compiled empty config: no loader');
        assert.deepEqual(await run({ compiled: true, existingPixel: true, config: { ...base, rules: [rule] }, target: true }),
            [`https://le-cloud.app/f/t/${tenant}.js`], 'Compiled bundle only loads explicitly enabled LE loader');
    }
    console.log(`Tracking regression passed: ${checked} source/bundle/template files scanned; default, existing-pixel and configured-loader startup checked. No external requests made.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
