<?php declare(strict_types=1);

namespace LoyaltyEngage\Subscriber;

use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Service\LoyaltyEngageApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ReturnSubscriber implements EventSubscriberInterface
{
    private LoyaltyEngageApiService $loyaltyEngageApiService;
    private EntityRepository $orderDeliveryRepository;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoyaltyEngageApiService $loyaltyEngageApiService,
        EntityRepository $orderDeliveryRepository,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->loyaltyEngageApiService = $loyaltyEngageApiService;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onDeliveryReturned'
        ];
    }

    public function onDeliveryReturned(StateMachineTransitionEvent $event): void
    {
        try {
            if (
                $event->getEntityName() !== 'order_delivery' ||
                $event->getToPlace()->getTechnicalName() !== 'returned'
            ) {
                return;
            }

            $deliveryId = $event->getEntityId();

            if (!$this->loyaltyEngageApiService->isReturnExportEnabled()) {
                return;
            }

            $criteria = new Criteria([$deliveryId]);
            $criteria->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $criteria->addAssociation('order');
            $criteria->addAssociation('order.lineItems');
            $criteria->addAssociation('order.orderCustomer');

            /** @var OrderDeliveryEntity|null $delivery */
            $delivery = $this->orderDeliveryRepository->search($criteria, $event->getContext())->first();

            if (!$delivery || !$delivery->getOrder()) {
                $this->logger->error('LoyaltyEngage: Delivery or order not found', ['deliveryId' => $deliveryId]);
                return;
            }

            /** @var OrderEntity $order */
            $order    = $delivery->getOrder();
            $customer = $order->getOrderCustomer();

            if (!$customer || !$customer->getEmail()) {
                $this->logger->error('LoyaltyEngage: Missing customer or email', ['orderId' => $order->getId()]);
                return;
            }

            $email      = $customer->getEmail();
            $returnDate = (new \DateTime())->format(DATE_ATOM);

            // ----------------------------------------------------------------
            // Step 1: Collect product line items and calculate totals.
            //
            // We skip:
            //   - 'delivery'  → shipping costs must never be rewarded/reversed
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

                // Skip shipping / delivery line items — never reverse-reward these
                if ($type === 'delivery' || $type === 'shipping') {
                    continue;
                }

                // Only process product line items from here on
                if ($type !== 'product') {
                    continue;
                }

                if (!$lineItem->getProductId()) {
                    $this->logger->warning('LoyaltyEngage: Missing product ID on line item', [
                        'orderId'    => $order->getId(),
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
                        'orderId'    => $order->getId(),
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
                $this->logger->error('LoyaltyEngage: No valid product line items found', ['orderId' => $order->getId()]);
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
            //   Send price = effectiveSubtotal and quantity = 1 to avoid
            //   rounding loss when effectiveSubtotal is not evenly divisible
            //   by quantity (e.g. €80 / 6 = €13.333...).
            // ----------------------------------------------------------------
            $products       = [];
            $discountCents  = (int) round($totalDiscount * 100);
            $remainingCents = $discountCents;
            $lastIndex      = count($rawProducts) - 1;

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

                $subtotalCents          = (int) round($raw['subtotal'] * 100);
                $effectiveSubtotalCents = max(0, $subtotalCents - $productDiscountCents);
                $effectiveSubtotal      = round($effectiveSubtotalCents / 100, 2);

                $products[] = [
                    'sku'      => $raw['sku'],
                    'price'    => $effectiveSubtotal,
                    'quantity' => 1,
                ];
            }

            if ($this->loyaltyEngageApiService->getLoggerStatus()) {
                $this->logger->info('LoyaltyEngage: Return event products with discount distribution', [
                    'orderId'       => $order->getOrderNumber(),
                    'productTotal'  => $productTotal,
                    'totalDiscount' => $totalDiscount,
                    'products'      => $products,
                ]);
            }

            $message = new ReturnMessage($email, $returnDate, $products, $order->getSalesChannelId());

            $this->logger->info('LoyaltyEngage: Dispatching return message to queue', [
                'returnDate' => $returnDate
            ]);

            $this->messageBus->dispatch($message);

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Fatal error in onDeliveryReturned()', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
