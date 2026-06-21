<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TicketSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OkohiDeleteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->header('X-Okohi-Integration-Key');

        if (! $key) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (tenancy()->initialized) {
            return $this->handle($key);
        }

        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            return $this->handle($key);
        }

        $deleted = false;

        $tenants->each(function (Tenant $tenant) use ($key, &$deleted) {
            if ($deleted) {
                return false;
            }

            tenancy()->initialize($tenant);

            $settings = TicketSetting::where('okohi_integration_key', $key)->first();

            if ($settings) {
                $settings->update([
                    'okohi_integration_url' => null,
                    'okohi_integration_key' => null,
                ]);
                $deleted = true;
            }

            tenancy()->end();
        });

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json(['success' => true]);
    }

    private function handle(string $key): JsonResponse
    {
        $settings = TicketSetting::where('okohi_integration_key', $key)->first();

        if (! $settings) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $settings->update([
            'okohi_integration_url' => null,
            'okohi_integration_key' => null,
        ]);

        return response()->json(['success' => true]);
    }
}
