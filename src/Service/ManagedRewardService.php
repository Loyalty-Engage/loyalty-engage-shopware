<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ManagedRewardService
{
    public function __construct(
        private readonly LoyaltyProductCatalog $catalog,
        private readonly LoyaltyShopEligibility $eligibility,
        private readonly LoyaltyShopService $legacyShop,
        private readonly LoyaltyEngageApiService $api,
        private readonly CartService $carts,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly RewardAuthorization $authorization
    ) {}

    public function redeem(string $sku, string $type, string $requestId, SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        if (!$customer || $customer->getGuest()) { return $this->error('Log in om punten in te wisselen.'); }
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $requestId)) { return $this->error('Ongeldige aanvraagcode. Vernieuw de pagina.'); }
        $scope = hash('sha256', $context->getSalesChannelId() . $customer->getId());
        $lock = 'le-reward-' . substr($scope, 0, 48);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 0)', ['name' => $lock]) !== 1) {
            return $this->error('Een andere puntenaanvraag wordt verwerkt. Probeer zo opnieuw.');
        }
        $key = hash('sha256', $scope . $requestId);
        $reserved = false;
        try {
            $previous = $this->connection->fetchOne('SELECT response FROM le_reward_request WHERE id = :id', ['id' => $key]);
            if ($previous !== false) {
                return $previous ? json_decode($previous, true, 512, JSON_THROW_ON_ERROR)
                    : $this->error('Deze aanvraag is al gestart. Controleer de verwerking voordat u opnieuw probeert.');
            }
            $product = $this->catalog->find($sku, $context);
            if (!$product) { return $this->error('Deze loyaltybeloning is niet beschikbaar.'); }
            $fields = $this->catalog->fields($product, $context->getSalesChannelId());
            if (($fields['le_shop_type'] ?? 'physical') !== $type) { return $this->error('Ongeldig beloningstype.'); }
            if ($type === 'discount_code' && !empty($fields['le_shop_discount_currency'])
                && $fields['le_shop_discount_currency'] !== $context->getCurrency()->getIsoCode()) {
                return $this->error('Deze kortingsbeloning is niet beschikbaar in de gekozen valuta.');
            }
            $cart = $this->carts->getCart($context->getToken(), $context);
            $reason = $this->eligibility->cartError($cart, $context, $type === 'physical') ?? $this->eligibility->productError($product, $context);
            if ($reason) { return $this->error($reason); }
            $id = substr(hash('sha256', 'le-reward-' . $sku), 0, 32);
            if ($type === 'physical' && $cart->has($id)) { return $this->error('Deze beloning staat al in uw winkelwagen.'); }

            $this->connection->insert('le_reward_request', ['id' => $key, 'response' => null, 'created_at' => date('Y-m-d H:i:s')]);
            if ($type === 'discount_code') {
                $discountType = $fields['le_shop_discount_type'] ?? 'absolute';
                if (!in_array($discountType, ['absolute', 'percentage'], true)
                    || !is_numeric($fields['le_shop_discount_value'] ?? null) || (float) $fields['le_shop_discount_value'] <= 0
                    || ($discountType === 'percentage' && (float) $fields['le_shop_discount_value'] > 100)) {
                    $result = $this->error('Configureer eerst een geldige kortingswaarde bij het beloningsproduct.');
                } else {
                    // The browser cannot choose the discount amount or type.
                    $result = $this->legacyShop->claimDiscountCodeProduct($customer->getEmail(), $sku, (float) $fields['le_shop_discount_value'], $context, $discountType, true);
                }
            } else {
                $status = $this->api->addToCart($customer->getEmail(), $sku, $context->getSalesChannelId());
                if ($status !== 200) {
                    $result = $this->error('Loyalty Engage kon deze beloning niet reserveren. Controleer uw punten en probeer later opnieuw.');
                } else {
                    $reserved = true;
                    $item = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId(), 1);
                    $item->setStackable(false);
                    $item->setRemovable(true);
                    $item->setPayloadValue('loyaltyFreeProduct', true);
                    $item->setPayloadValue('leManagedReward', true);
                    $item->setPayloadValue('productNumber', $sku);
                    $item->setPayloadValue('leRewardRequestKey', $key);
                    $item->setPayloadValue('leRewardAuthorization', $this->authorization->sign($item, $context));
                    $this->connection->update('le_reward_request', ['active' => 1], ['id' => $key]);
                    $cart = $this->carts->add($cart, $item, $context);
                    if (!$cart->has($id)) { throw new \RuntimeException('Shopware did not add the reserved reward.'); }
                    $reserved = false;
                    $result = ['success' => true, 'message' => 'De beloning is toegevoegd aan uw winkelwagen.'];
                }
            }
            $this->connection->update('le_reward_request', ['response' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $key]);
            return $result;
        } catch (\Throwable $exception) {
            if ($reserved) {
                $this->authorization->revoke($key);
                $status = $this->api->removeItem($customer->getEmail(), $sku, 1, $context->getSalesChannelId());
                $this->logger->warning('Loyalty shop: compensating failed cart add', ['sku' => $sku, 'status' => $status]);
            }
            $this->logger->error('Loyalty shop: reward request failed', ['sku' => $sku, 'error' => $exception->getMessage()]);
            return $this->error($exception instanceof LoyaltyFeedUnavailable ? $exception->getMessage() : 'De aanvraag kon niet worden afgerond. Controleer uw beloningen voordat u opnieuw probeert.');
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        }
    }

    private function error(string $message): array { return ['success' => false, 'message' => $message]; }
}
