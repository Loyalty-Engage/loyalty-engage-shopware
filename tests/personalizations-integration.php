<?php declare(strict_types=1);

// Local disposable environment only. No requests are made to Loyalty Engage.
// php tests/personalizations-integration.php /var/www/html SALES_CHANNEL_ID --confirm-local
if (($argv[3] ?? '') !== '--confirm-local') { throw new RuntimeException('Explicit local test confirmation required.'); }
$root = $argv[1];
$channel = $argv[2];
$loader = require $root . '/vendor/autoload.php';
$_SERVER['PROJECT_ROOT'] = $root;
$plugins = new Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader($loader, null, Shopware\Core\Kernel::getConnection());
if (class_exists(Shopware\Core\Framework\Adapter\Kernel\KernelFactory::class)) {
    $kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create(environment: 'prod', debug: false, classLoader: $loader, pluginLoader: $plugins);
} else {
    $http = new Shopware\Core\HttpKernel('prod', false, $loader);
    $http->setPluginLoader($plugins);
    $kernel = $http->getKernel();
}
$kernel->boot();
$container = $kernel->getContainer();
$context = Shopware\Core\Framework\Context::createDefaultContext();
$settings = $container->get(Shopware\Core\System\SystemConfig\SystemConfigService::class);
$db = Shopware\Core\Kernel::getConnection();
$channelRow = $db->fetchAssociative('SELECT LOWER(HEX(currency_id)) currency, LOWER(HEX(customer_group_id)) customerGroup, LOWER(HEX(payment_method_id)) payment FROM sales_channel WHERE id = UNHEX(?)', [$channel]);
if (!$channelRow) { throw new RuntimeException('Local sales channel not found.'); }
$previous = [];
foreach (['personalizationRules', 'personalizationCurrencyId'] as $key) {
    $previous[$key] = $settings->get('LoyaltyEngage.config.' . $key, $channel);
}
$customerIds = [];
$pageIds = [];
$landingIds = [];
$jars = [];
register_shutdown_function(static function () use ($settings, $previous, $channel, $container, $context, &$customerIds, &$pageIds, &$landingIds, &$jars): void {
    foreach ($previous as $key => $value) { $settings->set('LoyaltyEngage.config.' . $key, $value, $channel); }
    foreach (['landing_page.repository' => $landingIds, 'cms_page.repository' => $pageIds, 'customer.repository' => $customerIds] as $repository => $ids) {
        if ($ids) { $container->get($repository)->delete(array_map(static fn ($id) => ['id' => $id], $ids), $context); }
    }
    foreach ($jars as $jar) { @unlink($jar); }
});
function integrationCheck(bool $value, string $label): void { if (!$value) { throw new RuntimeException($label); } }
function requestLocal(string $path, string $jar, ?array $post = null, int $redirects = 4): array
{
    $headers = [];
    $curl = curl_init('http://127.0.0.1' . $path);
    $requestHeaders = ['Host: shopware.test:8081'];
    if (str_starts_with($path, '/loyalty/personalizations/context') || $post !== null) {
        $requestHeaders[] = 'X-Requested-With: XMLHttpRequest';
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => $requestHeaders, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) { $headers[] = strtolower(trim($line)); return strlen($line); }]);
    if ($post !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($curl);
    integrationCheck(is_string($body), 'Local request failed');
    if ($post === null && in_array($status, [301, 302, 303, 307, 308], true)) {
        integrationCheck($redirects > 0, 'Local redirect loop');
        foreach ($headers as $header) {
            if (!str_starts_with($header, 'location: ')) { continue; }
            $location = substr($header, 10);
            $host = parse_url($location, PHP_URL_HOST);
            integrationCheck($host === null || in_array($host, ['shopware.test', 'localhost'], true), 'Unexpected external redirect');
            return requestLocal((parse_url($location, PHP_URL_PATH) ?: '/') . (($query = parse_url($location, PHP_URL_QUERY)) ? '?' . $query : ''), $jar, null, $redirects - 1);
        }
    }
    return [$status, $body, implode("\n", $headers)];
}
function targetsFromHtml(string $html): array
{
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $targets = [];
    foreach ((new DOMXPath($document))->query('//*[@data-le-personalization]') as $element) {
        $targets[$element->getAttribute('id')] = $element->getAttribute('data-le-personalization');
    }
    return $targets;
}
function localJar(): string { global $jars; $jars[] = tempnam(sys_get_temp_dir(), 'le-session-'); return end($jars); }
$base = ['public_id' => 'test-1', 'enabled' => true, 'placement' => 'automatic', 'pages' => [], 'personalized' => false, 'use_cart_value' => false];
$rules = [];
foreach (['product', 'cart', 'checkout'] as $scope) { $rules[] = $base + ['key' => 'qa-' . $scope, 'page_scope' => $scope]; }
$rules[] = array_replace($base, ['key' => 'qa-private', 'page_scope' => 'product', 'personalized' => true, 'use_cart_value' => true]);
$rules[] = array_replace($base, ['key' => 'qa-disabled', 'page_scope' => 'product', 'enabled' => false]);
$rules[] = array_replace($base, ['key' => 'qa-manual', 'placement' => 'manual', 'page_scope' => 'product']);
$pageId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
$pageIds[] = $pageId;
$slots = [];
$blocks = [];
for ($i = 0; $i < 2; $i++) {
    $slotId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
    $slots[] = 'le-personalization-cms-' . $slotId;
    $blocks[] = ['id' => Shopware\Core\Framework\Uuid\Uuid::randomHex(), 'type' => 'le-personalization', 'position' => $i,
        'slots' => [['id' => $slotId, 'slot' => 'content', 'type' => 'le-personalization',
            'config' => ['personalizationKey' => ['source' => 'static', 'value' => 'qa-manual']]]]];
}
$container->get('cms_page.repository')->create([['id' => $pageId, 'name' => 'Disposable personalization QA', 'type' => 'landingpage',
    'sections' => [['id' => Shopware\Core\Framework\Uuid\Uuid::randomHex(), 'type' => 'default', 'position' => 0, 'blocks' => $blocks]]]], $context);
