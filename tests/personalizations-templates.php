<?php declare(strict_types=1);

$loader = require $argv[1];
$root = dirname(__DIR__);
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($root . '/src/Resources/views'), ['autoescape' => 'html']);
function personalizationTestPath(string $route): string { return '/loyalty/personalizations/context'; }
$twig->addFunction(new Twig\TwigFunction('path', 'personalizationTestPath'));
$base = ['key' => 'qa', 'public_id' => 'test-1', 'enabled' => true, 'placement' => 'automatic', 'page_scope' => 'product', 'pages' => [], 'personalized' => false, 'use_cart_value' => false];
$render = static function (string $route, array $rules, string $pageId = '', bool $landing = false) use ($twig): string {
    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('_route', $route);
    return $twig->render('storefront/component/personalizations.html.twig', [
        'app' => ['request' => $request],
        'page' => ['cmsPage' => $landing ? null : ['id' => $pageId], 'landingPage' => ['cmsPageId' => $landing ? $pageId : null], 'product' => ['id' => str_repeat('a', 32)],
            'extensions' => ['lePersonalizations' => ['tenant' => '804bd81b-a77e-42ee-a70c-53e62eadf4ae', 'rules' => $rules, 'debug' => false]]],
    ]);
};
function templateCheck(bool $value, string $label): void { if (!$value) { throw new RuntimeException($label); } }
templateCheck(!str_contains($render('frontend.detail.page', []), 'data-le-personalization'), 'No active rules, no bootstrap');
$html = $render('frontend.detail.page', [$base]);
templateCheck(str_contains($html, 'le-personalization-auto-qa'), 'PDP automatic target');
templateCheck(str_contains($html, 'data-le-personalization-placement="automatic"'), 'Stable automatic placement marker');
templateCheck(!str_contains($render('frontend.checkout.cart.page', [$base]), 'le-personalization-auto-qa'), 'PDP rule absent from cart');
foreach (['frontend.checkout.cart.page' => 'cart', 'frontend.checkout.confirm.page' => 'checkout', 'frontend.checkout.register.page' => 'checkout'] as $route => $scope) {
    templateCheck(str_contains($render($route, [array_replace($base, ['page_scope' => $scope])]), 'le-personalization-auto-qa'), $scope . ' target');
}
$pageId = str_repeat('b', 32);
$pages = array_replace($base, ['page_scope' => 'pages', 'pages' => [$pageId]]);
templateCheck(str_contains($render('frontend.landing.page', [$pages], $pageId), 'le-personalization-auto-qa'), 'Selected CMS page target');
templateCheck(str_contains($render('frontend.landing.page', [$pages], $pageId, true), 'le-personalization-auto-qa'), 'Selected landing page uses its nested CMS page ID');
templateCheck(!str_contains($render('frontend.landing.page', [$pages], str_repeat('c', 32)), 'le-personalization-auto-qa'), 'Non-selected CMS page excluded');
templateCheck(!str_contains($render('frontend.detail.page', [array_replace($base, ['placement' => 'manual'])]), 'le-personalization-auto-qa'), 'Manual rule never auto-inserted');
foreach ([str_repeat('d', 32), str_repeat('e', 32)] as $slotId) {
    $manual = $twig->render('storefront/element/cms-element-le-personalization.html.twig', ['element' => [
        'id' => $slotId, 'config' => ['personalizationKey' => ['value' => 'qa']],
    ]]);
    templateCheck(str_contains($manual, 'le-personalization-cms-' . $slotId), 'Independent CMS slot target');
}
templateCheck(str_contains(file_get_contents($root . '/src/Resources/views/storefront/base.html.twig'), 'block base_main %'), 'Use the outer storefront block that CMS pages do not replace');
echo "Personalization Twig scopes, CMS slots and public bootstrap checks passed\n";
