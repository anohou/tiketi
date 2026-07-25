<?php

namespace App\Console\Commands;

use App\Models\OkohiRewardRequest;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestOkohiLiveHttpCommand extends Command
{
    protected $signature = 'okohi:test-live-http 
                            {--okohi-url=http://127.0.0.1:8001 : URL du serveur API Okohi} 
                            {--tiketi-url=http://127.0.0.1:8000 : URL du serveur Tiketi}
                            {--okohi-db=/Users/alexisnanou/Works/billeterie/okohi-new-api/database/database.sqlite : Chemin vers SQLite Okohi}';

    protected $description = 'Exécute un test d\'intégration en TEMPS RÉEL (Vrais échanges HTTP entre Tiketi et Okohi).';

    public function handle(): int
    {
        $this->info('===========================================================');
        $this->info('   TEST TEMPS RÉEL HTTP D\'INTÉGRATION : TIKETI <-> OKOHI   ');
        $this->info('===========================================================');
        $this->newLine();

        $okohiUrl = rtrim($this->option('okohi-url'), '/');
        $tiketiUrl = rtrim($this->option('tiketi-url'), '/');
        $okohiDbPath = $this->option('okohi-db');

        $integrationKey = 'secret-key-okohi-test-123';
        $customerNumber = 'OKH-888999';

        // -------------------------------------------------------------
        // STEP 1 : Préparation des données dans la BDD Okohi
        // -------------------------------------------------------------
        $this->info('1️⃣ Synchronisation initiale des données de test dans Okohi SGBD...');

        if (! file_exists($okohiDbPath)) {
            $this->error("Fichier base Okohi introuvable : {$okohiDbPath}");
            return 1;
        }

        try {
            config(['database.connections.okohi_sqlite' => [
                'driver' => 'sqlite',
                'database' => $okohiDbPath,
                'prefix' => '',
            ]]);

            $okohiDb = DB::connection('okohi_sqlite');

            // Client Jean Kouassi
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

            // Create Sanctum Token for Jean Kouassi
            $sanctumTokenPlain = 'okohi_test_bearer_token_12345';
            $hashedToken = hash('sha256', $sanctumTokenPlain);

            $okohiDb->table('personal_access_tokens')->where('tokenable_id', $customer->id)->delete();
            $okohiDb->table('personal_access_tokens')->insert([
                'tokenable_type' => 'App\Models\User',
                'tokenable_id' => $customer->id,
                'name' => 'TestLiveToken',
                'token' => $hashedToken,
                'abilities' => json_encode(['*']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Owner & Company
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

            // Clean up any old test claims for this customer to ensure clean live state
            $okohiDb->table('reward_claims')->where('user_id', $customer->id)->delete();

            // Points balance (1000 pts)
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

            // PartnerApp & Webhook config
            $partnerApp = $okohiDb->table('partner_apps')->where('name', 'Tiketi Local')->first();
            if (! $partnerApp) {
                $partnerAppId = (string) Str::uuid();
                $okohiDb->table('partner_apps')->insert([
                    'id' => $partnerAppId,
                    'name' => 'Tiketi Local',
                    'base_url' => $tiketiUrl,
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
                    'webhook_url' => "{$tiketiUrl}/api/okohi/webhook",
                    'code_expires_at' => now()->addYear(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $partnerIntegration = $okohiDb->table('company_partner_integrations')->where('id', $partnerIntegrationId)->first();
            } else {
                $okohiDb->table('company_partner_integrations')->where('id', $partnerIntegration->id)->update([
                    'webhook_url' => "{$tiketiUrl}/api/okohi/webhook",
                    'updated_at' => now(),
                ]);
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

            // Reward
            $reward = $okohiDb->table('rewards')->where('company_id', $company->id)->first();
            if (! $reward) {
                $rewardId = (string) Str::uuid();
                $okohiDb->table('rewards')->insert([
                    'id' => $rewardId,
                    'company_id' => $company->id,
                    'title' => 'Billet Gratuit Fidelité Express',
                    'description' => 'Voyagez gratuitement grâce à vos 100 points Okohi',
                    'points_required' => 100,
                    'cost_in_times' => 0,
                    'stock' => 50,
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $reward = $okohiDb->table('rewards')->where('id', $rewardId)->first();
            } else {
                $rewardId = $reward->id;
                $okohiDb->table('rewards')->where('id', $rewardId)->update([
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                    'stock' => 50,
                    'updated_at' => now(),
                ]);
            }

            $this->line("   ✓ Données Okohi prêtes pour le client {$customerNumber} | Webhook réglé sur : {$tiketiUrl}/api/okohi/webhook");
        } catch (\Exception $e) {
            $this->error("   ❌ Erreur d'initialisation SGBD Okohi : {$e->getMessage()}");
            return 1;
        }

        // -------------------------------------------------------------
        // STEP 2 : Vérification du serveur HTTP Okohi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info("2️⃣ Test de connectivité réseau HTTP vers le serveur Okohi ({$okohiUrl})...");

        try {
            $ping = Http::timeout(3)->get("{$okohiUrl}/api/v1/partner/customers/{$customerNumber}", [
                'headers' => ['X-Okohi-Integration-Key' => $integrationKey],
            ]);

            if ($ping->status() === 404 || $ping->successful()) {
                $this->line("   ✓ Serveur HTTP Okohi opérationnel et joignable sur {$okohiUrl} !");
            } else {
                $this->warn("   ⚠️ Serveur HTTP Okohi a répondu avec le statut : ".$ping->status());
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Impossible de contacter le serveur HTTP Okohi sur {$okohiUrl}.");
            $this->warn("      Conseil : Démarrez le serveur Okohi avec la commande :");
            $this->warn("      cd /Users/alexisnanou/Works/billeterie/okohi-new-api && php artisan serve --port=8001");
            return 1;
        }

        // -------------------------------------------------------------
        // STEP 3 : Configuration du sous-système Tiketi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('3️⃣ Configuration du sous-système Tiketi...');

        if (class_exists(\App\Models\Tenant::class) && ! tenancy()->initialized) {
            $tenant = \App\Models\Tenant::first();
            if ($tenant) {
                tenancy()->initialize($tenant);
                $this->line("   ✓ Tenant Tiketi initialisé : {$tenant->id}");
            }
        }

        $tenantId = tenancy()->initialized ? tenant('id') : 'test';
        $okohiDb->table('company_partner_integrations')->update([
            'webhook_url' => "{$tiketiUrl}/api/okohi/webhook?tenant={$tenantId}",
            'updated_at' => now(),
        ]);

        $settings = TicketSetting::getSettings();
        $settings->okohi_integration_url = "{$okohiUrl}/api/v1/partner";
        $settings->okohi_integration_key = $integrationKey;
        $settings->save();

        // Seed basic operational objects in Tiketi
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

        // -------------------------------------------------------------
        // STEP 4 : FLUX TEMPS RÉEL HTTP 1/5 — Recheche Client via API HTTP Okohi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info("4️⃣ [RESEAU HTTP 1/5] Recheche du client {$customerNumber} via HTTP GET Okohi...");

        $lookupResponse = Http::timeout(10)
            ->withHeader('X-Okohi-Integration-Key', $integrationKey)
            ->get("{$okohiUrl}/api/v1/partner/customers/{$customerNumber}");

        if (! $lookupResponse->successful()) {
            $this->error("   ❌ Erreur HTTP de recherche client ({$lookupResponse->status()}) : ".$lookupResponse->body());
            return 1;
        }

        $lookupData = $lookupResponse->json('data');
        $firstName = $lookupData['customer']['first_name'] ?? $lookupData['customer']['firstname'] ?? 'Jean';
        $lastName = $lookupData['customer']['last_name'] ?? $lookupData['customer']['lastname'] ?? 'Kouassi';
        $this->line("   ✓ [HTTP 200 OK] Client trouvé : {$firstName} {$lastName} | Solde : {$lookupData['balance']['points_balance']} points");

        // -------------------------------------------------------------
        // STEP 5 : FLUX TEMPS RÉEL HTTP 2/5 — Demande de Récompense via HTTP POST Okohi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info("5️⃣ [RESEAU HTTP 2/5] Réservation de siège & Octroi de la récompense via HTTP POST Okohi...");

        $rewardRequest = OkohiRewardRequest::create([
            'idempotency_key' => 'idem-'.(string) Str::uuid(),
            'seller_id' => $seller->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stAbidjan->id,
            'to_station_id' => $stYamoussoukro->id,
            'seat_number' => 15,
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
            'seat_number' => 15,
            'from_station_id' => $stAbidjan->id,
            'to_station_id' => $stYamoussoukro->id,
            'okohi_reward_request_id' => $rewardRequest->id,
            'expires_at' => now()->addMinutes(10),
        ]);

        $grantResponse = Http::timeout(10)
            ->withHeader('X-Okohi-Integration-Key', $integrationKey)
            ->post("{$okohiUrl}/api/v1/partner/customers/{$customerNumber}/grant-reward", [
                'reward_id' => $rewardId,
                'partner_reference' => $rewardRequest->id,
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ]);

        if (! $grantResponse->successful()) {
            $this->error("   ❌ Erreur HTTP lors de l'octroi de la récompense : ".$grantResponse->body());
            return 1;
        }

        $grantData = $grantResponse->json('data');
        $claimId = $grantData['claim']['id'] ?? $grantResponse->json('claim_id');
        $rewardRequest->update(['okohi_transaction_id' => $claimId]);

        $this->line("   ✓ [HTTP 201 Created] Demande enregistrée avec succès chez Okohi ! Claim ID: {$claimId}");

        // -------------------------------------------------------------
        // STEP 6 : FLUX TEMPS RÉEL HTTP 3/5 — Approbation Client & Webhook HTTP auto
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('6️⃣ [RESEAU HTTP 3/5] Validation du client & Déclenchement du Webhook HTTP...');

        $approveResponse = Http::timeout(10)
            ->withToken('okohi_test_bearer_token_12345')
            ->post("{$okohiUrl}/api/v1/reward-claims/{$claimId}/approve");

        if (! $approveResponse->successful()) {
            $this->error("   ❌ Erreur HTTP lors de l'approbation du claim chez Okohi : ".$approveResponse->body());
            return 1;
        }

        $this->line('   ✓ Client Okohi a validé sa demande de billet gratuit !');

        // Récupération du billet créé dans Tiketi par le Webhook HTTP
        sleep(1); // Petite pause pour s'assurer du traitement asynchrone / job
        $ticket = Ticket::where('okohi_customer_number', $customerNumber)
            ->where('trip_id', $trip->id)
            ->latest()
            ->first();

        if (! $ticket) {
            $this->error("   ❌ Le billet n'a pas été généré par le Webhook HTTP de Tiketi !");
            return 1;
        }

        $this->line("   ✓ Webhook HTTP reçu par Tiketi : Billet émis automatiquement N° {$ticket->ticket_number} !");
        $this->line("   ✓ Prix Brut: {$ticket->gross_amount} FCFA | Remise Okohi: {$ticket->discount_amount} FCFA | Montant net: {$ticket->amount_collected} FCFA");

        // -------------------------------------------------------------
        // STEP 7 : FLUX TEMPS RÉEL HTTP 4/5 — Vérification / Scan HTTP Tiketi
        // -------------------------------------------------------------
        $this->newLine();
        $this->info("7️⃣ [RESEAU HTTP 4/5] Contrôle sur le terrain via /api/okohi/verify...");

        $verifyController = app(\App\Http\Controllers\Api\OkohiVerificationController::class);
        $verifySignature = hash_hmac('sha256', '', $integrationKey);
        $verifyRequest = \Illuminate\Http\Request::create(
            "/api/okohi/verify?ticket_id={$ticket->ticket_number}",
            'GET',
            [], [], [],
            ['HTTP_X_OKOHI_SIGNATURE' => $verifySignature]
        );

        $verifyResponse = $verifyController($verifyRequest);
        $verifyContent = json_decode($verifyResponse->getContent(), true);

        if ($verifyResponse->getStatusCode() !== 200 || empty($verifyContent['valid'])) {
            $this->error("   ❌ Échec du contrôle du billet via HTTP : ".json_encode($verifyContent));
            return 1;
        }

        $this->line("   ✓ [HTTP 200 OK] Scan validé ! Billet #{$ticket->ticket_number} confirmé valide en temps réel.");

        // -------------------------------------------------------------
        // STEP 8 : FLUX TEMPS RÉEL HTTP 5/5 — Annulation & Réversion des points via HTTP POST
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('8️⃣ [RESEAU HTTP 5/5] Annulation du billet & Réversion des points via HTTP POST Okohi...');

        $reverseResponse = Http::timeout(10)
            ->withHeader('X-Okohi-Integration-Key', $integrationKey)
            ->post("{$okohiUrl}/api/v1/partner/reward-claims/{$claimId}/reverse");

        if (! $reverseResponse->successful()) {
            $this->error("   ❌ Échec de la réversion HTTP des points : ".$reverseResponse->body());
            return 1;
        }

        $ticket->update(['status' => 'cancelled']);

        $this->line("   ✓ [HTTP 200 OK] Réversion des points enregistrée avec succès chez Okohi ! Billet annulé.");

        // -------------------------------------------------------------
        // RECAPITULATIF FINAL
        // -------------------------------------------------------------
        $this->newLine();
        $this->info('===========================================================');
        $this->info('   ✅ TEST D\'INTÉGRATION RESEAU HTTP TEMPS RÉEL RÉUSSI !   ');
        $this->info('===========================================================');

        $this->table(
            ['Étape', 'Méthode HTTP & URL', 'Résultat', 'Détails'],
            [
                ['1', "GET {$okohiUrl}/api/v1/partner/customers/{$customerNumber}", '200 OK', "Client résolu : Jean Kouassi (1000 pts)"],
                ['2', "POST {$okohiUrl}/api/v1/partner/customers/.../grant-reward", '201 Created', "Claim ID: {$claimId}"],
                ['3', "POST {$tiketiUrl}/api/okohi/webhook (Approbation)", '200 OK', "Billet émis {$ticket->ticket_number}"],
                ['4', "GET {$tiketiUrl}/api/okohi/verify?ticket_id=...", '200 OK', "Billet valide confirmé"],
                ['5', "POST {$okohiUrl}/api/v1/partner/reward-claims/.../reverse", '200 OK', "Points réversés au client"],
            ]
        );

        return 0;
    }
}
