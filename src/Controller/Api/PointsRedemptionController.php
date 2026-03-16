<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Api;

use LoyaltyEngage\Service\PointsRedemptionService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for loyalty points redemption API endpoints
 * Supports both Store API (frontend) and Admin API (backend) routes
 */
#[Route(defaults: ['_routeScope' => ['store-api', 'api']])]
class PointsRedemptionController extends StorefrontController
{
    /**
     * @var PointsRedemptionService
     */
    private $pointsRedemptionService;

    /**
     * @param PointsRedemptionService $pointsRedemptionService
     */
    public function __construct(PointsRedemptionService $pointsRedemptionService)
    {
        $this->pointsRedemptionService = $pointsRedemptionService;
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
     * Redeem loyalty points for a discount
     * 
     * Store API: POST /store-api/loyalty/redeem-points
     * Admin API: POST /api/v{version}/loyalty/shop/{email}/redeem-points
     * 
     * Request body:
     * {
     *   "points": 10  // Number of points to redeem
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Successfully redeemed 10 points for €10 discount.",
     *   "discountAmount": 10,
     *   "pointsRedeemed": 10,
     *   "discountCodes": ["CODE1", "CODE2", ...]
     * }
     */
    public function redeemPointsApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $points = (int) ($data['points'] ?? 0);
        
        if ($points <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Points must be a positive number.'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Get email from logged-in customer or URL parameter
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->pointsRedemptionService->redeemPointsForDiscount($customerEmail, $points, $context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove loyalty points discount from cart
     * 
     * Store API: DELETE /store-api/loyalty/redeem-points
     * Admin API: DELETE /api/v{version}/loyalty/shop/{email}/redeem-points
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Loyalty points discount removed from cart."
     * }
     */
    public function removePointsDiscountApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        // Get email from logged-in customer or URL parameter
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->pointsRedemptionService->removePointsDiscount($context);

        return new JsonResponse($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Get redemption info (available points, limits, existing discount)
     * 
     * Store API: GET /store-api/loyalty/redeem-points/info
     * Admin API: GET /api/v{version}/loyalty/shop/{email}/redeem-points/info
     * 
     * Response:
     * {
     *   "enabled": true,
     *   "customerPoints": 100,
     *   "pointsPerEuro": 1,
     *   "minPointsToRedeem": 1,
     *   "maxPointsPerOrder": 50,
     *   "maxDiscountPercentage": 50,
     *   "cartTotal": 150.00,
     *   "maxRedeemablePoints": 50,
     *   "maxDiscountAmount": 50,
     *   "existingDiscount": null
     * }
     */
    public function getRedemptionInfoApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        // Get email from logged-in customer or URL parameter
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->pointsRedemptionService->getRedemptionInfo($customerEmail, $context);

        return new JsonResponse($result, Response::HTTP_OK);
    }

    /**
     * Calculate discount preview without actually redeeming
     * 
     * Store API: POST /store-api/loyalty/redeem-points/preview
     * Admin API: POST /api/v{version}/loyalty/shop/{email}/redeem-points/preview
     * 
     * Request body:
     * {
     *   "points": 10
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "points": 10,
     *   "discountAmount": 10,
     *   "newCartTotal": 140.00,
     *   "canRedeem": true,
     *   "message": "You can redeem 10 points for €10 discount."
     * }
     */
    public function previewRedemptionApi(?string $email = null, Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $points = (int) ($data['points'] ?? 0);
        
        if ($points <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Points must be a positive number.'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Get email from logged-in customer or URL parameter
        $customerEmail = $this->getCustomerEmail($email, $context);
        
        if (!$customerEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer not logged in or email not provided.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Get redemption info to validate
        $info = $this->pointsRedemptionService->getRedemptionInfo($customerEmail, $context);
        
        if (!$info['enabled']) {
            return new JsonResponse([
                'success' => false,
                'canRedeem' => false,
                'message' => 'Points redemption is not enabled.'
            ], Response::HTTP_OK);
        }

        $pointsPerEuro = $info['pointsPerEuro'];
        $discountAmount = $points / $pointsPerEuro;
        $newCartTotal = max(0, $info['cartTotal'] - $discountAmount);
        
        // Validate points
        $canRedeem = true;
        $message = "You can redeem {$points} points for €{$discountAmount} discount.";
        
        if ($points > $info['customerPoints']) {
            $canRedeem = false;
            $message = "Insufficient points. You have {$info['customerPoints']} points available.";
        } elseif ($points < $info['minPointsToRedeem']) {
            $canRedeem = false;
            $message = "Minimum {$info['minPointsToRedeem']} points required to redeem.";
        } elseif ($info['maxPointsPerOrder'] > 0 && $points > $info['maxPointsPerOrder']) {
            $canRedeem = false;
            $message = "Maximum {$info['maxPointsPerOrder']} points can be redeemed per order.";
        } elseif ($points > $info['maxRedeemablePoints']) {
            $canRedeem = false;
            $message = "Maximum {$info['maxRedeemablePoints']} points can be redeemed for this order.";
        }

        return new JsonResponse([
            'success' => true,
            'points' => $points,
            'discountAmount' => $discountAmount,
            'currentCartTotal' => $info['cartTotal'],
            'newCartTotal' => $newCartTotal,
            'canRedeem' => $canRedeem,
            'message' => $message,
            'customerPoints' => $info['customerPoints'],
            'maxRedeemablePoints' => $info['maxRedeemablePoints']
        ], Response::HTTP_OK);
    }
}
