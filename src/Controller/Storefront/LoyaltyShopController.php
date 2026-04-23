<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Storefront;

use LoyaltyEngage\Service\LoyaltyShopService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Storefront controller for the Loyalty Shop widget.
 *
 * These endpoints are called by the embedded HTML/JS widget that is injected
 * via the LoyaltyEngage email / CMS block. The widget uses plain fetch() calls
 * with the customer's browser session cookie, so we use the storefront scope
 * (no bearer token required).
 *
 * Routes:
 *   POST /loyaltyshop/cart/add        – add a physical loyalty product by SKU
 *   POST /loyaltyshop/discount/claim  – claim a discount-code product by SKU
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class LoyaltyShopController extends StorefrontController
{
    private LoyaltyShopService $loyaltyShopService;

    public function __construct(LoyaltyShopService $loyaltyShopService)
    {
        $this->loyaltyShopService = $loyaltyShopService;
    }

    /**
     * Add a physical loyalty product to the Shopware cart.
     *
     * Request body (JSON):
     * {
     *   "sku": "24-MB01"
     * }
     */
    #[Route(
        path: '/loyaltyshop/cart/add',
        name: 'frontend.loyaltyshop.cart.add',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        // Must be logged in
        if (!$context->getCustomer()) {
            return new JsonResponse(
                ['success' => false, 'message' => 'User not logged in'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $sku  = trim((string) ($data['sku'] ?? ''));

        if ($sku === '') {
            return new JsonResponse(
                ['success' => false, 'message' => 'SKU is required.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $result = $this->loyaltyShopService->addProductBySku(
            $context->getCustomer()->getEmail(),
            $sku,
            $context
        );

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Claim a discount-code product from the loyalty shop.
     *
     * Request body (JSON):
     * {
     *   "sku":      "DISCOUNT_PER_10",
     *   "discount": 1050
     * }
     */
    #[Route(
        path: '/loyaltyshop/discount/claim',
        name: 'frontend.loyaltyshop.discount.claim',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function claimDiscount(Request $request, SalesChannelContext $context): JsonResponse
    {
        // Must be logged in
        if (!$context->getCustomer()) {
            return new JsonResponse(
                ['success' => false, 'message' => 'User not logged in'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $sku      = trim((string) ($data['sku'] ?? ''));
        $discount = (float) ($data['discount'] ?? 0);

        if ($sku === '') {
            return new JsonResponse(
                ['success' => false, 'message' => 'SKU is required.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $result = $this->loyaltyShopService->claimDiscountCodeProduct(
            $context->getCustomer()->getEmail(),
            $sku,
            $discount,
            $context
        );

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }
}
