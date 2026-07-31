<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

class ReturnMessage
{
    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $returnDate;

    /**
     * @var array
     */
    private $products;

    /**
     * @var string|null
     */
    private $salesChannelId;

    /**
     * @param string $email
     * @param string $returnDate
     * @param array $products
     */
    public function __construct(string $email, string $returnDate, array $products, ?string $salesChannelId = null)
    {
        $this->email = $email;
        $this->returnDate = $returnDate;
        $this->products = $products;
        $this->salesChannelId = $salesChannelId;
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
    public function getReturnDate(): string
    {
        return $this->returnDate;
    }

    /**
     * @return array
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
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
                'event' => 'Return',
                'email' => $this->email,
                'orderDate' => $this->returnDate,
                'products' => $this->products
            ]
        ];
    }
}
