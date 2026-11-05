// Local-only HTTP integration, independent of docker exec and the installed PHP version.
// SHOPWARE_QA_PASSWORD=... node tests/personalizations-http.cjs SALES_CHANNEL_ID --confirm-local
const assert = require('node:assert/strict');
const { randomUUID, createHash } = require('node:crypto');
const channel = process.argv[2];
const origin = process.env.SHOPWARE_QA_ORIGIN || 'http://shopware.test:8081';
assert.equal(process.argv[3], '--confirm-local');
assert.match(channel || '', /^[a-f0-9]{32}$/);
assert.ok(['http://shopware.test:8081', 'http://localhost:8081'].includes(origin), 'Local test origin only');
assert.ok(process.env.SHOPWARE_QA_PASSWORD, 'Provide the local administrator password via environment');
const id = () => randomUUID().replaceAll('-', '');
const entity = data => data?.attributes ? { id: data.id, ...data.attributes } : data;
const hash = email => createHash('sha256').update(email).digest('hex');
const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
let token;
let original;
const created = [];
async function api(path, method = 'GET', body) {
    const response = await fetch(origin + path, { method, headers: { ...headers, ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(20000) });
    const raw = await response.text();
    const data = raw ? JSON.parse(raw) : {};
    assert.ok(response.ok, `${method} ${path}: HTTP ${response.status}, ${data.errors?.[0]?.code || 'request failed'}`);
    return data;
}
async function page(path, jar = new Map(), post, redirects = 4) {
    const response = await fetch(origin + path, { method: post ? 'POST' : 'GET', redirect: 'manual',
        headers: { Cookie: [...jar].map(([key, value]) => `${key}=${value}`).join('; '),
            ...(post || path.startsWith('/loyalty/personalizations/context') ? { 'X-Requested-With': 'XMLHttpRequest' } : {}),
            ...(post ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) },
        body: post ? new URLSearchParams(post) : undefined, signal: AbortSignal.timeout(20000) });
    for (const cookie of response.headers.getSetCookie()) {
        const pair = cookie.split(';')[0];
        const split = pair.indexOf('=');
        jar.set(pair.slice(0, split), pair.slice(split + 1));
    }
    const body = await response.text();
    if (!post && [301, 302, 303, 307, 308].includes(response.status)) {
        assert.ok(redirects > 0, 'Local redirect limit');
        const destination = new URL(response.headers.get('location'), origin);
        assert.equal(destination.origin, origin, 'Never follow an external redirect');
        return page(destination.pathname + destination.search, jar, undefined, redirects - 1);
    }
    return { status: response.status, headers: response.headers, body };
}
function targets(html) {
    return new Map([...html.matchAll(/id="([^"]+)"[^>]*data-le-personalization="([^"]+)"/g)].map(match => [match[1], match[2]]));
}
function bootstrap(html) {
    const attribute = html.match(/data-le-personalization-config="([^"]*)"/)?.[1];
    assert.ok(attribute, 'Public bootstrap exists');
    return JSON.parse(attribute.replace(/&#x([a-f0-9]+);/gi, (_, code) => String.fromCodePoint(parseInt(code, 16)))
        .replace(/&#([0-9]+);/g, (_, code) => String.fromCodePoint(Number(code)))
        .replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
}
const configPath = `/api/_action/system-config?salesChannelId=${channel}`;
const prefix = 'LoyaltyEngage.config.';
const setConfig = config => api(configPath, 'POST', Object.fromEntries(Object.entries(config).map(([key, value]) => [prefix + key, value])));
async function create(type, payload) {
    await api(`/api/${type}`, 'POST', payload);
    created.unshift([type, payload.id]);
}
(async () => {
    const auth = await api('/api/oauth/token', 'POST', { grant_type: 'password', client_id: 'administration',
        username: process.env.SHOPWARE_QA_USERNAME || 'admin', password: process.env.SHOPWARE_QA_PASSWORD, scopes: 'write' });
    token = auth.access_token;
    assert.ok(token, 'Local admin authentication');
    const configuration = await api(configPath + '&domain=LoyaltyEngage.config&inherit=false');
    original = { personalizationRules: configuration[prefix + 'personalizationRules'] ?? null,
        personalizationCurrencyId: configuration[prefix + 'personalizationCurrencyId'] ?? null };
    await api('/api/_action/cache', 'DELETE');
    const sc = entity((await api(`/api/sales-channel/${channel}`)).data);
    const salutation = entity((await api('/api/search/salutation', 'POST', { limit: 1 })).data[0]).id;
    const country = entity((await api('/api/search/country', 'POST', { limit: 1, filter: [{ type: 'equals', field: 'active', value: true }] })).data[0]).id;
    const cmsId = id();
    const slotIds = [id(), id()];
    await create('cms-page', { id: cmsId, name: 'Disposable personalization HTTP QA', type: 'landingpage',
        sections: [{ id: id(), type: 'default', position: 0, blocks: slotIds.map((slot, index) => ({ id: id(), type: 'le-personalization', position: index,
            slots: [{ id: slot, slot: 'content', type: 'le-personalization', config: { personalizationKey: { source: 'static', value: 'qa-manual' } } }] })) }] });
    const landingId = id();
    await create('landing-page', { id: landingId, name: 'Disposable personalization HTTP QA', active: true, url: 'le-http-qa-' + landingId,
        cmsPageId: cmsId, salesChannels: [{ id: channel }] });
    const base = { public_id: 'test-1', enabled: true, placement: 'automatic', pages: [], personalized: false, use_cart_value: false };
    const rules = ['product', 'cart', 'checkout'].map(scope => ({ ...base, key: 'qa-' + scope, page_scope: scope }));
    rules.push({ ...base, key: 'qa-private', page_scope: 'product', personalized: true, use_cart_value: true },
        { ...base, key: 'qa-disabled', page_scope: 'product', enabled: false },
        { ...base, key: 'qa-manual', placement: 'manual', page_scope: 'product' },
        { ...base, key: 'qa-selected', page_scope: 'pages', pages: [cmsId] });
    await setConfig({ personalizationRules: rules, personalizationCurrencyId: sc.currencyId });
    const guest = new Map();
    const productPath = '/Demo-speeltje/LE-DEMO-TOY-' + channel.slice(0, 6);
    let response = await page(productPath, guest);
    assert.equal(response.status, 200, 'Product page');
    assert.ok([...targets(response.body).values()].includes('qa-product'), 'PDP placement');
    assert.ok(![...targets(response.body).values()].includes('qa-disabled'), 'Disabled rule excluded');
    console.log('PASS product page and disabled/out-of-scope rules');
    response = await page('/landingPage/' + landingId, guest);
    assert.equal(response.status, 200, 'CMS page');
    assert.equal(bootstrap(response.body).cmsPage, cmsId, 'Landing page CMS context');
    for (const slot of slotIds) assert.equal(targets(response.body).get('le-personalization-cms-' + slot), 'qa-manual', 'Independent CMS slot');
    assert.ok([...targets(response.body).values()].includes('qa-selected'), 'Selected CMS scope');
    assert.ok(![...targets(response.body).values()].includes('qa-product'), 'Wrong CMS scope excluded');
    console.log('PASS manual CMS, repeated slots and selected-page placement');
    if (process.env.SHOPWARE_QA_BROWSER === '1') {
        console.log(`BROWSER_CHECK ${origin}/landingPage/${landingId}`);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Browser check timed out; cleaning up local fixtures')), 180000);
            process.stdin.once('data', () => { clearTimeout(timer); resolve(); });
        });
    }
    response = await page('/checkout/cart', guest);
    assert.equal(response.status, 200);
    assert.ok([...targets(response.body).values()].includes('qa-cart'), 'Cart automatic placement');
    const contextPath = '/loyalty/personalizations/context?keys=qa-private,qa-product&scope=cart';
    response = await page(contextPath, guest);
    assert.equal(response.status, 200);
    let data = JSON.parse(response.body).context;
    assert.equal(data['qa-private'].identifier, undefined);
    assert.equal(data['qa-private'].cartValue, 0);
    assert.match(response.headers.get('cache-control'), /no-store/);
    assert.match(response.headers.get('cache-control'), /private/);
    console.log('PASS guest context and private/no-store HTTP headers');
    const tax = entity((await api('/api/search/tax', 'POST', { limit: 1 })).data[0]);
    const productId = id();
    await create('product', { id: productId, productNumber: 'LE-HTTP-QA-' + productId, name: 'Disposable personalization QA product',
        active: true, stock: 20, taxId: tax.id, price: [{ currencyId: sc.currencyId, gross: 12, net: Math.round(1200 / (1 + tax.taxRate / 100)) / 100, linked: true }],
        visibilities: [{ salesChannelId: channel, visibility: 30 }], customFields: { le_shop_enabled: false, le_shop_regular_purchase: true } });
    const addProduct = async jar => {
        const fields = { id: productId, referencedId: productId, type: 'product', stackable: '1', removable: '1', quantity: '2' };
        const payload = Object.fromEntries(Object.entries(fields).map(([key, value]) => [`lineItems[${productId}][${key}]`, value]));
        const added = await page('/checkout/line-item/add', jar, payload);
        assert.ok([200, 204, 302].includes(added.status), 'Local regular product added');
    };
    await addProduct(guest);
    response = await page('/checkout/register', guest);
    assert.equal(response.status, 200);
    assert.ok([...targets(response.body).values()].includes('qa-checkout'), 'Checkout registration automatic placement with a non-empty cart');
    response = await page(contextPath, guest);
    assert.equal(JSON.parse(response.body).context['qa-private'].cartValue, 24, 'Real cart product value');
    console.log('PASS cart value change and checkout registration scope');
    for (let index = 0; index < 2; index++) {
        const customerId = id();
        const addressId = id();
        const email = `le-http-qa-${customerId}@example.test`;
        const password = 'Local-HTTP-QA-only-987!';
        await create('customer', { id: customerId, email, password, firstName: 'Local', lastName: 'QA', customerNumber: 'LE-HTTP-QA-' + customerId,
            active: true, guest: false, salutationId: salutation, salesChannelId: channel, groupId: sc.customerGroupId,
            defaultPaymentMethodId: sc.paymentMethodId, defaultBillingAddressId: addressId, defaultShippingAddressId: addressId,
            addresses: [{ id: addressId, firstName: 'Local', lastName: 'QA', salutationId: salutation, countryId: country,
                street: 'Teststraat 1', zipcode: '1234AB', city: 'Test' }] });
        const jar = new Map();
        response = await page('/account/login', jar, { username: email, password });
        assert.ok([200, 302].includes(response.status), 'Local customer login');
        response = await page(contextPath, jar);
        data = JSON.parse(response.body).context;
        assert.equal(data['qa-private'].identifier, hash(email), 'Only this session identity');
        assert.equal(data['qa-product'].identifier, undefined, 'Public rule never receives identity');
        response = await page(productPath, jar);
        assert.equal(response.status, 200);
        assert.ok(!response.body.includes(hash(email)), 'No private identity in shared product HTML');
        await addProduct(jar);
        response = await page('/checkout/confirm', jar);
        assert.equal(response.status, 200);
        assert.ok([...targets(response.body).values()].includes('qa-checkout'), 'Authenticated checkout confirmation placement');
        console.log(`PASS authenticated session ${index + 1} and public HTML identity isolation`);
    }
    await setConfig({ personalizationRules: [] });
    response = await page(productPath, guest);
    assert.equal(response.status, 200);
    assert.equal(targets(response.body).size, 0);
    assert.ok(!response.body.includes('data-le-personalization-config='));
    console.log('PASS no rules means no targets or loader bootstrap');
})().catch(error => {
    console.error(error.message);
    process.exitCode = 1;
}).finally(async () => {
    let failed = false;
    if (original) {
        try { await setConfig(original); } catch (_) { console.error('Could not restore local personalization settings'); failed = true; }
    }
    for (const [type, recordId] of created) {
        try { await api(`/api/${type}/${recordId}`, 'DELETE'); } catch (_) { console.error(`Could not remove own QA fixture ${type}/${recordId}`); failed = true; }
    }
    if (failed) process.exitCode = 1;
    else console.log('PASS original settings restored and temporary fixtures removed');
});
