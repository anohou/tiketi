<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OkohiRewardsController extends Controller
{
    /**
     * Build the partner API base URL from settings.
     */
    private function partnerUrl(): string
    {
        $base = rtrim(config('services.okohi.base_url', 'http://127.0.0.1:8001'), '/');

        if (! str_contains($base, '/api/v1/partner')) {
            $base .= '/api/v1/partner';
        }

        return $base;
    }

    /**
     * Return the integration key header array.
     */
    private function headers(): array
    {
        return [
            'X-Okohi-Integration-Key' => TicketSetting::getSettings()->okohi_integration_key,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Ensure integration is configured before proceeding.
     */
    private function ensureConnected(): void
    {
        if (! TicketSetting::getSettings()->hasOkohiIntegration()) {
            abort(400, 'Intégration Okohi non configurée.');
        }
    }

    /**
     * GET /admin/settings/loyalty/rewards
     * List all rewards for the connected Okohi company.
     */
    public function index(): JsonResponse
    {
        $this->ensureConnected();

        $response = \Http::timeout(15)
            ->withHeaders($this->headers())
            ->get($this->partnerUrl() . '/rewards');

        return $this->forwardOkohiResponse($response);
    }

    /**
     * POST /admin/settings/loyalty/rewards
     * Create a new reward in Okohi.
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureConnected();

        $response = \Http::timeout(15)
            ->withHeaders($this->headers())
            ->post($this->partnerUrl() . '/rewards', $request->all());

        return $this->forwardOkohiResponse($response);
    }

    /**
     * GET /admin/settings/loyalty/rewards/{id}
     * Show a single reward.
     */
    public function show(string $id): JsonResponse
    {
        $this->ensureConnected();

        $response = \Http::timeout(15)
            ->withHeaders($this->headers())
            ->get($this->partnerUrl() . '/rewards/' . $id);

        return $this->forwardOkohiResponse($response);
    }

    /**
     * PUT /admin/settings/loyalty/rewards/{id}
     * Update a reward in Okohi.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->ensureConnected();

        $response = \Http::timeout(15)
            ->withHeaders($this->headers())
            ->put($this->partnerUrl() . '/rewards/' . $id, $request->all());

        return $this->forwardOkohiResponse($response);
    }

    /**
     * DELETE /admin/settings/loyalty/rewards/{id}
     * Delete a reward in Okohi.
     */
    public function destroy(string $id): JsonResponse
    {
        $this->ensureConnected();

        $response = \Http::timeout(15)
            ->withHeaders($this->headers())
            ->delete($this->partnerUrl() . '/rewards/' . $id);

        return $this->forwardOkohiResponse($response);
    }

    /**
     * Forward the Okohi API response back to the client.
     * Preserves status code and body structure.
     */
    private function forwardOkohiResponse($response): JsonResponse
    {
        $body = $response->json() ?? [];

        if ($response->status() === 401) {
            return response()->json(['error' => 'Clé d\'intégration invalide'], 401);
        }

        if ($response->status() === 403) {
            return response()->json(['error' => 'Intégration désactivée côté Okohi'], 403);
        }

        if ($response->status() === 422) {
            return response()->json(
                ['error' => 'Validation échouée', 'errors' => $body['data'] ?? $body],
                422
            );
        }

        if (! $response->successful()) {
            $msg = $body['message'] ?? $body['data']['message'] ?? 'Erreur lors de l\'appel à Okohi.';

            return response()->json(['error' => $msg], $response->status());
        }

        return response()->json($body['data'] ?? $body);
    }
}