// node tests/personalizations-storefront.cjs /path/to/storefront/node_modules
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(path.join(process.argv[2], 'jsdom'));
const source = fs.readFileSync(path.join(__dirname, '../src/Resources/app/storefront/src/js/personalizations.js'), 'utf8')
    .replace('export class Personalizations', 'class Personalizations').replace('export function startPersonalizations', 'function startPersonalizations');
const tenant = '804bd81b-a77e-42ee-a70c-53e62eadf4ae';
const rule = { key: 'pdp-punten', public_id: 'bp-12', enabled: true, placement: 'manual', page_scope: 'product', personalized: false, use_cart_value: false };
const tick = () => new Promise(resolve => setTimeout(resolve, 160));

async function fixture(rules = [rule]) {
    const dom = new JSDOM('<!doctype html><head></head><body></body>', { url: 'https://shop.example/product', runScripts: 'outside-only' });
    const { window } = dom;
    window.CSS = { escape: value => value };
    window.HTMLElement.prototype.getClientRects = function () { return this.hidden ? [] : [{}]; };
    window.fetch = async () => ({ ok: true, json: async () => ({ context: {} }) });
    window.eval(`${source}\nwindow.TestPersonalizations = Personalizations;`);
    const config = window.document.createElement('div');
    config.dataset.lePersonalizationConfig = JSON.stringify({ tenant, rules, scope: 'product', product: 'a'.repeat(32), contextUrl: '/loyalty/personalizations/context' });
    window.document.body.appendChild(config);
    const target = window.document.createElement('div');
    target.dataset.lePersonalization = 'pdp-punten';
    window.document.body.appendChild(target);
    const runtime = new window.TestPersonalizations();
    await tick();
    return { dom, window, target, runtime, config };
}

(async () => {
    const first = await fixture();
    assert.equal(first.window.document.querySelectorAll('script[src]').length, 1);
    assert.equal(first.window._lecloud.personalization.length, 1);
    assert.equal('identifier' in first.window._lecloud.personalization[0], false);
    assert.equal('cartValue' in first.window._lecloud.personalization[0], false);
    const loader = first.window.document.querySelector('script');
    assert.equal(loader.type, 'module');
    assert.equal(loader.async, true);
    await first.runtime.scan();
    first.runtime.refresh();
    await tick();
    assert.equal(first.window._lecloud.personalization.length, 1, 'Identical events deduplicated');
    const replacement = first.target.cloneNode(false);
    first.target.replaceWith(replacement);
    await tick();
    assert.equal(first.window._lecloud.personalization.length, 2, 'Remounted target triggered');
    assert.equal(first.window.document.querySelectorAll('script[src]').length, 1, 'Only one loader');
    const secondTarget = replacement.cloneNode(false);
    first.window.document.body.appendChild(secondTarget);
    await tick();
    assert.notEqual(secondTarget.id, replacement.id, 'Duplicate placement receives unique target');
    assert.equal(first.window._lecloud.personalization.length, 3);
    first.dom.window.close();

    const disabled = await fixture([{ ...rule, enabled: false }]);
    assert.equal(disabled.window.document.querySelectorAll('script[src]').length, 0);
    assert.equal(disabled.window._lecloud, undefined);
    disabled.dom.window.close();
    const wrongScope = await fixture([{ ...rule, placement: 'automatic', page_scope: 'cart' }]);
    assert.equal(wrongScope.window._lecloud, undefined, 'Automatic rule not used by manual target');
    wrongScope.dom.window.close();

    const automatic = await fixture([{ ...rule, placement: 'automatic' }]);
    automatic.target.id = 'le-personalization-auto-pdp-punten';
    automatic.runtime.refresh();
    await tick();
    const automaticClone = automatic.target.cloneNode(false);
    automatic.window.document.body.appendChild(automaticClone);
    await tick();
    assert.equal(automatic.window._lecloud.personalization.length, 2);
    assert.equal(automatic.runtime.targets().length, 2, 'Renamed automatic clones keep automatic placement');
    assert.notEqual(automaticClone.id, automatic.target.id);
    automatic.dom.window.close();

    const changedKey = await fixture([rule, { ...rule, key: 'other', public_id: 'bp-13' }]);
    changedKey.target.dataset.lePersonalization = 'other';
    await tick();
    assert.equal(changedKey.window._lecloud.personalization.length, 2, 'Changed key on existing DOM node triggers new rule');
    assert.equal(changedKey.window._lecloud.personalization[1].id, 'bp-13');
    changedKey.dom.window.close();

    const pending = await fixture([{ ...rule, personalized: true }]);
    let resolveContext;
    pending.window.fetch = () => new Promise(resolve => { resolveContext = resolve; });
    pending.runtime.refresh();
    await tick();
    pending.config.remove();
    await tick();
    resolveContext({ ok: true, json: async () => ({ context: { 'pdp-punten': { identifier: 'a'.repeat(64) } } }) });
    await tick();
    assert.equal(pending.window._lecloud.personalization.length, 0, 'Removing config cancels pending private trigger');
    pending.dom.window.close();

    const mixed = await fixture([rule, { ...rule, key: 'private', personalized: true }]);
    mixed.window.fetch = async () => { throw new Error('offline'); };
    const privateTarget = mixed.window.document.createElement('div');
    privateTarget.dataset.lePersonalization = 'private';
    mixed.window.document.body.appendChild(privateTarget);
    await tick();
    assert.equal(mixed.window._lecloud.personalization.length, 1, 'Public rule still works when private context fails');
    mixed.dom.window.close();

    const personalized = await fixture([{ ...rule, personalized: true, use_cart_value: true }]);
    let value = 25;
    let identifier;
    personalized.window.fetch = async () => ({ ok: true, json: async () => ({ context: { 'pdp-punten': { cartValue: value, identifier } } }) });
    personalized.runtime.refresh();
    await tick();
    let events = personalized.window._lecloud.personalization;
    assert.equal(events[events.length - 1].cartValue, 25);
    assert.equal('identifier' in events[events.length - 1], false, 'Guest identifier omitted');
    const count = events.length;
    personalized.runtime.refresh();
    await tick();
    assert.equal(events.length, count);
    value = 30;
    identifier = 'a'.repeat(64);
    personalized.runtime.refresh();
    await tick();
    assert.equal(events.length, count + 1);
    assert.equal(events[events.length - 1].identifier, identifier);
    assert.equal(events[events.length - 1].cartValue, 30);
    personalized.window.fetch = async () => { throw new Error('offline'); };
    personalized.runtime.refresh();
    await tick();
    assert.equal(events.length, count + 1, 'Network failure does not push incorrect context');
    personalized.dom.window.close();
    console.log('Personalization storefront DOM, queue, privacy and remount checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
