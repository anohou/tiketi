<?php

namespace App\Domain\Ticketing;

use RuntimeException;

final class TicketingRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
