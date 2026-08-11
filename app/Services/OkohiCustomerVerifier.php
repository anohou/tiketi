<?php

namespace App\Services;

use App\Models\TicketSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Vérification de l'identité client Okohi (point 5).
 *
 * Une regex de format ne suffit pas : avant d'enregistrer okohi_customer_number
 * sur un billet, on interroge Okohi via le mécanisme partenaire authentifié et
 * on utilise le numéro CANONIQUE retourné par Okohi — jamais la valeur brute
 * du navigateur.
 *
 * Politique en cas de panne Okohi : la vente n'est PAS bloquée, mais le billet
 * n'est jamais rattaché arbitrairement à un portefeuille non vérifié.
 */
final class OkohiCustomerVerifier
{
    /**
     * Vérifie un client Okohi.
     *
     * @return array{verified: bool, canonical_number: string|null, error: string|null}
     *         verified=false + error='unreachable' → panne Okohi (vente possible,
     *         billet non rattaché) ;
     *         verified=false + error='not_found' → numéro inexistant (vente possible,
     *         billet non rattaché, avertissement) ;
     *         verified=true → numéro canonique d'Okohi.
     */
    public function verify(string $customerNumber, TicketSetting $settings): array
    {
        // Format minimal de sécurité (le format réel Okohi est OKH-XXXXXX).
        if (! preg_match('/^OKH-[A-Za-z0-9]{4,32}$/', $customerNumber)) {
            return ['verified' => false, 'canonical_number' => null, 'error' => 'invalid_format'];
        }

        if (! $settings->hasOkohiIntegration()) {
            return ['verified' => false, 'canonical_number' => null, 'error' => 'not_configured'];
        }

        $baseUrl = rtrim((string) config('services.okohi.base_url'), '/');
        if ($baseUrl === '') {
            return ['verified' => false, 'canonical_number' => null, 'error' => 'not_configured'];
        }

        try {
            $timestamp = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $body = '';

            $signature = hash_hmac(
                'sha256',
                $timestamp.'.'.$nonce.'.'.$body,
                (string) $settings->okohi_integration_key,
            );

            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Okohi-Integration-Key' => (string) $settings->okohi_integration_key,
                    'X-Okohi-Timestamp' => $timestamp,
                    'X-Okohi-Nonce' => $nonce,
                    'X-Okohi-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->get($baseUrl.'/api/v1/partner/customers/'.urlencode($customerNumber));

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $canonical = $data['customer']['customer_number'] ?? $data['customer_number'] ?? null;

                // Utilise le numéro canonique retourné par Okohi.
                return [
                    'verified' => $canonical !== null,
                    'canonical_number' => $canonical ? strtoupper((string) $canonical) : null,
                    'error' => $canonical !== null ? null : 'not_found',
                ];
            }

            if ($response->status() === 404) {
                return ['verified' => false, 'canonical_number' => null, 'error' => 'not_found'];
            }

            // Autre erreur (5xx, 4xx inattendu) : panne/incohérence Okohi.
            return ['verified' => false, 'canonical_number' => null, 'error' => 'unreachable'];
        } catch (\Throwable $e) {
            // Panne réseau : la vente n'est pas bloquée, mais pas de rattachement.
            return ['verified' => false, 'canonical_number' => null, 'error' => 'unreachable'];
        }
    }
}
