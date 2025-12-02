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
     * @var int
     */
    private $quantity;

    /**
     * @param string $email
     * @param string $productId
     * @param int $quantity
     */
    public function __construct(string $email, string $productId, int $quantity)
    {
        $this->email = $email;
        $this->productId = $productId;
        $this->quantity = $quantity;
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
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
