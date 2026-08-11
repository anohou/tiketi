<?php

namespace App\Services;

use App\Models\OkohiTicketOutbox;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TicketSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publication fiable d'un billet vers le portefeuille Okohi (§7).
 *
 * - Okohi reste un portefeuille client : Tiketi reste la source de vérité et
 *   le générateur du QR. Okohi réaffiche le QR reçu sans le recalculer.
 * - Signature HMAC avec timestamp et nonce (X-Okohi-Timestamp, X-Okohi-Nonce,
 *   X-Okohi-Signature = HMAC-SHA256 de timestamp.nonce.body).
 * - Clé d'idempotence par billet et version : une reprise ne crée jamais de
 *   doublon côté Okohi.
 * - Une panne Okohi ne fait jamais échouer la vente : l'outbox est écrite dans
 *   la transaction de vente, puis le job relance jusqu'à livraison.
 */
final class OkohiTicketPublisher
{
    /**
     * Version du SCHÉMA du contrat (indépendante du cycle de vie).
     * La version du cycle de vie est calculée dynamiquement par nextVersion()
     * et n'est jamais une constante.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Met en file la publication d'un billet (dans la transaction de vente).
     *
     * @return OkohiTicketOutbox|null null si l'intégration Okohi n'est pas configurée
     */
    public function enqueue(Ticket $ticket, string $operation = OkohiTicketOutbox::OPERATION_CREATE): ?OkohiTicketOutbox
    {
        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            $ticket->forceFill(['okohi_delivery_status' => 'not_requested'])->saveQuietly();

            return null;
        }

        // Point 1 : JAMAIS de billet Okohi sans propriétaire vérifié.
        // Un billet n'est publié que s'il est rattaché à un client Okohi
        // vérifié (okohi_customer_number canonique). Sans client vérifié :
        // okohi_delivery_status = not_requested et AUCUNE entrée d'outbox —
        // quelle que soit l'opération (création, affectation, réaffectation,
        // embarquement, expiration, remboursement, changement de préférence).
        // Cette protection est CENTRALE : les services appelants ne peuvent
        // pas l'oublier.
        if (blank($ticket->okohi_customer_number)) {
            $ticket->forceFill(['okohi_delivery_status' => 'not_requested'])->saveQuietly();

            return null;
        }

        $externalTicketId = $ticket->public_token ?: $ticket->id;

        // Point 6 : TOUTE l'opération (verrou + calcul de version + payload +
        // idempotency_key + insertion + statut ticket) est atomique dans une
        // SEULE transaction. Le verrou porte sur la ligne TICKET (qui existe
        // toujours) — le verrouillage fonctionne donc même lorsqu'aucune
        // entrée d'outbox n'existe encore pour ce billet.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($ticket, $externalTicketId, $operation, $settings) {
            $lockedTicket = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $version = $this->nextVersion($lockedTicket, $externalTicketId, $operation);

            $payload = $this->buildPayload($lockedTicket, $settings, $version);
            $idempotencyKey = "{$externalTicketId}:v{$version}";

            $record = OkohiTicketOutbox::create([
                'ticket_id' => $lockedTicket->id,
                'external_ticket_id' => $externalTicketId,
                'status' => OkohiTicketOutbox::STATUS_PENDING,
                'operation' => $operation,
                'version' => $version,
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'attempt_count' => 0,
                'next_attempt_at' => now(),
            ]);

            $lockedTicket->forceFill(['okohi_delivery_status' => 'pending'])->saveQuietly();

            // L'instance passée doit refléter le même état en mémoire (le
            // verrou a chargé une instance distincte).
            if ($lockedTicket->is($ticket)) {
                $ticket->forceFill(['okohi_delivery_status' => 'pending']);
            } else {
                $ticket->forceFill(['okohi_delivery_status' => $lockedTicket->okohi_delivery_status]);
            }

