<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

class FreeProductRemoveMessage
{
    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $productId;

    /**
     * The product SKU (productNumber) used by the LoyaltyEngage API.
     * This is different from the Shopware product UUID ($productId).
     *
     * @var string
     */
    private $sku;

    /**
     * @var int
     */
    private $quantity;

    /**
     * @param string $email
     * @param string $productId  Shopware product UUID (for logging)
     * @param string $sku        Product SKU / productNumber (sent to LoyaltyEngage API)
     * @param int    $quantity
     */
    public function __construct(string $email, string $productId, string $sku, int $quantity)
    {
        $this->email     = $email;
        $this->productId = $productId;
        $this->sku       = $sku;
        $this->quantity  = $quantity;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return string
     */
    public function getProductId(): string
    {
        return $this->productId;
    }

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
