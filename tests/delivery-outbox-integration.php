<?php declare(strict_types=1);

// Disposable local Shopware DB, mocked provider; no real point mutations.
$root = $argv[1];
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('LoyaltyEngage\\', dirname(__DIR__) . '/src/');
if (is_file($root . '/.env')) { (new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env'); }
if (!in_array(getenv('APP_URL'), ['http://shopware.test:8081', 'http://localhost:8082'], true)) {
    throw new RuntimeException('Disposable local environment required');
}
$db = Shopware\Core\Kernel::getConnection();
if ((int) $db->fetchOne('SELECT COUNT(*) FROM le_delivery_outbox') !== 0) {
    throw new RuntimeException('Use an empty disposable outbox; the retry scanner must not touch unrelated deliveries.');
}
$other = Doctrine\DBAL\DriverManager::getConnection($db->getParams());
$prefix = 'outbox-qa-' . bin2hex(random_bytes(8));
$ids = [];
register_shutdown_function(static function () use ($db, &$ids): void {
    if ($db->isTransactionActive()) { $db->rollBack(); }
    foreach ($ids as $id) { $db->delete('le_delivery_outbox', ['id' => $id]); }
});
function verify(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
$api = new class extends LoyaltyEngage\Service\LoyaltyEngageApiService {
    public array $calls = [];
    public ?int $status = 200;
    public $during = null;
    public function __construct() {}
    public function placeOrderWithResponse(string $email, string $orderId, array $products, ?string $salesChannelId = null): array {
        $this->calls[] = [$email, $orderId, $products, $salesChannelId];
        if ($this->during) { ($this->during)(); }
        return ['statusCode' => $this->status, 'body' => null];
    }
    public function sendEvent(array $payload, ?string $salesChannelId = null): ?int {
        $this->calls[] = [$payload, $salesChannelId];
        return $this->status;
    }
    public function redeemDiscountCode(string $email, string $code, ?string $salesChannelId = null): bool {
        $this->calls[] = [$email, $code, $salesChannelId];
        return $this->status === 200;
    }
};
$outbox = new LoyaltyEngage\Service\DeliveryOutbox($db, $api, new Psr\Log\NullLogger());
$competing = new LoyaltyEngage\Service\DeliveryOutbox($other, $api, new Psr\Log\NullLogger());
$message = new LoyaltyEngage\Message\FreeProductPurchaseMessage('qa@example.test', $prefix, [['sku' => 'SKU', 'quantity' => 1]], 'local');
$id = $outbox->enqueue($message); $ids[] = $id;
$api->status = null;
$outbox->process($id);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$id]) === 'pending', 'Timeout retained for retry');
verify($db->fetchOne('SELECT attempts FROM le_delivery_outbox WHERE id = ?', [$id]) == 1, 'One attempt recorded');
$outbox->dispatch($message);
verify(count($api->calls) === 1, 'Queue redelivery respects retry backoff');
$db->update('le_delivery_outbox', ['next_attempt_at' => '2000-01-01 00:00:00'], ['id' => $id]);
$api->status = 200;
$api->during = static function () use ($competing, $id): void { $competing->process($id); };
$outbox->process($id);
$api->during = null;
verify(count($api->calls) === 2, 'Second DB connection cannot send concurrently');
verify($api->calls[0] === $api->calls[1], 'Retry preserves orderId, channel and entire payload');
$outbox->dispatch($message);
verify(count($api->calls) === 2, 'Successful delivery is never sent again');
$changed = new LoyaltyEngage\Message\FreeProductPurchaseMessage('different@example.test', $prefix, [['sku' => 'OTHER']], 'local');
verify($outbox->enqueue($changed) === $id, 'Same order resolves to original operation');
verify(json_decode($db->fetchOne('SELECT payload FROM le_delivery_outbox WHERE id = ?', [$id]), true)['email'] === 'qa@example.test', 'Changed duplicate cannot overwrite original payload');
$second = new LoyaltyEngage\Message\FreeProductPurchaseMessage('qa@example.test', $prefix . '-crash', [], 'local');
$crashId = $outbox->enqueue($second); $ids[] = $crashId;
$db->update('le_delivery_outbox', ['state' => 'sending', 'attempts' => 1], ['id' => $crashId]);
$outbox->process($crashId);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$crashId]) === 'succeeded', 'Abandoned idempotent send recovers after worker crash');
$purchase = new LoyaltyEngage\Message\PurchaseMessage('qa@example.test', $prefix . '-purchase', '2026-11-19', [], 'local');
$purchaseId = $outbox->enqueue($purchase); $ids[] = $purchaseId;
$api->status = 503; $outbox->process($purchaseId);
$db->update('le_delivery_outbox', ['next_attempt_at' => '2000-01-01 00:00:00'], ['id' => $purchaseId]);
$api->status = 200; $outbox->process($purchaseId);
$last = array_slice($api->calls, -2);
verify($last[0] === $last[1] && $last[1][0][0]['orderId'] === $prefix . '-purchase' && $last[1][1] === 'local', 'Purchase event retries retain original orderId and sales channel credentials scope');
$return = new LoyaltyEngage\Message\ReturnMessage('qa@example.test', '2026-11-19', [], 'local', $prefix . '-return');
foreach (['first', 'second'] as $part) {
    $partial = new LoyaltyEngage\Message\ReturnMessage('qa@example.test', '2026-11-19', [['sku' => $part]], 'local', $prefix . $part, $prefix);
    $ids[] = $outbox->enqueue($partial);
    $before = count($api->calls);
    $outbox->dispatch($partial);
    $outbox->dispatch($partial);
    verify(count($api->calls) === $before + 1 && end($api->calls)[0][0]['orderId'] === $prefix, 'Separate return delivery sent once with shared orderId: ' . $part);
}
$returnId = $outbox->enqueue($return); $ids[] = $returnId;
$api->status = null; $outbox->process($returnId);
$before = count($api->calls); $outbox->dispatch($return);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$returnId]) === 'uncertain' && count($api->calls) === $before, 'Uncertain return is retained, never automatically repeated');
$returnDuplicate = new LoyaltyEngage\Message\ReturnMessage('qa@example.test', '2026-11-20', [], 'local', $prefix . '-return');
verify($outbox->enqueue($returnDuplicate) === $returnId, 'Repeated delivery transition with a new timestamp cannot create another local Return');
$returnCrashId = $outbox->enqueue(new LoyaltyEngage\Message\ReturnMessage('qa@example.test', '2026-11-19', [], 'local', $prefix . '-return-crash')); $ids[] = $returnCrashId;
$db->update('le_delivery_outbox', ['state' => 'sending', 'attempts' => 1], ['id' => $returnCrashId]);
$before = count($api->calls); $outbox->process($returnCrashId);
verify(count($api->calls) === $before && $db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$returnCrashId]) === 'uncertain', 'Crashed Return worker never resends an uncertain event');
$rejected = new LoyaltyEngage\Message\FreeProductPurchaseMessage('qa@example.test', $prefix . '-rejected', [], 'local');
$rejectedId = $outbox->enqueue($rejected); $ids[] = $rejectedId;
$api->status = 400; $outbox->process($rejectedId);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$rejectedId]) === 'rejected', 'Functional rejection is retained for operator attention');
$exhaustedId = $outbox->enqueue(new LoyaltyEngage\Message\FreeProductPurchaseMessage('qa@example.test', $prefix . '-exhausted', [], 'local')); $ids[] = $exhaustedId;
$db->update('le_delivery_outbox', ['attempts' => 11], ['id' => $exhaustedId]);
$api->status = 429; $outbox->process($exhaustedId);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$exhaustedId]) === 'exhausted', 'Retry limit retains failed delivery without endless sends');
$db->beginTransaction();
$transactionId = $outbox->enqueue(new LoyaltyEngage\Message\FreeProductPurchaseMessage('qa@example.test', $prefix . '-transaction', [], 'local')); $ids[] = $transactionId;
$before = count($api->calls); $outbox->process($transactionId);
verify(count($api->calls) === $before, 'No external mutation before surrounding transaction commits');
$db->rollBack();
$voucher = new LoyaltyEngage\Message\VoucherRedemptionMessage('qa@example.test', $prefix . '-voucher', 'local');
$voucherId = $outbox->enqueue($voucher); $ids[] = $voucherId;
$api->status = 200; $outbox->process($voucherId);
$before = count($api->calls); $outbox->dispatch($voucher);
verify(count($api->calls) === $before && $db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$voucherId]) === 'succeeded', 'Voucher remote redemption succeeds once');
$failedVoucherId = $outbox->enqueue(new LoyaltyEngage\Message\VoucherRedemptionMessage('qa@example.test', $prefix . '-failed-voucher', 'local')); $ids[] = $failedVoucherId;
$api->status = null; $outbox->process($failedVoucherId);
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$failedVoucherId]) === 'uncertain', 'Failed remote voucher sync retained for reconciliation');
verify(!$outbox->requeue($failedVoucherId) && !$outbox->requeue($returnId), 'Operator command cannot blindly replay uncertain non-idempotent operations');
verify($outbox->requeue($rejectedId) && $outbox->requeue($exhaustedId), 'Operator can recover rejected/exhausted idempotent deliveries');
$api->status = 200;
$outbox->retryDue();
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$rejectedId]) === 'succeeded'
    && $db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$exhaustedId]) === 'succeeded', 'Scheduled retry scanner processes due, requeued operations');
