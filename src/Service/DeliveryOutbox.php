<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LoyaltyEngage\Message\FreeProductPurchaseMessage;
use LoyaltyEngage\Message\PurchaseMessage;
use LoyaltyEngage\Message\ReturnMessage;
use LoyaltyEngage\Message\VoucherRedemptionMessage;
use Psr\Log\LoggerInterface;

class DeliveryOutbox
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoyaltyEngageApiService $api,
        private readonly LoggerInterface $logger
    ) {}

    public function dispatch(object $message): bool
    {
        $id = $this->enqueue($message);
        $this->process($id);
        return $this->connection->fetchOne('SELECT state FROM le_delivery_outbox WHERE id = ?', [$id]) === 'succeeded';
    }

    public function enqueue(object $message): string
    {
        if ($message instanceof FreeProductPurchaseMessage) {
            $kind = 'cart_purchase';
            $identity = $message->getOrderId();
            $payload = ['email' => $message->getEmail(), 'orderId' => $identity, 'products' => $message->getProducts()];
        } elseif ($message instanceof PurchaseMessage) {
            $kind = 'purchase';
            $identity = $message->getOrderId();
            $payload = $message->toArray();
        } elseif ($message instanceof ReturnMessage) {
            $kind = 'return';
            $payload = $message->toArray();
            $identity = $message->getSourceId() ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } elseif ($message instanceof VoucherRedemptionMessage) {
            $kind = 'voucher_redeem';
            $identity = $message->getCode();
            $payload = ['email' => $message->getEmail(), 'code' => $message->getCode()];
        } else {
            throw new \InvalidArgumentException('Unsupported loyalty delivery message');
        }

        $channel = $message->getSalesChannelId();
        $id = hash('sha256', $kind . ':' . ($channel ?? 'global') . ':' . $identity);
        $now = gmdate('Y-m-d H:i:s');
        try {
            // First payload wins, including on queue redelivery or repeated state transitions.
            $this->connection->insert('le_delivery_outbox', [
                'id' => $id, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'sales_channel_id' => $channel, 'retry_safe' => (int) in_array($kind, ['cart_purchase', 'purchase'], true),
                'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Keep the original operation and its state; never reset a successful send.
        }

        return $id;
    }

    public function process(string $id): void
    {
        if (method_exists($this->connection, 'ensureConnectedToPrimary')) {
            $this->connection->ensureConnectedToPrimary();
        }
        // Never spend points before the surrounding order transaction is committed.
        if ($this->connection->isTransactionActive()) {
            return;
        }
        // A connection-scoped lock survives DB commits, but is released if the worker dies.
        // Its 64-character name also supports older MySQL versions used by Shopware 6.5.
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$id]) !== 1) {
            return;
        }
        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM le_delivery_outbox WHERE id = ?', [$id]);
            if (!$row || in_array($row['state'], ['succeeded', 'rejected', 'uncertain', 'exhausted'], true)) {
                return;
            }
            if ($row['state'] === 'sending' && !(bool) $row['retry_safe']) {
                $this->finish($id, 'uncertain', null, (int) $row['attempts']);
                return;
            }
            if ((int) $row['attempts'] >= 12) {
                $this->finish($id, 'exhausted', $row['last_status'] !== null ? (int) $row['last_status'] : null, (int) $row['attempts']);
                return;
            }
            if ($row['state'] !== 'sending' && $row['next_attempt_at'] > gmdate('Y-m-d H:i:s')) {
                return;
            }
            $attempts = (int) $row['attempts'] + 1;
            $this->connection->update('le_delivery_outbox', [
                'state' => 'sending', 'attempts' => $attempts, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $status = null;
            try {
                if ($row['kind'] === 'cart_purchase') {
                    $result = $this->api->placeOrderWithResponse($payload['email'], $payload['orderId'], $payload['products'], $row['sales_channel_id']);
                    $status = $result['statusCode'] ?? null;
                } elseif ($row['kind'] === 'voucher_redeem') {
                    $status = $this->api->redeemDiscountCode($payload['email'], $payload['code'], $row['sales_channel_id']) ? 200 : null;
                } else {
                    $status = $this->api->sendEvent($payload, $row['sales_channel_id']);
                }
            } catch (\Throwable $e) {
                // Do not store raw responses, credentials or email addresses in delivery logs.
                $this->logger->error('Loyalty delivery raised an exception', ['deliveryId' => $id, 'exceptionType' => get_class($e)]);
            }
            if ($status === 200) {
                $state = 'succeeded';
            } elseif (!(bool) $row['retry_safe']) {
                $state = 'uncertain';
            } elseif ($status !== null && $status >= 400 && $status < 500 && !in_array($status, [408, 429], true)) {
                $state = 'rejected';
            } else {
                $state = $attempts >= 12 ? 'exhausted' : 'pending';
            }
            $this->finish($id, $state, $status, $attempts);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$id]);
        }
    }

    public function retryDue(int $limit = 100): void
    {
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM le_delivery_outbox
            WHERE (state = 'pending' AND next_attempt_at <= UTC_TIMESTAMP()) OR state = 'sending'
            ORDER BY next_attempt_at LIMIT " . max(1, min(1000, $limit)));
        foreach ($ids as $id) {
            $this->process($id);
        }
    }

    public function requeue(string $id): bool
    {
        return $this->connection->executeStatement("UPDATE le_delivery_outbox
            SET state = 'pending', attempts = 0, next_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND retry_safe = 1 AND state IN ('rejected', 'exhausted')", [$id]) === 1;
    }

    private function finish(string $id, string $state, ?int $status, int $attempts): void
    {
        $this->connection->update('le_delivery_outbox', [
            'state' => $state, 'last_status' => $status,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + min(86400, 60 * (2 ** min($attempts, 10)))),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        if ($state !== 'succeeded') {
            $this->logger->error('Loyalty delivery requires retry or attention', [
                'deliveryId' => $id, 'state' => $state, 'attempts' => $attempts, 'status' => $status,
            ]);
        }
    }
}
