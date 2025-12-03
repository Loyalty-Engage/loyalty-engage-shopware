<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Api;

use LoyaltyEngage\Service\CustomerLoyaltyService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
class CustomerLoyaltyController extends AbstractController
{
    /**
     * @var CustomerLoyaltyService
     */
    private $customerLoyaltyService;

    /**
     * @param CustomerLoyaltyService $customerLoyaltyService
     */
    public function __construct(CustomerLoyaltyService $customerLoyaltyService)
    {
        $this->customerLoyaltyService = $customerLoyaltyService;
    }

    /**
     * Update customer loyalty data by email
     * 
     * @Route("/api/_action/loyalty-engage/customer/update", name="api.action.loyalty_engage.customer.update", methods={"POST"})
     */
    public function updateCustomerLoyalty(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email is required'
            ], 400);
        }

        $email = $data['email'];
        unset($data['email']); // Remove email from loyalty data

        $result = $this->customerLoyaltyService->updateCustomerLoyaltyData(
            $email,
            $data,
            $context
        );

        $statusCode = $result['success'] ? 200 : 400;

        return new JsonResponse($result, $statusCode);
    }

    /**
     * Get customer loyalty data by email
     * 
     * @Route("/api/_action/loyalty-engage/customer/get", name="api.action.loyalty_engage.customer.get", methods={"POST"})
     */
    public function getCustomerLoyalty(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email is required'
            ], 400);
        }

        $result = $this->customerLoyaltyService->getCustomerLoyaltyData(
            $data['email'],
            $context
        );

        $statusCode = $result['success'] ? 200 : 404;

        return new JsonResponse($result, $statusCode);
    }
}
