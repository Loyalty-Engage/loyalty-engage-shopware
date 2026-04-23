<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Storefront;

use LoyaltyEngage\Service\LoyaltyShopService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront controller for the Loyalty Shop widget.
 *
 * Routes are defined in Resources/config/routes.xml:
 *   POST /loyaltyshop/cart/add        – add a physical loyalty product by SKU
 *   POST /loyaltyshop/discount/claim  – claim a discount-code product by SKU
 */
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
     * Request body (JSON): { "sku": "24-MB01" }
     */
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
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
     * Request body (JSON): { "sku": "DISCOUNT_PER_10", "discount": 1050 }
     */
    public function claimDiscount(Request $request, SalesChannelContext $context): JsonResponse
    {
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
