<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final class VoucherPromotionConfig
{
    public static function identity(string $legacyName): string
    {
        return hash('sha256', $legacyName);
    }

    public static function ruleId(string $promotionId): string
    {
        return Uuid::fromStringToHex('loyalty-voucher-minimum:' . $promotionId);
    }

    public static function payload(string $promotionId, string $legacyName, string $sku, array $response, string $languageId, array $orderRuleIds = [], array $translationIds = []): array
    {
        $title = $response['product']['title'] ?? null;
        $title = is_string($title) && trim($title) !== '' ? trim($title) : $sku;
        $title = mb_substr($title, 0, 255);
        $translations = [];
        foreach (array_unique(array_merge([Defaults::LANGUAGE_SYSTEM, $languageId], $translationIds)) as $id) {
            $translations[$id] = ['languageId' => $id, 'name' => $title,
                'customFields' => ['le_voucher_identity' => self::identity($legacyName)]];
        }
        $data = ['name' => $title, 'translations' => $translations];

        // Older API/bridge responses do not supply this field. Preserve their existing rules.
        if (!array_key_exists('discountMinimumOrderValue', $response)) {
            return $data;
        }
        $minimum = $response['discountMinimumOrderValue'];
        if ((!is_int($minimum) && !is_float($minimum)) || !is_finite((float) $minimum) || $minimum < 0 || $minimum > 100000000) {
            throw new \UnexpectedValueException('Invalid voucher minimum order value.');
        }
        $ruleId = self::ruleId($promotionId);
        // Shopware ORs orderRules. Never add an alternative that bypasses merchant rules.
        if (array_diff($orderRuleIds, [$ruleId])) {
            throw new \RuntimeException('Voucher promotion has manual order rules; minimum cannot safely be synchronized.');
        }
        $data['orderRules'] = [[
            'id' => $ruleId,
            'name' => 'LoyaltyEngage minimum: ' . $promotionId,
            'priority' => 1,
            'conditions' => [[
                'id' => Uuid::fromStringToHex('loyalty-voucher-minimum-condition:' . $promotionId),
                'type' => 'cartCartAmount',
                'value' => ['operator' => '>=', 'amount' => (float) $minimum],
            ]],
        ]];

        return $data;
    }
}
