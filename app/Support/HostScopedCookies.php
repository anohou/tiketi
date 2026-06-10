<?php

namespace App\Support;

use Illuminate\Support\Str;

class HostScopedCookies
{
    public static function sessionCookieName(?string $host): string
    {
        $base = env('SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_session');

        return self::sanitizeCookieName($base).'__'.self::normalizeHost($host);
    }

    public static function xsrfCookieName(?string $host): string
    {
        return 'XSRF-TOKEN__'.self::normalizeHost($host);
    }

    protected static function normalizeHost(?string $host): string
    {
        $normalized = Str::of($host ?? 'unknown')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return $normalized !== '' ? $normalized : 'unknown';
    }

    protected static function sanitizeCookieName(string $name): string
    {
        $sanitized = Str::of($name)
            ->replaceMatches('/[^A-Za-z0-9_-]+/', '_')
            ->trim('_-')
            ->value();

        return $sanitized !== '' ? $sanitized : 'laravel_session';
    }
}
