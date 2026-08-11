<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrewMember;
use App\Models\DepartureSchedule;
use App\Models\Destination;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class SettingsController extends Controller
{
    private const ROLE_LABELS = [
        'admin' => 'Administrateur',
        'supervisor' => 'Superviseur',
        'seller' => 'Vendeuse / Vendeur',
        'accountant' => 'Comptable',
        'executive' => 'Direction',
        'fleet_manager' => 'Gestionnaire de flotte',
    ];

    public function index()
    {
        $user = auth()->user();

        if ($user->role === 'seller') {
            return redirect()->route('seller.settings.index');
        }

        if (in_array($user->role, ['admin', 'supervisor'], true)) {
            return Inertia::render('Admin/Settings/Index', [
                'role' => $user->role,
                'stats' => $this->settingsStats(),
                'operationalSettings' => OperationalSetting::current(),
            ]);
        }

        return Inertia::render('Admin/Settings/Index', [
            'role' => $user->role,
            'profile' => $this->profileData($user),
            'company' => $this->companyData(),
            'loyalty' => $this->loyaltyData(),
            'scope' => $this->scopeData($user),
            'directives' => $this->directivesData($user),
        ]);
    }

    public function enterprise()
    {
        $tenant = tenant();

        return Inertia::render('Admin/Settings/Enterprise', [
            'tenant' => $tenant,
            'featureFlags' => [
                'departure_programs' => $tenant->departureProgramsEnabled(),
                'round_trip_sales' => $tenant->roundTripSalesEnabled(),
            ],
            'stats' => $this->settingsStats(),
            'operationalSettings' => OperationalSetting::current(),
        ]);
    }

    public function updateEnterprise(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|file|mimetypes:image/png,image/jpeg,image/webp,image/svg+xml|max:5120',
            'automatic_connection_allocation' => 'required|boolean',
            'connection_transfer_buffer_minutes' => 'required|integer|min:0|max:240',
            'operational_day_start_hour' => 'required|integer|min:0|max:23',
            'scheduled_trip_lookahead_hours' => 'required|integer|min:1|max:168',
            'seller_compensation_enabled' => 'required|boolean',
            'seller_compensation_max_amount' => 'required|integer|min:0',
            'default_vehicle_assignment_policy' => 'nullable|in:require_real_vehicle,allow_planned_capacity',
            'departure_programs' => 'required|boolean',
            'round_trip_sales' => 'required|boolean',
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $directory = public_path('logos');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Delete old logo if exists
            if ($tenant->logo_url) {
                $oldPath = public_path(ltrim(str_replace('/logos/', 'logos/', $tenant->logo_url), '/'));
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($directory, $filename);
            $tenant->logo_url = '/logos/'.$filename;
        }

        $tenant->name = $request->name;
        $tenant->email = $request->email;
        $tenant->phone = $request->phone;
        $tenant->mergeFeatureFlags([
            'departure_programs' => $request->boolean('departure_programs'),
            'round_trip_sales' => $request->boolean('round_trip_sales'),
        ]);

        $tenant->save();

        $operationalSettings = OperationalSetting::current();
        $operationalSettings->update([
            'automatic_connection_allocation' => $request->boolean('automatic_connection_allocation'),
            'connection_transfer_buffer_minutes' => (int) $request->input('connection_transfer_buffer_minutes', 15),
            'settings' => array_merge($operationalSettings->settings ?? [], [
                'seller_compensation_enabled' => $request->boolean('seller_compensation_enabled'),
                'seller_compensation_max_amount' => (int) $request->input('seller_compensation_max_amount', 0),
                'operational_day_start_hour' => (int) $request->input('operational_day_start_hour'),
                'scheduled_trip_lookahead_hours' => (int) $request->input('scheduled_trip_lookahead_hours'),
                'default_vehicle_assignment_policy' => $request->input('default_vehicle_assignment_policy', 'require_real_vehicle'),
            ]),
        ]);

        return redirect()->back()->with('success', 'Paramètres de l\'entreprise mis à jour.');
    }

    /**
     * Profil professionnel de l'utilisateur connecté (lecture seule).
     */
    private function profileData(User $user): array
    {
        $stations = UserStationAssignment::with('station')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get()
            ->map(fn ($assignment) => [
                'id' => $assignment->station->id,
                'name' => $assignment->station->name,
                'code' => $assignment->station->code,
                'city' => $assignment->station->city,
            ])
            ->values();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'role' => $user->role,
            'roleLabel' => self::ROLE_LABELS[$user->role] ?? ucfirst($user->role),
            'active' => (bool) $user->active,
            'statusLabel' => $user->active ? 'Actif' : 'Inactif',
            'stations' => $stations,
            'supervisors' => $this->directSupervisors($user),
            'referent' => $this->referentAdmin($user),
        ];
    }

    /**
     * Superviseurs partageant au moins une gare avec le vendeur.
     */
    private function directSupervisors(User $user): array
    {
        if ($user->role !== 'seller') {
            return [];
        }

        $stationIds = $user->getActiveStationIds();
        $supervisors = collect();

        if (! empty($stationIds)) {
            $supervisors = User::where('role', 'supervisor')
                ->whereHas('stationAssignments', fn ($query) => $query
                    ->whereIn('station_id', $stationIds)
                    ->where('active', true))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'telephone']);
        }

        if ($supervisors->isEmpty()) {
            $creatorId = data_get($user->settings, 'creator_id');
            if ($creatorId) {
                $creator = User::whereKey($creatorId)
                    ->whereIn('role', ['supervisor', 'admin'])
                    ->first(['id', 'name', 'email', 'telephone']);

                if ($creator) {
                    $supervisors = collect([$creator]);
                }
            }
        }

        return $supervisors->map(fn ($supervisor) => [
            'id' => $supervisor->id,
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'telephone' => $supervisor->telephone,
        ])->values()->all();
    }

    /**
     * Administrateur référent (créateur si administrateur, sinon premier admin).
     */
    private function referentAdmin(User $user): ?array
    {
        $creatorId = data_get($user->settings, 'creator_id');
        if ($creatorId) {
            $creator = User::whereKey($creatorId)
                ->where('role', 'admin')
                ->first(['id', 'name', 'email', 'telephone']);

            if ($creator) {
                return $this->personArray($creator);
            }
        }

        $admin = User::where('role', 'admin')->orderBy('name')->first(['id', 'name', 'email', 'telephone']);

        return $admin ? $this->personArray($admin) : null;
    }

    private function personArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'telephone' => $user->telephone,
        ];
    }

    /**
     * Informations générales de la compagnie (sans données sensibles).
     */
    private function companyData(): array
    {
        $isTenant = function_exists('tenancy') && tenancy()->initialized;
        $ticketSettings = TicketSetting::getSettings();

        $name = $isTenant ? tenant('name') : null;
        $email = $isTenant ? tenant('email') : null;
        $phone = $isTenant ? tenant('phone') : null;
        $logo = $isTenant ? tenant('logo_url') : null;

        return [
            'name' => $name ?? $ticketSettings->company_name,
            'email' => $email,
            'phone' => $phone,
            'logo_url' => $logo,
            'currency' => 'F CFA',
            'timezone' => config('app.timezone', 'UTC'),
            'support' => [
                'email' => $email,
                'phone_numbers' => collect($ticketSettings->phone_numbers ?? [])
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'policies' => [
                'sale' => ($ticketSettings->footer_messages ?? [])[0] ?? 'Valable pour ce voyage',
                'cancellation' => ($ticketSettings->footer_messages ?? [])[1] ?? 'Non remboursable',
                'baggage' => $ticketSettings->baggage_policy_message,
            ],
        ];
    }

    /**
     * Programme Okohi : statut, règle de gain et catalogue des récompenses (lecture seule).
     * Aucune clé d'intégration n'est transmise.
     */
    private function loyaltyData(): array
    {
        $settings = TicketSetting::getSettings();
        $connected = $settings->hasOkohiIntegration();

        $data = [
            'connected' => $connected,
            'parameters' => null,
            'rewards' => [],
            'error' => null,
        ];

        if (! $connected) {
            return $data;
        }

        $base = $this->partnerBaseUrl();

        try {
            $parameters = Http::timeout(8)
                ->withHeaders($this->partnerHeaders())
                ->get($base.'/parameters');

            if ($parameters->successful()) {
                $data['parameters'] = $parameters->json('data') ?? $parameters->json();
            } elseif ($parameters->status() === 404) {
                $data['error'] = 'Aucun mode de fidélité actif n\'est défini dans Okohi.';
            }
        } catch (\Throwable $e) {
            $data['error'] = 'Impossible de contacter le service de fidélité Okohi.';
        }

        try {
            $rewards = Http::timeout(8)
                ->withHeaders($this->partnerHeaders())
                ->get($base.'/rewards');

            if ($rewards->successful()) {
                $payload = $rewards->json('data') ?? $rewards->json();
                $data['rewards'] = $payload['rewards'] ?? $payload['data'] ?? (is_array($payload) ? $payload : []);
            }
        } catch (\Throwable $e) {
            // Le catalogue reste vide si le service est indisponible.
        }

        return $data;
    }

    private function partnerBaseUrl(): string
    {
        $base = rtrim(config('services.okohi.base_url', 'http://127.0.0.1:8001'), '/');

        if (! str_contains($base, '/api/v1/partner')) {
            $base .= '/api/v1/partner';
        }

        return $base;
    }

    private function partnerHeaders(): array
    {
        return [
            'X-Okohi-Integration-Key' => TicketSetting::getSettings()->okohi_integration_key,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Périmètre opérationnel de l'utilisateur selon son rôle.
     */
    private function scopeData(User $user): array
    {
        return match ($user->role) {
            'seller' => $this->sellerScope($user),
            'supervisor' => $this->supervisorScope($user),
            'fleet_manager' => $this->fleetScope(),
            'accountant' => $this->accountantScope(),
            'executive' => $this->executiveScope(),
            default => ['type' => $user->role],
        };
    }

    private function sellerScope(User $user): array
    {
        $stationIds = $user->getActiveStationIds();

        $stations = Station::whereIn('id', $stationIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'address', 'phone', 'settings'])
            ->map(fn ($station) => [
                'id' => $station->id,
                'name' => $station->name,
                'code' => $station->code,
                'city' => $station->city,
                'address' => $station->address,
                'phone' => $station->phone,
                'can_sell_tickets' => $station->can_sell_tickets,
            ])
            ->values();

        $routes = $user->accessibleRoutesQuery()
            ->with(['originStation:id,name', 'destinationStation:id,name'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($route) => [
                'id' => $route->id,
                'name' => $route->name,
                'origin' => $route->originStation?->name,
                'destination' => $route->destinationStation?->name,
            ])
            ->values();

        $operationalSettings = OperationalSetting::current()->settings ?? [];

        return [
            'type' => 'seller',
            'stations' => $stations,
            'routes' => $routes,
            'paymentMethods' => $this->paymentMethods(),
            'compensation' => [
                'enabled' => (bool) data_get($operationalSettings, 'seller_compensation_enabled', false),
                'maxAmount' => (int) data_get($operationalSettings, 'seller_compensation_max_amount', 0),
            ],
            'deviceRestrictions' => data_get($operationalSettings, 'device_restrictions', ['web' => false, 'control' => false]),
        ];
    }

    private function supervisorScope(User $user): array
    {
        $stationIds = $user->getActiveStationIds();

        $stations = Station::whereIn('id', $stationIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'address', 'phone', 'settings'])
            ->map(fn ($station) => [
                'id' => $station->id,
                'name' => $station->name,
                'code' => $station->code,
                'city' => $station->city,
                'address' => $station->address,
                'phone' => $station->phone,
                'can_sell_tickets' => $station->can_sell_tickets,
            ])
            ->values();

        $sellers = User::where('role', 'seller')
            ->where(function ($query) use ($stationIds, $user) {
                $query->whereHas('stationAssignments', fn ($sub) => $sub
                    ->whereIn('station_id', $stationIds)
                    ->where('active', true))
                    ->orWhere(fn ($sub) => $sub
                        ->whereDoesntHave('stationAssignments')
                        ->where('settings->creator_id', $user->id));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'telephone'])
            ->map(function ($seller) {
                return [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'email' => $seller->email,
                    'telephone' => $seller->telephone,
                    'stations' => $seller->stationAssignments()
                        ->where('active', true)
                        ->with('station:id,name')
                        ->get()
                        ->map(fn ($assignment) => $assignment->station?->name)
                        ->filter()
                        ->values(),
                ];
            })
            ->values();

        $routes = Route::with(['originStation:id,name', 'destinationStation:id,name'])
            ->where('active', true)
            ->where(function ($query) use ($stationIds) {
                $query->whereIn('origin_station_id', $stationIds)
                    ->orWhereIn('destination_station_id', $stationIds)
                    ->orWhereHas('routeStopOrders', fn ($sub) => $sub->whereIn('station_id', $stationIds));
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($route) => [
                'id' => $route->id,
                'name' => $route->name,
                'origin' => $route->originStation?->name,
                'destination' => $route->destinationStation?->name,
            ])
            ->values();

        return [
            'type' => 'supervisor',
            'stations' => $stations,
            'sellers' => $sellers,
            'routes' => $routes,
        ];
    }

    private function fleetScope(): array
    {
        $vehicles = Vehicle::with('vehicleType:id,name')
            ->orderBy('identifier')
            ->get(['id', 'identifier', 'maker', 'vehicle_type_id', 'seat_count', 'active'])
            ->map(fn ($vehicle) => [
                'id' => $vehicle->id,
                'identifier' => $vehicle->identifier,
                'maker' => $vehicle->maker,
                'seat_count' => $vehicle->seat_count,
                'type' => $vehicle->vehicleType?->name ?? '—',
                'active' => (bool) $vehicle->active,
            ])
            ->values();

        $pools = StationVehicleAssignment::with(['station:id,name', 'vehicle:id,identifier'])
            ->where('active', true)
            ->get()
            ->groupBy('station_id')
            ->map(function ($assignments) {
                return [
                    'station' => $assignments->first()->station?->name ?? '—',
                    'vehicles' => $assignments
                        ->map(fn ($assignment) => $assignment->vehicle?->identifier)
                        ->filter()
                        ->values(),
                ];
            })
            ->values();

        $unpooledVehicles = Vehicle::where('active', true)
            ->whereDoesntHave('stationAssignments', fn ($query) => $query->where('active', true))
            ->orderBy('identifier')
            ->get(['id', 'identifier'])
            ->pluck('identifier');

        $crews = CrewMember::where('active', true)
            ->with(['currentAssignment.vehicle:id,identifier'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'phone'])
            ->map(fn ($crew) => [
                'id' => $crew->id,
                'name' => $crew->name,
                'role' => $crew->role === 'driver' ? 'Chauffeur' : 'Assistant',
                'phone' => $crew->phone,
                'vehicle' => $crew->currentAssignment?->vehicle?->identifier ?? null,
            ])
            ->values();

        $uncrewedVehicles = Vehicle::where('active', true)
            ->whereDoesntHave('currentCrew')
            ->orderBy('identifier')
            ->get(['id', 'identifier'])
            ->pluck('identifier');

        return [
            'type' => 'fleet_manager',
            'vehicles' => $vehicles,
            'pools' => $pools,
            'unpooledVehicles' => $unpooledVehicles,
            'crews' => $crews,
            'uncrewedVehicles' => $uncrewedVehicles,
        ];
    }

    private function accountantScope(): array
    {
        $operational = OperationalSetting::current();
        $dayStart = $operational->operationalDayStartHour();

        return [
            'type' => 'accountant',
            'currency' => 'F CFA',
            'paymentMethods' => $this->paymentMethods(),
            'closingRules' => [
                "La journée opérationnelle débute à {$dayStart}h00 (heure locale).",
                'Les ventes en espèces et les récompenses Okohi sont comptabilisées séparément.',
                'La clôture quotidienne des ventes est rapprochée par le superviseur du périmètre.',
            ],
            'perimeters' => ['Toutes les gares'],
            'reportTypes' => [
                'Rapport de ventes détaillé',
                'Chiffre d\'affaires et revenus',
                'Classement des vendeurs',
                'Tendance quotidienne',
                'Export CSV',
            ],
            'contacts' => $this->adminContacts(),
        ];
    }

    private function executiveScope(): array
    {
        $ticketSettings = TicketSetting::getSettings();
        $operational = OperationalSetting::current();
        $operationalSettings = $operational->settings ?? [];

        return [
            'type' => 'executive',
            'network' => [
                'stations' => Station::where('active', true)->count(),
                'routes' => Route::where('active', true)->count(),
                'vehicles' => Vehicle::where('active', true)->count(),
                'trips' => Trip::count(),
            ],
            'supervisors' => $this->adminContacts('supervisor'),
            'policies' => [
                'sellerCompensationEnabled' => (bool) data_get($operationalSettings, 'seller_compensation_enabled', false),
                'sellerCompensationMaxAmount' => (int) data_get($operationalSettings, 'seller_compensation_max_amount', 0),
                'automaticConnectionAllocation' => (bool) $operational->automatic_connection_allocation,
                'connectionTransferBufferMinutes' => (int) $operational->connection_transfer_buffer_minutes,
            ],
            'services' => [
                'okohiConnected' => $ticketSettings->hasOkohiIntegration(),
                'crewSales' => $ticketSettings->allowsCrewSales(),
                'deviceRestrictions' => data_get($operationalSettings, 'device_restrictions', ['web' => false, 'control' => false]),
            ],
        ];
    }

    private function adminContacts(string $role = 'admin'): array
    {
        return User::where('role', $role)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'telephone'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'telephone' => $user->telephone,
            ])
            ->values()
            ->all();
    }

    private function paymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Espèces (F CFA)'],
            ['value' => 'okohi_reward', 'label' => 'Récompense Okohi'],
        ];
    }

    /**
     * Directives et procédures métier par rôle (lecture seule).
     */
    private function directivesData(User $user): array
    {
        return match ($user->role) {
            'seller' => [
                ['title' => 'Vente de billets', 'content' => 'Sélectionnez le voyage, la gare de départ, la destination puis le nombre de places avant de confirmer. Chaque billet est imprimé avec un code QR.'],
                ['title' => 'Remboursement & compensation', 'content' => 'Une annulation ou compensation doit respecter les règles de la compagnie. Au-delà du plafond autorisé, la demande doit être approuvée par le superviseur.'],
                ['title' => 'Réimpression', 'content' => 'Un billet peut être réimprimé depuis l\'historique des ventes si l\'impression initiale a échoué.'],
                ['title' => 'Poste de travail', 'content' => 'Les ventes ne sont autorisées que sur les appareils enregistrés et approuvés par l\'administrateur.'],
            ],
            'supervisor' => [
                ['title' => 'Supervision des ventes', 'content' => 'Vous suivez les ventes des gares de votre périmètre et pouvez approuver les compensations qui dépassent le plafond des vendeurs.'],
                ['title' => 'Gestion des incidents', 'content' => 'En cas d\'incident (retard, panne, conflit de siège), appliquez la procédure définie et signalez-le via le canal prévu.'],
                ['title' => 'Création de comptes vendeur', 'content' => 'Les comptes vendeur que vous créez sont affectés à votre périmètre; les réglages structurels restent sous la responsabilité de l\'administrateur.'],
            ],
            'fleet_manager' => [
                ['title' => 'Préparation des véhicules', 'content' => 'Chaque véhicule est rattaché à un pool de gare. Avant le départ, vérifiez l\'équipage, le type de véhicule et le nombre de places.'],
                ['title' => 'Contrôle de la flotte', 'content' => 'Assurez-vous qu\'aucun véhicule actif ne reste sans gare ni équipage. Signalez les véhicules indisponibles à l\'administrateur.'],
                ['title' => 'Équipages', 'content' => 'Chaque véhicule doit disposer d\'un chauffeur et d\'un assistant affectés à la rotation en cours.'],
            ],
            'accountant' => [
                ['title' => 'Clôture comptable', 'content' => 'Rapprochez les ventes du jour (espèces et récompenses Okohi) avec les rapports de ventes avant clôture.'],
                ['title' => 'Export des données', 'content' => 'Les rapports peuvent être exportés en CSV pour être intégrés à votre outil comptable.'],
            ],
            'executive' => [
                ['title' => 'Pilotage du réseau', 'content' => 'Le tableau de bord analytique vous donne une vue consolidée des ventes, du chiffre d\'affaires et de l\'occupation des véhicules.'],
                ['title' => 'Politiques & services', 'content' => 'Les politiques commerciales et l\'état des services (fidélité, contrôle des appareils) sont consultables ci-dessus; leur modification relève de l\'administrateur.'],
            ],
            default => [],
        };
    }

    private function settingsStats(): array
    {
        $user = auth()->user();

        if (! $user) {
            return $this->emptyStats();
        }

        return match ($user->role) {
            'supervisor' => $this->supervisorStats($user),
            'seller' => $this->sellerStats($user),
            'fleet_manager' => $this->fleetStats(),
            default => $this->globalStats(),
        };
    }

    private function emptyStats(): array
    {
        return [
            'stations' => 0,
            'destinations' => 0,
            'routes' => 0,
            'vehicles' => 0,
            'vehicleTypes' => 0,
            'trips' => 0,
            'fares' => 0,
            'departureSchedules' => 0,
            'users' => 0,
            'assignments' => 0,
            'crewMembers' => 0,
            'crewAssignments' => 0,
        ];
    }

    private function supervisorStats(User $user): array
    {
        $stationIds = $user->getActiveStationIds();

        return [
            'stations' => count($stationIds),
            'destinations' => 0,
            'routes' => 0,
            'vehicles' => 0,
            'vehicleTypes' => 0,
            'trips' => 0,
            'fares' => 0,
            'users' => User::where('role', 'seller')
                ->where(function ($query) use ($stationIds) {
                    $query->whereHas('stationAssignments', fn ($sub) => $sub
                        ->whereIn('station_id', $stationIds)
                        ->where('active', true))
                        ->orWhere(fn ($sub) => $sub
                            ->whereDoesntHave('stationAssignments')
                            ->where('settings->creator_id', auth()->id()));
                })
                ->count(),
            'assignments' => UserStationAssignment::whereIn('station_id', $stationIds)
                ->whereHas('user', fn ($query) => $query->where('role', 'seller'))
                ->count(),
            'crewMembers' => 0,
            'crewAssignments' => 0,
        ];
    }

    private function sellerStats(User $user): array
    {
        $stationIds = $user->getActiveStationIds();

        return [
            'stations' => count($stationIds),
            'destinations' => 0,
            'routes' => $user->accessibleRoutesQuery()->count(),
            'vehicles' => 0,
            'vehicleTypes' => 0,
            'trips' => 0,
            'fares' => 0,
            'users' => 0,
            'assignments' => 0,
            'crewMembers' => 0,
            'crewAssignments' => 0,
        ];
    }

    private function fleetStats(): array
    {
        return [
            'stations' => 0,
            'destinations' => 0,
            'routes' => 0,
            'vehicles' => Vehicle::count(),
            'vehicleTypes' => VehicleType::count(),
            'trips' => 0,
            'fares' => 0,
            'users' => 0,
            'assignments' => 0,
            'crewMembers' => CrewMember::count(),
            'crewAssignments' => VehicleCrewAssignment::count(),
        ];
    }

    private function globalStats(): array
    {
        return [
            'stations' => Station::count(),
            'destinations' => Destination::count(),
            'routes' => Route::count(),
            'vehicles' => Vehicle::count(),
            'vehicleTypes' => VehicleType::count(),
            'trips' => Trip::count(),
            'fares' => RouteFare::count(),
            'departureSchedules' => DepartureSchedule::count(),
            'users' => User::count(),
            'assignments' => UserStationAssignment::count(),
            'crewMembers' => CrewMember::count(),
            'crewAssignments' => VehicleCrewAssignment::count(),
        ];
    }
}
