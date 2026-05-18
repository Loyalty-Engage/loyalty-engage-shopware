<?php declare(strict_types=1);

namespace LoyaltyEngage\Message;

/**
 * Message dispatched when a loyalty free product is removed from the cart.
 *
 * Instead of calling removeItem() per product (which causes a burst of API calls
 * and triggers rate limiting), we dispatch a single removeAllItems() call per
 * customer. The message is delayed by 5 seconds so that if multiple products are
 * removed in quick succession, only one API call is made after the dust settles.
 */
class FreeProductRemoveMessage
{
    private string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
