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

    private ?string $sourceId = null;
    private ?string $orderId = null;

    /**
     * @param string $email
     * @param string $returnDate
     * @param array $products
     */
    public function __construct(string $email, string $returnDate, array $products, ?string $salesChannelId = null, ?string $sourceId = null, ?string $orderId = null)
    {
        $this->email = $email;
        $this->returnDate = $returnDate;
        $this->products = $products;
        $this->salesChannelId = $salesChannelId;
        $this->sourceId = $sourceId;
        $this->orderId = $orderId;
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

    public function getSourceId(): ?string { return $this->sourceId; }

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
                ...($this->orderId !== null ? ['orderId' => $this->orderId] : []),
                'email' => $this->email,
                'orderDate' => $this->returnDate,
                'products' => $this->products
            ]
        ];
    }
}
