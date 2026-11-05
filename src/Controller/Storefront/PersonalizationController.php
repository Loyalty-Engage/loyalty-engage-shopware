<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Storefront;

use LoyaltyEngage\Service\PersonalizationContext;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersonalizationController extends StorefrontController
{
    public function __construct(private readonly PersonalizationContext $contextService) {}

    public function context(Request $request, SalesChannelContext $context): JsonResponse
    {
        $keys = array_slice(explode(',', substr((string) $request->query->get('keys', ''), 0, 6500)), 0, 100);
        try {
            $data = $this->contextService->load($context, $keys, (string) $request->query->get('scope', ''),
                (string) $request->query->get('product', ''), max(1, min(10000, $request->query->getInt('quantity', 1))));
            $status = 200;
        } catch (\Throwable) {
            // Optional personalization must never break the storefront or expose exception details.
            $data = [];
            $status = 503;
        }
        return new JsonResponse(['context' => (object) $data], $status, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
