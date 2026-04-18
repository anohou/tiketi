<?php

namespace App\Rules;

use App\Support\TenantDomainPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedTenantDomain implements ValidationRule
{
    public static function normalize(mixed $value): string
    {
        return TenantDomainPolicy::normalize($value);
    }

    public static function isReservedTiketiDomain(mixed $value): bool
    {
        return TenantDomainPolicy::isReservedTiketiDomain($value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isReservedTiketiDomain($value)) {
            $fail(TenantDomainPolicy::RESERVED_MESSAGE);
        }
    }
}
