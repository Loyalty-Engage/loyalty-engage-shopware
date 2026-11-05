<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RewardAuthorization
{
    public function __construct(private readonly string $secret, private readonly Connection $connection) {}

    public function sign(LineItem $item, SalesChannelContext $context): string
    {
        return hash_hmac('sha256', implode('|', [$context->getToken(), $context->getSalesChannelId(), $context->getCustomer()?->getId(), $item->getId(), $item->getReferencedId(), $item->getQuantity(), $item->getPayloadValue('productNumber'), $item->getPayloadValue('leRewardRequestKey')]), $this->secret);
    }

    public function valid(LineItem $item, SalesChannelContext $context): bool
    {
        $signature = $item->getPayloadValue('leRewardAuthorization');
        return is_string($signature) && hash_equals($this->sign($item, $context), $signature)
            && (bool) $this->connection->fetchOne('SELECT active FROM le_reward_request WHERE id = :id', ['id' => $item->getPayloadValue('leRewardRequestKey')]);
    }

    public function revoke(string $key): void
    {
        $this->connection->update('le_reward_request', ['active' => 0], ['id' => $key]);
    }
}
