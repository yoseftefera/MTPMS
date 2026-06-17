<?php

namespace App\Exceptions;

use RuntimeException;

class BudgetExceededException extends RuntimeException
{
    public function __construct(
        private readonly string $availableBalance,
        private readonly string $requestedAmount,
        private readonly string $shortfall,
    ) {
        parent::__construct(
            "Purchase request total ({$requestedAmount}) exceeds available budget balance ({$availableBalance}). Shortfall: {$shortfall}."
        );
    }

    public function getAvailableBalance(): string
    {
        return $this->availableBalance;
    }

    public function getRequestedAmount(): string
    {
        return $this->requestedAmount;
    }

    public function getShortfall(): string
    {
        return $this->shortfall;
    }
}
