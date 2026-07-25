<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TicketSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class OkohiSignatureVerification
{
    /**
     * Handle an incoming request for Okohi API endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle tenant initialization if tenant param is provided
        $tenantId = (string) $request->query('tenant', '');
        if ($tenantId !== '' && function_exists('tenancy') && ! tenancy()->initialized) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }

        // Scan tenants if not initialized and tenants exist
        if (function_exists('tenancy') && ! tenancy()->initialized && class_exists(Tenant::class)) {
            $tenants = Tenant::all();
            if ($tenants->isNotEmpty()) {
                $matchedTenant = null;
                $receivedSignature = (string) $request->header('X-Okohi-Signature');
                $receivedKey = (string) $request->header('X-Okohi-Integration-Key');

                foreach ($tenants as $tenant) {
                    $tenant->run(function () use ($request, $receivedSignature, $receivedKey, &$matchedTenant) {
                        $settings = TicketSetting::getSettings();
                        $secret = (string) $settings->okohi_integration_key;
                        if ($secret === '') {
                            return;
                        }

                        $rawBody = (string) $request->getContent();
                        $fullUrl = $request->fullUrl();
                        $method = $request->getMethod();
                        $tsHeader = (string) $request->header('X-Okohi-Timestamp', '');
                        $nonceHeader = (string) $request->header('X-Okohi-Nonce', '');
                        $canonicalPayload = implode('|', array_filter([$method, $fullUrl, $tsHeader, $nonceHeader, $rawBody]));

                        $sig1 = hash_hmac('sha256', $rawBody, $secret);
                        $sig2 = hash_hmac('sha256', $canonicalPayload, $secret);

                        if (($receivedSignature !== '' && (hash_equals($sig1, $receivedSignature) || hash_equals($sig2, $receivedSignature)))
                            || ($receivedKey !== '' && hash_equals($secret, $receivedKey))) {
                            $matchedTenant = $tenant;
                        }
                    });

                    if ($matchedTenant) {
                        tenancy()->initialize($matchedTenant);
                        break;
                    }
                }

                if (! $matchedTenant && tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $settings = TicketSetting::getSettings();
        $secret = (string) $settings->okohi_integration_key;

        if ($secret === '') {
            return $next($request);
        }

        $receivedSignature = (string) ($request->headers->get('x-okohi-signature') ?? '');
        $receivedKey = (string) ($request->headers->get('x-okohi-integration-key') ?? '');

        // Check HMAC signature if present
        if ($receivedSignature !== '') {
            // Anti-replay protection via timestamp & nonce if present
            $timestamp = (int) ($request->headers->get('x-okohi-timestamp') ?? 0);
            if ($timestamp > 0) {
                if (abs(time() - $timestamp) > 300) { // 5 minutes window
                    return response()->json(['valid' => false, 'message' => 'Request timestamp out of bounds'], 401);
                }

                $nonce = (string) ($request->headers->get('x-okohi-nonce') ?? '');
                if ($nonce !== '') {
                    $cacheKey = "okohi_nonce_{$nonce}";
                    if (Cache::has($cacheKey)) {
                        return response()->json(['valid' => false, 'message' => 'Replay attack detected'], 401);
                    }
                    Cache::put($cacheKey, true, 300);
                }
            }

            $rawBody = (string) $request->getContent();
            $fullUrl = $request->fullUrl();
            $method = $request->getMethod();
            $tsHeader = (string) ($request->headers->get('x-okohi-timestamp') ?? '');
            $nonceHeader = (string) ($request->headers->get('x-okohi-nonce') ?? '');

            $canonicalPayload = implode('|', array_filter([$method, $fullUrl, $tsHeader, $nonceHeader, $rawBody]));

            $sig1 = hash_hmac('sha256', $rawBody, $secret);
            $sig2 = hash_hmac('sha256', $canonicalPayload, $secret);

            if (! hash_equals($sig1, $receivedSignature) && ! hash_equals($sig2, $receivedSignature)) {
                return response()->json(['valid' => false, 'message' => 'Invalid signature'], 401);
            }

            return $next($request);
        }

        // Fallback for legacy key header
        if ($receivedKey !== '' && hash_equals($secret, $receivedKey)) {
            return $next($request);
        }

        return response()->json(['valid' => false, 'message' => 'Unauthorized'], 401);
    }
}
