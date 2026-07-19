<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TicketSetting;
use Illuminate\Http\Request;

class OkohiWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $body = $request->getContent();
        $received = $request->header('X-Okohi-Signature');
        $event = $request->header('X-Okohi-Event') ?? $request->input('event');

        // Find the tenant whose integration key matches the HMAC signature
        $matched = null;

        foreach (Tenant::cursor() as $tenant) {
            $tenant->run(function () use ($body, $received, $tenant, &$matched) {
                $settings = TicketSetting::getSettings();

                if (! $settings->okohi_integration_key) {
                    return;
                }

                $expected = hash_hmac('sha256', $body, $settings->okohi_integration_key);

                if (hash_equals($expected, (string) $received)) {
                    $matched = $tenant;
                }
            });

            if ($matched) {
                break;
            }
        }

        // Always return 200 to prevent Okohi retries, even for unknown claims
        if (! $matched) {
            return response()->json(['ok' => true]);
        }

        $payload = $request->all();
        $claimId = $payload['claim_id'] ?? null;
        $status = $payload['status'] ?? null;

        if (! $claimId || ! $status) {
            return response()->json(['ok' => true]);
        }

        // Broadcast the claim status update to the frontend via websocket
        $matched->run(function () use ($claimId, $status, $payload) {
            event(new \App\Events\OkohiClaimUpdated($claimId, $status, $payload));
        });

        return response()->json(['ok' => true]);
    }
}