            return $record;
        });
    }

    /**
     * Traite une entrée d'outbox (appelé par le job).
     *
     * @return bool succès de livraison (true si livré ou déjà livré)
     */
    public function deliver(OkohiTicketOutbox $outbox): bool
    {
        if ($outbox->isDelivered()) {
            return true;
        }

        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            $outbox->update(['status' => OkohiTicketOutbox::STATUS_CANCELLED]);

            return true;
        }

        $baseUrl = rtrim((string) config('services.okohi.base_url'), '/');
        if ($baseUrl === '') {
            $this->markFailed($outbox, 'Okohi base URL non configurée.', 'base_url_missing');

            return false;
        }

        try {
            $endpoint = $outbox->operation === OkohiTicketOutbox::OPERATION_CREATE
                ? '/api/v1/partner/tickets'
                : "/api/v1/partner/tickets/{$outbox->external_ticket_id}";

            $timestamp = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $body = json_encode($outbox->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $signature = $this->sign($settings->okohi_integration_key, $timestamp, $nonce, $body);

            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Okohi-Integration-Key' => $settings->okohi_integration_key,
                    'X-Okohi-Timestamp' => $timestamp,
                    'X-Okohi-Nonce' => $nonce,
                    'X-Okohi-Signature' => $signature,
                    'X-Idempotency-Key' => $outbox->idempotency_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->{$outbox->operation === OkohiTicketOutbox::OPERATION_CREATE ? 'post' : 'patch'}($baseUrl.$endpoint, $outbox->payload);

            $responseBody = $response->json() ?? [];

            if (! $response->successful()) {
                // 409 = déjà livré (idempotence) : considéré comme livré.
                if ($response->status() === 409 && $responseBody['code'] === 'idempotency_duplicate') {
                    $this->markDelivered($outbox, $responseBody);

                    return true;
                }

                $this->markFailed(
                    $outbox,
                    $responseBody['message'] ?? 'Okohi API error: '.$response->status(),
                    'http_'.$response->status(),
                    $responseBody,
                );

                return false;
            }

            $this->markDelivered($outbox, $responseBody);

            return true;
        } catch (\Throwable $e) {
            $this->markFailed($outbox, $e->getMessage(), 'network_error');

            return false;
        }
    }

    /**
     * Retries restants avant échec définitif.
     */
    public function attemptsRemaining(OkohiTicketOutbox $outbox): int
    {
        return max(0, $this->maxAttempts() - $outbox->attempt_count);
    }

    public function maxAttempts(): int
    {
        return (int) config('transport.okohi.max_attempts', 8);
    }

    private function sign(string $key, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $key);
    }

    private function markDelivered(OkohiTicketOutbox $outbox, array $responseBody): void
    {
        $outbox->update([
            'status' => OkohiTicketOutbox::STATUS_DELIVERED,
            'last_attempt_at' => now(),
            'delivered_at' => now(),
            'last_response' => $responseBody,
            'last_error' => null,
            'last_error_code' => null,
        ]);

        $outbox->ticket()->update(['okohi_delivery_status' => 'delivered']);
    }

    private function markFailed(OkohiTicketOutbox $outbox, string $error, string $code, array $responseBody = []): void
    {
        $attempts = $outbox->attempt_count + 1;
        $remaining = $this->maxAttempts() - $attempts;

        $update = [
            'attempt_count' => $attempts,
            'last_attempt_at' => now(),
            'last_error' => Str::limit($error, 1000),
            'last_error_code' => $code,
            'last_response' => $responseBody ?: $outbox->last_response,
        ];

        if ($remaining <= 0) {
            $update['status'] = OkohiTicketOutbox::STATUS_FAILED;
        } else {
            $update['status'] = OkohiTicketOutbox::STATUS_PENDING;
            $update['next_attempt_at'] = now()->addSeconds($this->backoffSeconds($attempts));
        }

        $outbox->update($update);
        $outbox->ticket()->update(['okohi_delivery_status' => 'failed']);

        Log::warning('okohi_ticket_delivery_failed', [
            'outbox_id' => $outbox->id,
            'ticket_id' => $outbox->ticket_id,
            'attempt' => $attempts,
            'code' => $code,
            'error' => Str::limit($error, 300),
        ]);
    }

    private function backoffSeconds(int $attempt): int
    {
        // 30 s, 1 min, 2 min, 4 min, 8 min, 16 min, 30 min, 60 min.
        return min(3600, 30 * (2 ** ($attempt - 1)));
    }

    private function nextVersion(Ticket $ticket, string $externalTicketId, string $operation): int
    {
        $max = OkohiTicketOutbox::where('ticket_id', $ticket->id)
            ->where('external_ticket_id', $externalTicketId)
            ->max('version');

        if ($operation === OkohiTicketOutbox::OPERATION_CREATE && $max === null) {
            return 1;
        }

        return (int) $max + 1;
    }

    /**
     * Charge utile minimale (§7.1) : identité du billet, QR exact, itinéraire
     * aller et retour, choix du retour, validité, prix payé, états des droits.
     */
    private function buildPayload(Ticket $ticket, TicketSetting $settings, int $version): array
    {
        $ticket->loadMissing(['fromStation', 'toStation', 'outboundJourney', 'returnJourney']);

        $outbound = $ticket->outboundJourney;
        $return = $ticket->returnJourney;

        $journeyState = function (?TicketJourney $journey): ?array {
            if (! $journey) {
                return null;
            }

            return [
                'direction' => $journey->direction,
                'selection_mode' => $journey->selection_mode,
                'from_station_id' => $journey->from_station_id,
                'to_station_id' => $journey->to_station_id,
                'trip_id' => $journey->trip_id,
                'departure_schedule_id' => $journey->departure_schedule_id,
                'desired_travel_date' => $journey->desired_travel_date?->toDateString(),
                'desired_departure_time' => $journey->desired_departure_time?->format('H:i'),
                'seat_number' => $journey->seat_number,
                'seat_assignment_status' => $journey->seat_assignment_status,
                'status' => $journey->status,
                'boarded_at' => $journey->boarded_at?->toIso8601String(),
            ];
        };

        return [
            'version' => $version,
            'schema_version' => self::SCHEMA_VERSION,
            'external_ticket_id' => $ticket->public_token ?: $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'journey_type' => $ticket->journey_type,
            'qr' => [
                'format' => 'TIKETI2',
                'payload' => $ticket->qrPayloadString(),
            ],
            'company_id' => tenant()?->id,
            'customer' => [
                'name' => $ticket->passenger_name,
                'phone' => $ticket->passenger_phone,
                'okohi_customer_number' => $ticket->okohi_customer_number,
            ],
            'itinerary' => [
                'outbound' => [
                    'from_station_id' => $outbound?->from_station_id ?? $ticket->from_station_id,
                    'to_station_id' => $outbound?->to_station_id ?? $ticket->to_station_id,
                    'journey' => $journeyState($outbound),
                ],
                'return' => $journeyState($return),
            ],
            'pricing' => [
                'price' => (int) $ticket->price,
                'normal_total_amount' => $ticket->normal_total_amount !== null ? (int) $ticket->normal_total_amount : null,
                'round_trip_discount_amount' => (int) ($ticket->round_trip_discount_amount ?? 0),
                'amount_collected' => (int) ($ticket->amount_collected ?? $ticket->price),
                'currency' => 'XOF',
            ],
            'validity' => [
                'issued_at' => $ticket->created_at?->toIso8601String(),
                'return_valid_until' => $ticket->return_valid_until?->toIso8601String(),
            ],
            'status' => [
                'ticket' => $ticket->status,
                'outbound' => $outbound?->status,
                'return' => $return?->status,
            ],
        ];
    }
}
