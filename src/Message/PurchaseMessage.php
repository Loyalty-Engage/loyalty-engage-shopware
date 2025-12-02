<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

class PurchaseMessage
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
     * @var string
     */
    private $orderDate;

    /**
     * @var array
     */
    private $products;

    /**
     * @param string $email
     * @param string $orderId
     * @param string $orderDate
     * @param array $products
     */
    public function __construct(string $email, string $orderId, string $orderDate, array $products)
    {
        $this->email = $email;
        $this->orderId = $orderId;
        $this->orderDate = $orderDate;
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
     * @return string
     */
    public function getOrderDate(): string
    {
        return $this->orderDate;
    }

    /**
     * @return array
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    /**
     * Get the message payload as an array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            [
                'event' => 'Purchase',
                'email' => $this->email,
                'orderId' => $this->orderId,
                'orderDate' => $this->orderDate,
                'products' => $this->products
            ]
        ];
    }
}
