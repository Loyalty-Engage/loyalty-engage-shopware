<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Api;

use LoyaltyEngage\Api\LoyaltyCartApiInterface;
use LoyaltyEngage\Service\LoyaltyCartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for loyalty cart API endpoints
 * Supports both Store API (frontend) and Admin API (backend) routes
 */
#[Route(defaults: ['_routeScope' => ['store-api', 'api']])]
class LoyaltyCartController extends StorefrontController implements LoyaltyCartApiInterface
{
    /**
     * @var LoyaltyCartService
     */
    private $loyaltyCartService;

    /**
     * @param LoyaltyCartService $loyaltyCartService
     */
    public function __construct(LoyaltyCartService $loyaltyCartService)
    {
        $this->loyaltyCartService = $loyaltyCartService;
    }

    /**
     * Get customer email from context or request parameter
     */
    private function getCustomerEmail(?string $emailParam, SalesChannelContext $context): ?string
    {
        // For Store API: get email from logged-in customer
        if ($context->getCustomer()) {
            return $context->getCustomer()->getEmail();
        }
        
        // For Admin API: use email from URL parameter
        if ($emailParam) {
            return $emailParam;
        }
        
        return null;
    }

    /**
     * Add a product to the cart using loyalty points
     * Works for both Store API (no email param) and Admin API (with email param)
     */
    public function addProductApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';
        
        // Get email from logged-in customer or URL parameter
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->addProduct($customerEmail, $productId, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove a product from the cart
     */
    public function removeProductApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';
        
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->removeProduct($customerEmail, $productId, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove all products from the cart
     */
    public function removeAllProductsApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->removeAllProducts($customerEmail, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Claim a discount after adding a product to the loyalty cart
     */
    public function claimDiscountApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';
        $discount = (float)($data['discount'] ?? 0.1); // Default to 10% if not specified
        
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->claimDiscountAfterAddToCart($customerEmail, $productId, $discount, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * {@inheritdoc}
     */
    public function addProduct(string $email, string $productId, SalesChannelContext $context): array
    {
        return $this->loyaltyCartService->addProduct($email, $productId, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function removeProduct(string $email, string $productId, SalesChannelContext $context): array
    {
        return $this->loyaltyCartService->removeProduct($email, $productId, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllProducts(string $email, SalesChannelContext $context): array
    {
        return $this->loyaltyCartService->removeAllProducts($email, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function claimDiscountAfterAddToCart(string $email, string $productId, float $discount, SalesChannelContext $context): array
    {
        return $this->loyaltyCartService->claimDiscountAfterAddToCart($email, $productId, $discount, $context);
    }
}