verify($db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$returnId]) === 'uncertain'
    && $db->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$failedVoucherId]) === 'uncertain', 'Retry scanner leaves uncertain returns and voucher sync untouched');
echo "Delivery outbox integration passed; all provider calls mocked.\n";

$order = new Shopware\Core\Checkout\Order\OrderEntity();
$order->setId(str_repeat('a', 32));
$order->setOrderNumber($prefix);
$lines = new Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection();
foreach ([['A', 3, 10], ['B', 1, 20], ['promotion', 1, -1]] as [$sku, $quantity, $price]) {
    $line = new Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity();
    $line->setId(md5($sku)); $line->setType($sku === 'promotion' ? 'promotion' : 'product');
    $line->setProductId(md5($sku)); $line->setPayload(['productNumber' => $sku]);
    $line->setQuantity($quantity); $line->setUnitPrice($price); $line->setTotalPrice($quantity * $price);
    $lines->add($line);
}
$order->setLineItems($lines);
$deliveries = new Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection();
foreach ([1 => ['A' => 2], 2 => ['A' => 1, 'B' => 1]] as $n => $quantities) {
    $delivery = new Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity();
    $delivery->setId(str_repeat((string) $n, 32));
    $positions = new Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection();
    foreach ($quantities as $sku => $quantity) {
        $position = new Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionEntity();
        $position->setId(md5($n . $sku)); $position->setOrderLineItemId(md5($sku)); $position->setQuantity($quantity);
        $positions->add($position);
    }
    $delivery->setPositions($positions); $deliveries->add($delivery);
}
$order->setDeliveries($deliveries);
$subscriber = (new ReflectionClass(LoyaltyEngage\Subscriber\ReturnSubscriber::class))->newInstanceWithoutConstructor();
$testApi = new class extends LoyaltyEngage\Service\LoyaltyEngageApiService {
    public function __construct() {}
    public function getLoggerStatus(): bool { return false; }
};
(new ReflectionProperty($subscriber, 'loyaltyEngageApiService'))->setValue($subscriber, $testApi);
verify($subscriber->returnedProducts($order, str_repeat('1', 32)) === [['sku' => 'A', 'price' => 9.8, 'quantity' => 2]], 'First return excludes B and returns two of three A at discounted unit price');
verify($subscriber->returnedProducts($order, str_repeat('2', 32)) === [['sku' => 'A', 'price' => 9.8, 'quantity' => 1], ['sku' => 'B', 'price' => 19.6, 'quantity' => 1]], 'Second delivery returns only remaining positions');
verify($subscriber->returnedProducts($order, str_repeat('3', 32)) === [], 'Unknown delivery never falls back to the full order');
