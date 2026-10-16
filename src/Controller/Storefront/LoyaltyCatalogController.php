<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Storefront;

use LoyaltyEngage\Service\LoyaltyProductCatalog;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use LoyaltyEngage\Service\LoyaltyProductFeed;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoyaltyCatalogController extends StorefrontController
{
    public function __construct(
        private readonly LoyaltyProductCatalog $catalog,
        private readonly LoyaltyShopConfig $config,
        private readonly GenericPageLoader $pageLoader,
        private readonly LoyaltyProductFeed $productFeed
    ) {}

    public function index(Request $request, SalesChannelContext $context): Response
    {
        if (!$this->config->enabled($context->getSalesChannelId())) { throw $this->createNotFoundException(); }
        $page = $this->pageLoader->load($request, $context);
        $listing = $this->catalog->listing($context, max(1, $request->query->getInt('lePage', 1)), $request->query->get('leSort', 'name'));
        return $this->renderStorefront('@LoyaltyEngage/storefront/page/loyalty-shop/index.html.twig', [
            'page' => $page, 'loyaltyShop' => $listing,
            'pointsName' => $this->config->get('shopPointsName', $context->getSalesChannelId(), 'Punten'),
        ]);
    }

    public function state(SalesChannelContext $context): JsonResponse
    {
        $customer = $context->getCustomer();
        $fields = $customer?->getCustomFields() ?? [];
        return new JsonResponse([
            'enabled' => $this->config->enabled($context->getSalesChannelId()),
            'loggedIn' => $customer !== null && !$customer->getGuest(),
            'availableCoins' => max(0, (int) ($fields['le_available_coins'] ?? 0)),
            'tier' => $fields['le_current_tier'] ?? null,
            'balanceSource' => 'shopware-synced',
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function feed(Request $request, SalesChannelContext $context): Response
    {
        $expected = (string) $this->config->get('shopFeedToken', $context->getSalesChannelId(), '');
        $token = (string) $request->query->get('token', '');
        if (!$this->config->enabled($context->getSalesChannelId()) || strlen($expected) < 32 || !hash_equals($expected, $token)) {
            return new Response('', 403, ['Cache-Control' => 'private, no-store']);
        }
        return new StreamedResponse(function () use ($context): void {
            foreach ($this->productFeed->rows($context) as $row) {
                echo json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
        }, 200, ['Content-Type' => 'application/x-ndjson; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
