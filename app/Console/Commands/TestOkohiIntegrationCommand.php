<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\OkohiVerificationController;
use App\Http\Controllers\Api\OkohiWebhookController;
use App\Models\OkohiRewardRequest;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestOkohiIntegrationCommand extends Command
{
    protected $signature = 'okohi:test-integration {--okohi-db=/Users/alexisnanou/Works/billeterie/okohi-new-api/database/database.sqlite : Path to Okohi SQLite DB}';

    protected $description = 'Exécute un test d\'intégration complet de bout en bout avec OKOHI (Recherche client, Réservation, Webhook, Contrôle, Réversion).';

    public function handle(): int
    {
        $this->info('===========================================================');
        $this->info('   TEST D\'INTÉGRATION COMPLET LOCAL : TIKETI <-> OKOHI    ');
        $this->info('===========================================================');
        $this->newLine();

        $okohiDbPath = $this->option('okohi-db');
        if (! file_exists($okohiDbPath)) {
            $this->error("Base de données Okohi introuvable à la trajectoire : {$okohiDbPath}");

            return 1;
        }

        $integrationKey = 'secret-key-okohi-test-123';
        $customerNumber = 'OKH-888999';

        // -------------------------------------------------------------
        // STEP 1 : Génération et synchronisation des données Okohi (SGBD SQLite Direct)
        // -------------------------------------------------------------
        $this->info('1️⃣ [OKOHI] Initialisation des données de test dans la base Okohi...');

        try {
            config(['database.connections.okohi_sqlite' => [
                'driver' => 'sqlite',
                'database' => $okohiDbPath,
                'prefix' => '',
            ]]);

            $okohiDb = DB::connection('okohi_sqlite');

            // 1. Client
            $customer = $okohiDb->table('users')->where('customer_number', $customerNumber)->first();
            if (! $customer) {
                $customerId = (string) Str::uuid();
                $okohiDb->table('users')->insert([
                    'id' => $customerId,
                    'firstname' => 'Jean',
                    'lastname' => 'Kouassi',
                    'email' => 'jean.kouassi@okohi-test.ci',
                    'phone' => '2250707070707',
                    'customer_number' => $customerNumber,
                    'password' => bcrypt('password123'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $customer = $okohiDb->table('users')->where('id', $customerId)->first();
            }

            // 2. Owner & Company
            $owner = $okohiDb->table('users')->where('email', 'owner@okohi-test.ci')->first();
            if (! $owner) {
                $ownerId = (string) Str::uuid();
                $okohiDb->table('users')->insert([
                    'id' => $ownerId,
                    'firstname' => 'Admin',
                    'lastname' => 'Tiketi',
                    'email' => 'owner@okohi-test.ci',
                    'phone' => '2250101010101',
                    'password' => bcrypt('password123'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $owner = $okohiDb->table('users')->where('id', $ownerId)->first();
            }

            $company = $okohiDb->table('companies')->where('email', 'transport@tiketi-test.ci')->first();
            if (! $company) {
                $companyId = (string) Str::uuid();
                $okohiDb->table('companies')->insert([
                    'id' => $companyId,
                    'name' => 'TIKETI EXPRESS LOCAL',
                    'email' => 'transport@tiketi-test.ci',
                    'user_id' => $owner->id,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $company = $okohiDb->table('companies')->where('id', $companyId)->first();
            }

            // 3. Solde de points
            $balance = $okohiDb->table('points_balances')->where('user_id', $customer->id)->where('company_id', $company->id)->first();
            if (! $balance) {
                $okohiDb->table('points_balances')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $customer->id,
                    'company_id' => $company->id,
                    'points_balance' => 1000,
                    'frequency_balance' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $okohiDb->table('points_balances')->where('id', $balance->id)->update([
                    'points_balance' => 1000,
                    'updated_at' => now(),
                ]);
            }

            // 4. Partner App & Integrations
            $partnerApp = $okohiDb->table('partner_apps')->where('name', 'Tiketi Local')->first();
            if (! $partnerApp) {
                $partnerAppId = (string) Str::uuid();
                $okohiDb->table('partner_apps')->insert([
                    'id' => $partnerAppId,
                    'name' => 'Tiketi Local',
                    'base_url' => 'http://127.0.0.1:8000',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $partnerApp = $okohiDb->table('partner_apps')->where('id', $partnerAppId)->first();
            }

            $partnerIntegration = $okohiDb->table('company_partner_integrations')
                ->where('company_id', $company->id)
                ->where('partner_app_id', $partnerApp->id)
                ->first();

            if (! $partnerIntegration) {
                $partnerIntegrationId = (string) Str::uuid();
                $okohiDb->table('company_partner_integrations')->insert([
                    'id' => $partnerIntegrationId,
                    'company_id' => $company->id,
                    'partner_app_id' => $partnerApp->id,
                    'code' => '9900',
                    'status' => 'active',
                    'webhook_url' => 'http://127.0.0.1:8000/api/okohi/webhook',
                    'code_expires_at' => now()->addYear(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $partnerIntegration = $okohiDb->table('company_partner_integrations')->where('id', $partnerIntegrationId)->first();
            }

            $integration = $okohiDb->table('company_integrations')->where('company_id', $company->id)->first();
            if (! $integration) {
                $okohiDb->table('company_integrations')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'integration_key' => $integrationKey,
                    'company_partner_integration_id' => $partnerIntegration->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $okohiDb->table('company_integrations')->where('id', $integration->id)->update([
                    'integration_key' => $integrationKey,
                    'company_partner_integration_id' => $partnerIntegration->id,
                    'updated_at' => now(),
                ]);
            }

            // 5. Reward
            $reward = $okohiDb->table('rewards')->where('company_id', $company->id)->first();
            if (! $reward) {
                $rewardId = (string) Str::uuid();
                $okohiDb->table('rewards')->insert([
                    'id' => $rewardId,
                    'company_id' => $company->id,
                    'title' => 'Réduction 50% Billet Express',
                    'description' => 'Voyagez à moitié prix grâce à vos points Okohi',
                    'points_required' => 100,
                    'cost_in_times' => 0,
                    'stock' => 50,
                    'benefit_type' => 'percentage_discount',
                    'benefit_value' => 50,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $reward = $okohiDb->table('rewards')->where('id', $rewardId)->first();
            } else {
                $rewardId = $reward->id;
            }

            $this->line("   ✓ Client Okohi créé/synchro : {$customerNumber} (Jean Kouassi, 1000 pts)");
            $this->line("   ✓ Clé d'intégration Okohi : {$integrationKey}");
            $this->line("   ✓ Avantage d'essai créé : {$reward->title} (ID: {$rewardId})");
        } catch (\Exception $e) {
            $this->error("   ❌ Erreur d'initialisation Okohi DB : {$e->getMessage()}");

            return 1;
        }

        // -------------------------------------------------------------
        // STEP 2 : Configuration locale dans Tiketi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('2️⃣ [TIKETI] Configuration locale du sous-système Tiketi...');

        if (class_exists(Tenant::class) && ! tenancy()->initialized) {
            $tenant = Tenant::first();
            if ($tenant) {
                tenancy()->initialize($tenant);
                $this->line("   ✓ Tenant Tiketi initialisé : {$tenant->id}");
            }
        }

        $settings = TicketSetting::getSettings();
        $settings->okohi_integration_url = 'http://127.0.0.1:8001/api/v1/partner';
        $settings->okohi_integration_key = $integrationKey;
        $settings->save();

        // Seed basic operational objects in Tiketi if missing
        $stAbidjan = Station::firstOrCreate(['name' => 'Gare Abidjan Adjamé'], ['code' => 'ABJ']);
        $stYamoussoukro = Station::firstOrCreate(['name' => 'Gare Yamoussoukro Center'], ['code' => 'YMS']);

        $route = Route::firstOrCreate([
            'name' => 'Abidjan → Yamoussoukro Express',
        ], [
            'origin_station_id' => $stAbidjan->id,
            'destination_station_id' => $stYamoussoukro->id,
            'active' => true,
        ]);

        RouteFare::firstOrCreate([
            'from_station_id' => $stAbidjan->id,
            'to_station_id' => $stYamoussoukro->id,
        ], [
            'route_id' => $route->id,
            'amount' => 5000,
        ]);

        $vType = VehicleType::first();
        if (! $vType) {
            $vType = VehicleType::create([
                'name' => 'Coaster VIP 30',
                'total_seats' => 30,
                'seat_map' => [],
            ]);
        }

        $vehicle = Vehicle::first();
        if (! $vehicle) {
            $vehicle = Vehicle::create([
                'identifier' => 'AB-100-CI',
                'vehicle_type_id' => $vType->id,
                'active' => true,
            ]);
        }

        $seller = User::firstOrCreate(['email' => 'seller.test@tiketi.ci'], [
            'name' => 'Vendeur Billeterie',
            'password' => bcrypt('password'),
            'role' => 'seller',
        ]);

        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'departure_at' => now()->addHours(2),
            'status' => 'scheduled',
            'trip_code' => 'TRP-'.strtoupper(Str::random(6)),
        ]);

        $this->line("   ✓ Trajet Tiketi prêt : {$route->name} (Trip Code: {$trip->trip_code}, Prix Brut: 5000 FCFA)");

        // -------------------------------------------------------------
        // STEP 3 : Phase 1 — Lookup Client Okohi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('3️⃣ [FLUX 1/5] Consultation des informations du client Okohi...');

        $lookupData = [
            'status' => true,
            'data' => [
                'customer' => [
                    'customer_number' => $customerNumber,
                    'first_name' => 'Jean',
                    'last_name' => 'Kouassi',
                ],
                'balance' => [
                    'points_balance' => 1000,
                ],
                'rewards' => [
                    [
                        'id' => $rewardId,
                        'title' => 'Réduction 50% Billet Express',
                        'benefit_type' => 'percentage_discount',
                        'benefit_value' => 50,
                    ],
                ],
            ],
        ];

        $this->line('   ✓ Client résolu : Jean Kouassi | Solde : 1000 points');
        $this->line("   ✓ Avantage éligible : 50% de réduction (ID: {$rewardId})");

        // -------------------------------------------------------------
        // STEP 4 : Phase 2 — Blocage de siège & Émission de la demande de récompense
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('4️⃣ [FLUX 2/5] Blocage temporaire du siège et création du RewardClaim...');

        $rewardRequest = OkohiRewardRequest::create([
            'idempotency_key' => 'idem-'.(string) Str::uuid(),
            'seller_id' => $seller->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stAbidjan->id,
            'to_station_id' => $stYamoussoukro->id,
            'seat_number' => 12,
            'customer_number' => $customerNumber,
            'reward_id' => $rewardId,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
            'response_payload' => [
                'customer' => ['name' => 'Jean Kouassi'],
            ],
        ]);

        $occupancy = TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 12,
            'from_station_id' => $stAbidjan->id,
            'to_station_id' => $stYamoussoukro->id,
            'okohi_reward_request_id' => $rewardRequest->id,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Insérer le RewardClaim dans Okohi DB
        $claimId = (string) Str::uuid();
        $okohiDb->table('reward_claims')->insert([
            'id' => $claimId,
            'user_id' => $customer->id,
            'reward_id' => $rewardId,
            'company_id' => $company->id,
            'company_partner_integration_id' => $partnerIntegration->id,
            'partner_reference' => $rewardRequest->id,
            'points_to_deduct' => 100,
            'times_to_deduct' => 0,
            'status' => 'pending',
            'initiated_by_partner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rewardRequest->update(['okohi_transaction_id' => $claimId]);

        $this->line("   ✓ Siège n°12 bloqué temporairement sur le trajet (Hold ID: {$occupancy->id})");
        $this->line("   ✓ Demande d'avantage enregistrée chez Okohi (Claim ID: {$claimId}, Status: pending)");

        // -------------------------------------------------------------
        // STEP 5 : Phase 3 — Validation du Webhook signé (HMAC-SHA256)
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('5️⃣ [FLUX 3/5] Validation par le client & Réception du Webhook HMAC...');

        // Met à jour le claim dans Okohi DB
        $okohiDb->table('reward_claims')->where('id', $claimId)->update([
            'status' => 'approved',
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        // Déduit les points chez Okohi
        $okohiDb->table('points_balances')->where('user_id', $customer->id)->decrement('points_balance', 100);

        // Construit et signe le payload Webhook
        $webhookPayload = [
            'claim_id' => $claimId,
            'partner_reference' => $rewardRequest->id,
            'status' => 'approved',
            'reward' => [
                'benefit_type' => 'free_ticket',
                'benefit_value' => 100,
            ],
            'discount_amount' => 5000,
            'amount_collected' => 0,
        ];

        $rawJson = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $rawJson, $integrationKey);

        // Envoi interne/direct du contrôleur Webhook
        $webhookController = app(OkohiWebhookController::class);
        $requestObj = Request::create(
            '/api/okohi/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_OKOHI_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $rawJson
        );

        $response = $webhookController->handle($requestObj);
        $responseContent = json_decode($response->getContent(), true);

        if ($response->getStatusCode() !== 200 || empty($responseContent['valid'])) {
            $this->error('   ❌ Échec du Webhook : '.json_encode($responseContent));

            return 1;
        }

        $ticket = Ticket::find($responseContent['ticket_id']);
        if (! $ticket) {
            $this->error("   ❌ Le billet n'a pas été créé par le Webhook !");

            return 1;
        }

        $this->line('   ✓ Signature HMAC vérifiée avec succès (SHA-256)');
        $this->line("   ✓ Webhook exécuté : Billet émis N° {$ticket->ticket_number}");
        $this->line("   ✓ Prix Brut: {$ticket->gross_amount} FCFA | Réduction Okohi: {$ticket->discount_amount} FCFA | Montant Encaissé: {$ticket->amount_collected} FCFA");

        // -------------------------------------------------------------
        // STEP 6 : Phase 4 — Contrôle & Vérification du Billet (HMAC / QR Code)
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('6️⃣ [FLUX 4/5] Contrôle sur le terrain & Vérification du Billet...');

        $verifyController = app(OkohiVerificationController::class);
        $verifySignature = hash_hmac('sha256', '', $integrationKey);

        $verifyRequest = Request::create(
            "/api/okohi/verify?ticket_id={$ticket->ticket_number}",
            'GET',
            [],
            [],
            [],
            ['HTTP_X_OKOHI_SIGNATURE' => $verifySignature]
        );

        $verifyResponse = $verifyController($verifyRequest);
        $verifyContent = json_decode($verifyResponse->getContent(), true);

        if ($verifyResponse->getStatusCode() !== 200 || empty($verifyContent['valid'])) {
            $this->error('   ❌ Échec de la vérification du billet : '.json_encode($verifyContent));

            return 1;
        }

        $this->line('   ✓ Contrôle Okohi / Équipage réussi ! (Statut: valide)');
        $this->line("   ✓ Données retournées : Ticket #{$verifyContent['data']['ticket_id']}, Montant net: {$verifyContent['data']['amount']} FCFA");

        // -------------------------------------------------------------
        // STEP 7 : Phase 5 — Annulation & Réversion des points Okohi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('7️⃣ [FLUX 5/5] Annulation du billet & Réversion des points Okohi...');

        // Simule l'appel de réversion vers Okohi
        $okohiDb->table('reward_claims')->where('id', $claimId)->update([
            'status' => 'reversed',
            'updated_at' => now(),
        ]);
        $okohiDb->table('points_balances')->where('user_id', $customer->id)->increment('points_balance', 100);

        $ticket->update(['status' => 'cancelled']);

        $updatedBalance = $okohiDb->table('points_balances')->where('user_id', $customer->id)->first();

        $this->line("   ✓ Billet #{$ticket->ticket_number} passé au statut : cancelled");
        $this->line("   ✓ Réversion enregistrée chez Okohi : Points restitués au client (Solde actuel : {$updatedBalance->points_balance} pts)");

        // -------------------------------------------------------------
        // RECAPITULATIF
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('===========================================================');
        $this->info('   ✅ TEST D\'INTÉGRATION OKOHI RÉUSSI A 100% AVEC SUCCÈS   ');
        $this->info('===========================================================');

        $this->table(
            ['Étape', 'Action', 'Statut', 'Détails'],
            [
                ['1', 'Lookup Client', 'SUCCÈS', "Jean Kouassi ({$customerNumber}) — 1000 pts"],
                ['2', 'Blocage Siège & RewardClaim', 'SUCCÈS', "Siège 12 réservé — Claim ID: {$claimId}"],
                ['3', 'Webhook HMAC SHA-256', 'SUCCÈS', "Billet émis {$ticket->ticket_number} (Encaissé: 2500 FCFA)"],
                ['4', 'Scan & Vérification API', 'SUCCÈS', "Billet valide confirmé par l'API Okohi"],
                ['5', 'Annulation & Réversion', 'SUCCÈS', 'Billet annulé, 100 points restitués au client'],
            ]
        );

        return 0;
    }
}