$landingId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
$landingIds[] = $landingId;
$container->get('landing_page.repository')->create([['id' => $landingId, 'name' => 'Disposable personalization QA', 'active' => true,
    'url' => 'le-personalization-qa-' . $landingId, 'cmsPageId' => $pageId, 'salesChannels' => [['id' => $channel]]]], $context);
$rules[] = $base + ['key' => 'qa-selected', 'page_scope' => 'pages'];
$rules[count($rules) - 1]['pages'] = [$pageId];
$settings->set('LoyaltyEngage.config.personalizationRules', $rules, $channel);
$settings->set('LoyaltyEngage.config.personalizationCurrencyId', $channelRow['currency'], $channel);
$guest = localJar();
[$status, $html] = requestLocal('/Demo-speeltje/LE-DEMO-TOY-' . substr($channel, 0, 6), $guest);
integrationCheck($status === 200, 'PDP response');
$targets = targetsFromHtml($html);
integrationCheck(in_array('qa-product', $targets, true) && in_array('qa-private', $targets, true), 'PDP automatic placement');
integrationCheck(!in_array('qa-cart', $targets, true) && !in_array('qa-disabled', $targets, true), 'Page scope and disabled rules');
[$status, $cmsHtml, $cmsHeaders] = requestLocal('/landingPage/' . $landingId, $guest);
integrationCheck($status === 200, 'CMS response: ' . $status . ' ' . $cmsHeaders);
$targets = targetsFromHtml($cmsHtml);
integrationCheck(($targets[$slots[0]] ?? null) === 'qa-manual' && ($targets[$slots[1]] ?? null) === 'qa-manual', 'Two independent manual CMS targets');
integrationCheck(in_array('qa-selected', $targets, true) && !in_array('qa-product', $targets, true), 'Selected CMS page scope');
[$status, $cartHtml] = requestLocal('/checkout/cart', $guest);
integrationCheck($status === 200 && in_array('qa-cart', targetsFromHtml($cartHtml), true), 'Cart automatic placement');
[$status, $checkoutHtml] = requestLocal('/checkout/register', $guest);
integrationCheck($status === 200 && in_array('qa-checkout', targetsFromHtml($checkoutHtml), true), 'Checkout automatic placement');
[$status, $body, $headers] = requestLocal('/loyalty/personalizations/context?keys=qa-private&scope=cart', $guest);
$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['context']['qa-private'];
integrationCheck($status === 200 && !isset($data['identifier']) && $data['cartValue'] === 0, 'Guest has no identifier and an empty cart');
integrationCheck(str_contains($headers, 'no-store') && str_contains($headers, 'private'), 'HTTP context headers');
$salutation = $db->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
$country = $db->fetchOne('SELECT LOWER(HEX(id)) FROM country WHERE active = 1 LIMIT 1');
foreach ([1, 2] as $index) {
    $id = Shopware\Core\Framework\Uuid\Uuid::randomHex();
    $customerIds[] = $id;
    $addressId = Shopware\Core\Framework\Uuid\Uuid::randomHex();
    $email = 'le-qa-' . $id . '@example.test';
    $password = 'Local-QA-only-987!';
    $container->get('customer.repository')->create([['id' => $id, 'email' => $email, 'password' => $password, 'firstName' => 'Local', 'lastName' => 'QA',
        'customerNumber' => 'LE-QA-' . $id, 'active' => true, 'guest' => false, 'salutationId' => $salutation,
        'salesChannelId' => $channel, 'groupId' => $channelRow['customerGroup'], 'defaultPaymentMethodId' => $channelRow['payment'],
        'defaultBillingAddressId' => $addressId, 'defaultShippingAddressId' => $addressId,
        'addresses' => [['id' => $addressId, 'firstName' => 'Local', 'lastName' => 'QA', 'street' => 'Teststraat 1', 'zipcode' => '1234AB', 'city' => 'Test', 'countryId' => $country, 'salutationId' => $salutation]]]], $context);
    $jar = localJar();
    [$status] = requestLocal('/account/login', $jar, ['username' => $email, 'password' => $password]);
    integrationCheck(in_array($status, [200, 302], true), 'Local customer login');
    [$status, $body] = requestLocal('/loyalty/personalizations/context?keys=qa-private,qa-product&scope=cart', $jar);
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['context'];
    integrationCheck($status === 200 && ($data['qa-private']['identifier'] ?? '') === hash('sha256', $email), 'Current authenticated session identifier');
    integrationCheck(!isset($data['qa-product']['identifier']), 'Non-personalized rule remains anonymous');
    [$status, $privateHtml] = requestLocal('/Demo-speeltje/LE-DEMO-TOY-' . substr($channel, 0, 6), $jar);
    integrationCheck($status === 200 && !str_contains($privateHtml, $email) && !str_contains($privateHtml, hash('sha256', $email)), 'Shared PDP HTML contains no identity');
    [$status, $confirmHtml] = requestLocal('/checkout/confirm', $jar);
    integrationCheck(in_array($status, [200, 302], true), 'Authenticated checkout remains functional');
}
$settings->set('LoyaltyEngage.config.personalizationRules', [], $channel);
[$status, $disabledHtml] = requestLocal('/Demo-speeltje/LE-DEMO-TOY-' . substr($channel, 0, 6), $guest);
integrationCheck($status === 200 && targetsFromHtml($disabledHtml) === [] && !str_contains($disabledHtml, 'data-le-personalization-config='), 'No active rules means no bootstrap or targets');
echo "Personalization HTTP scopes, repeated CMS slots, authenticated sessions and private-cache checks passed\n";
