<?php declare(strict_types=1);

namespace LoyaltyEngage\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface LoyaltyCartApiInterface
{
    /**
     * Add a product to the cart using loyalty points
     *
     * @param string $email Customer email
     * @param string $productId Product ID
     * @param SalesChannelContext $context
     * @return array
     */
    public function addProduct(string $email, string $productId, SalesChannelContext $context): array;

    /**
     * Remove a product from the cart
     *
     * @param string $email Customer email
     * @param string $productId Product ID
     * @param SalesChannelContext $context
     * @return array
     */
    public function removeProduct(string $email, string $productId, SalesChannelContext $context): array;

    /**
     * Remove all products from the cart
     *
     * @param string $email Customer email
     * @param SalesChannelContext $context
     * @return array
     */
    public function removeAllProducts(string $email, SalesChannelContext $context): array;

    /**
     * Claim a discount after adding a product to the loyalty cart
     *
     * @param string $email Customer email
     * @param string $productId Product ID
     * @param float $discount Discount amount (0.1 = 10%)
     * @param SalesChannelContext $context
     * @return array
     */
    public function claimDiscountAfterAddToCart(string $email, string $productId, float $discount, SalesChannelContext $context): array;
}
