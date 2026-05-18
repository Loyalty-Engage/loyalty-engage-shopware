<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\PurchaseMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PurchaseSubscriber implements EventSubscriberInterface
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $orderRepository;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $orderRepository,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onOrderCompleted'
        ];
    }

    public function onOrderCompleted(StateMachineTransitionEvent $event): void
    {
        try {
            if (
                $event->getEntityName() !== 'order' ||
                $event->getToPlace()->getTechnicalName() !== 'completed'
            ) {
                return;
            }

            $orderId = $event->getEntityId();

            if (!$this->loyaltyEngageApiService->isPurchaseExportEnabled()) {
                return;
            }

            $criteria = new Criteria([$orderId]);
            $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('orderCustomer');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
                $this->logger->error('LoyaltyEngage: Order not found for purchase event', ['orderId' => $orderId]);
                return;
            }

            $customer = $order->getOrderCustomer();
            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('LoyaltyEngage: Order customer or email missing', ['orderId' => $orderId]);
                return;
            }

            $email       = $customer->getEmail();
            $orderNumber = $order->getOrderNumber();
            $orderDate   = $order->getOrderDateTime()?->format(DATE_ATOM) ?? '';

            // ----------------------------------------------------------------
            // Step 1: Collect product line items and calculate totals.
            //
            // We skip:
            //   - 'delivery'  → shipping costs must never be rewarded
            //   - 'promotion' → handled separately as discount
            //   - any other non-product type
            // ----------------------------------------------------------------
            $rawProducts   = [];   // [['sku', 'unitPrice', 'quantity', 'subtotal'], ...]
            $productTotal  = 0.0;  // sum of (unitPrice × quantity) for all products
            $totalDiscount = 0.0;  // absolute value of all promotion discounts

            foreach ($order->getLineItems() as $lineItem) {
                $type = $lineItem->getType();

                // Accumulate discount from promotion line items (value is negative)
                if ($type === 'promotion') {
                    $totalDiscount += abs((float) $lineItem->getTotalPrice());
                    continue;
                }

                // Skip shipping / delivery line items — never reward these
                if ($type === 'delivery' || $type === 'shipping') {
                    continue;
                }

                // Only process product line items from here on
                if ($type !== 'product') {
                    continue;
                }

                if (!$lineItem->getProductId()) {
                    $this->logger->warning('LoyaltyEngage: Missing product ID on line item', [
                        'orderId'    => $orderId,
                        'lineItemId' => $lineItem->getId()
                    ]);
                    continue;
                }

                // Use productNumber (SKU) from payload — the LoyaltyEngage API expects the SKU,
                // not the Shopware internal UUID.
                $payload = $lineItem->getPayload() ?? [];
                $sku     = $payload['productNumber'] ?? null;

                if (!$sku) {
                    $this->logger->warning('LoyaltyEngage: Missing productNumber in line item payload', [
                        'orderId'    => $orderId,
                        'lineItemId' => $lineItem->getId()
                    ]);
                    continue;
                }

                $unitPrice = (float) $lineItem->getUnitPrice();
                $quantity  = (int)   $lineItem->getQuantity();
                $subtotal  = $unitPrice * $quantity;

                $rawProducts[]  = [
                    'sku'       => $sku,
                    'unitPrice' => $unitPrice,
                    'quantity'  => $quantity,
                    'subtotal'  => $subtotal,
                ];
                $productTotal += $subtotal;
            }

            if (empty($rawProducts)) {
                $this->logger->error('LoyaltyEngage: No valid product line items found', ['orderId' => $orderId]);
                return;
            }

            // ----------------------------------------------------------------
            // Step 2: Distribute the total discount proportionally across
            //         products based on each product's share of the subtotal.
            //
            // Strategy: work in cents (integers) to avoid floating-point drift.
            //
            //   discountCents     = round(totalDiscount × 100)
            //   For each product (except the last):
            //     share           = productSubtotal / productTotal
            //     productDiscount = floor(discountCents × share)   ← always round down
            //     remainingCents -= productDiscount
            //   Last product gets all remainingCents (absorbs rounding remainder)
            //
            //   effectiveSubtotalCents = productSubtotalCents - productDiscountCents
            //   effectiveUnitPrice     = effectiveSubtotalCents / quantity / 100
            //                           (rounded to 2 dp)
            // ----------------------------------------------------------------
            $products          = [];
            $discountCents     = (int) round($totalDiscount * 100);
            $remainingCents    = $discountCents;
            $lastIndex         = count($rawProducts) - 1;

            foreach ($rawProducts as $i => $raw) {
                if ($i === $lastIndex) {
                    // Last product absorbs any remaining cents from rounding
                    $productDiscountCents = $remainingCents;
                } elseif ($productTotal > 0.0) {
                    $share                = $raw['subtotal'] / $productTotal;
                    $productDiscountCents = (int) floor($discountCents * $share);
                    $remainingCents      -= $productDiscountCents;
                } else {
                    $productDiscountCents = 0;
                }

                $subtotalCents         = (int) round($raw['subtotal'] * 100);
                $effectiveSubtotalCents = max(0, $subtotalCents - $productDiscountCents);
                $effectiveUnitPrice     = $raw['quantity'] > 0
                    ? round($effectiveSubtotalCents / $raw['quantity'] / 100, 2)
                    : 0.0;

                $products[] = [
                    'sku'      => $raw['sku'],
                    'price'    => $effectiveUnitPrice,
                    'quantity' => $raw['quantity'],
                ];
            }

            if ($this->loyaltyEngageApiService->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Purchase event products with discount distribution', [
                    'orderId'       => $orderNumber,
                    'productTotal'  => $productTotal,
                    'totalDiscount' => $totalDiscount,
                    'products'      => $products,
                ]);
            }

            $message = new PurchaseMessage($email, $orderNumber, $orderDate, $products);

            $this->logger->info('LoyaltyEngage: Dispatching purchase message to queue', [
                'orderId' => $orderNumber
            ]);

            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Fatal error in onOrderCompleted()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
