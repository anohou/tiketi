<?php

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $input = trim($value);
        $international = str_starts_with($input, '+') || str_starts_with($input, '00');
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryCode = (string) config('transport.crew_auth.default_country_code', '225');
        if (! $international && ! str_starts_with($digits, $countryCode)) {
            $digits = $countryCode.$digits;
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}
