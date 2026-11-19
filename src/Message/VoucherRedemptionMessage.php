<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

class VoucherRedemptionMessage
{
    public function __construct(
        private readonly string $email,
        private readonly string $code,
        private readonly ?string $salesChannelId
    ) {}

    public function getEmail(): string { return $this->email; }
    public function getCode(): string { return $this->code; }
    public function getSalesChannelId(): ?string { return $this->salesChannelId; }
}
