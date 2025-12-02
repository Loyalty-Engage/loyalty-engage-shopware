<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

class FreeProductPurchaseMessage
{
    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $orderId;

    /**
     * @var array
     */
    private $products;

    /**
     * @param string $email
     * @param string $orderId
     * @param array $products
     */
    public function __construct(string $email, string $orderId, array $products)
    {
        $this->email = $email;
        $this->orderId = $orderId;
        $this->products = $products;
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
    public function getOrderId(): string
    {
        return $this->orderId;
    }

    /**
     * @return array
     */
    public function getProducts(): array
    {
        return $this->products;
    }
}
