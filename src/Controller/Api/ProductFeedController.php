<?php declare(strict_types=1);

namespace LoyaltyEngage\Controller\Api;

use LoyaltyEngage\Service\ProductFeedService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Psr\Log\LoggerInterface;

class ProductFeedController extends AbstractController
{
    private ProductFeedService $productFeedService;
    private EntityRepository $salesChannelRepository;
    private LoggerInterface $logger;

    public function __construct(
        ProductFeedService $productFeedService,
        EntityRepository $salesChannelRepository,
        LoggerInterface $logger
    ) {
        $this->productFeedService     = $productFeedService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger                 = $logger;
    }

    public function feed(Request $request, Context $context): Response
    {
        $language       = (string) $request->query->get('language', 'nl_NL');
        $salesChannelId = $request->query->get('sales_channel');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        if ($salesChannelId) {
            $criteria->addFilter(new EqualsFilter('id', $salesChannelId));
        }
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if ($salesChannel === null) {
            return new Response(
                json_encode(['error' => 'No active sales channel found']),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/json']
            );
        }

        $this->logger->info('ProductFeedController: Generating NDJSON feed', [
            'salesChannelId' => $salesChannel->getId(),
            'language'       => $language,
        ]);

        $feedService = $this->productFeedService;

        $response = new StreamedResponse(static function () use ($feedService, $context, $language) {
            $offset    = 0;
            $batchSize = 100;

            do {
                $criteria = new Criteria();
                $criteria->setOffset($offset);
                $criteria->setLimit($batchSize);
                $criteria->addAssociation('cover.media');
                $criteria->addAssociation('tags');
                $criteria->addFilter(new EqualsFilter('active', true));
                $criteria->addFilter(new EqualsFilter('parentId', null));

                $count = $feedService->streamBatch($criteria, $context, $language);
                $offset += $batchSize;
            } while ($count === $batchSize);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
