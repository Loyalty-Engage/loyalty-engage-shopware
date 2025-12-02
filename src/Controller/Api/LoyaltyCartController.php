<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Api;

use LoyaltyEngage\Api\LoyaltyCartApiInterface;
use LoyaltyEngage\Service\LoyaltyCartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for loyalty cart API endpoints
 */
class LoyaltyCartController extends AbstractController implements LoyaltyCartApiInterface
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
     * Add a product to the cart using loyalty points
     */
    public function addProductApi(string $email, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';

        $result = $this->addProduct($email, $productId, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove a product from the cart
     */
    public function removeProductApi(string $email, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';

        $result = $this->removeProduct($email, $productId, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove all products from the cart
     */
    public function removeAllProductsApi(string $email, SalesChannelContext $context): JsonResponse
    {
        $result = $this->removeAllProducts($email, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Claim a discount after adding a product to the loyalty cart
     */
    public function claimDiscountApi(string $email, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? '';
        $discount = (float)($data['discount'] ?? 0.1); // Default to 10% if not specified

        $result = $this->claimDiscountAfterAddToCart($email, $productId, $discount, $context);

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
