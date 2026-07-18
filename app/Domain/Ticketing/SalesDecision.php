<?php

namespace App\Domain\Ticketing;

final readonly class SalesDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reasonCode = null,
        public ?string $message = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reasonCode, string $message): self
    {
        return new self(false, $reasonCode, $message);
    }
}
