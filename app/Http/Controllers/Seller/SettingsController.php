<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Route as BusRoute;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /**
     * Page d'accueil du Paramétrage vendeur (grille de raccourcis).
     */
    public function index()
    {
        return Inertia::render('Seller/Settings/Index', [
            'stats' => $this->settingsStats(auth()->user()),
        ]);
    }

    public function company()
    {
        $isTenant = function_exists('tenancy') && tenancy()->initialized;
        $ticketSettings = TicketSetting::getSettings();

        return Inertia::render('Admin/Settings/Enterprise', [
            'company' => [
                'name' => $isTenant ? tenant('name') : $ticketSettings->company_name,
                'email' => $isTenant ? tenant('email') : null,
                'phone' => $isTenant ? tenant('phone') : null,
                'logo_url' => $isTenant ? tenant('logo_url') : null,
            ],
            'stats' => $this->settingsStats(auth()->user()),
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
        ]);
    }

    public function loyalty()
    {
        $settings = TicketSetting::getSettings();
        $connected = $settings->hasOkohiIntegration();

        return Inertia::render('Admin/Settings/Loyalty', [
            'loyalty' => [
                'connected' => $connected,
                'rewards' => $connected ? $this->publicRewardsCatalog() : [],
            ],
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
            'loyaltyApiPrefix' => 'seller',
        ]);
    }

    public function stations()
    {
        $stationIds = auth()->user()->getActiveStationIds();

        $stations = Station::with([
            'destination',
            'userAssignments.user',
            'routeStopOrders.route.originStation',
            'routeStopOrders.route.destinationStation',
            'routeStopOrders.route.routeStopOrders.station',
            'routeStopOrders.station',
            'originRoutes.originStation',
            'originRoutes.destinationStation',
            'originRoutes.routeStopOrders.station',
            'destinationRoutes.originStation',
            'destinationRoutes.destinationStation',
            'destinationRoutes.routeStopOrders.station',
        ])->withCount(['userAssignments'])
            ->whereIn('id', $stationIds)
            ->orderBy('name')
            ->paginate(50);

        return Inertia::render('Admin/Stations/Index', [
            'stations' => $stations,
            'destinations' => $this->accessibleDestinations($stationIds),
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
        ]);
    }

    public function routes()
    {
        $user = auth()->user();
        $stationIds = $user->getActiveStationIds();

        $routes = $user->accessibleRoutesQuery()
            ->with([
                'originDestination',
                'targetDestination',
                'originStation',
                'destinationStation',
                'routeStopOrders.station',
                'trips.vehicle',
            ])
            ->withCount(['trips', 'routeStopOrders'])
            ->orderBy('name')
            ->paginate(50);

        $fares = RouteFare::with(['fromStation', 'toStation'])
            ->where(function (Builder $query) use ($stationIds) {
                $query->whereIn('from_station_id', $stationIds)
                    ->orWhereIn('to_station_id', $stationIds);
            })
            ->get();

        return Inertia::render('Admin/Routes/Index', [
            'routes' => $routes,
            'destinations' => $this->accessibleDestinations($stationIds)->map(fn (Destination $destination) => [
                'id' => $destination->id,
                'name' => $destination->name,
            ])->values(),
            'stations' => $this->stationOptions($stationIds),
            'fares' => $fares,
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
        ]);
    }

    public function vehicles()
    {
        $user = auth()->user();
        $stationIds = $user->getActiveStationIds();

        $filters = request()->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'station_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
        ]);

        $assignments = $this->currentStationVehicleAssignments($stationIds)
            ->with(['station', 'vehicle.vehicleType'])
            ->when($filters['station_id'] ?? null, fn (Builder $query, string $stationId) => $query->where('station_id', $stationId))
            ->when($filters['vehicle_id'] ?? null, fn (Builder $query, string $vehicleId) => $query->where('vehicle_id', $vehicleId))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.trim($search).'%';
                $query->where(function (Builder $searchQuery) use ($term) {
                    $searchQuery
                        ->whereLike('notes', $term, caseSensitive: false)
                        ->orWhereHas('station', fn (Builder $station) => $station
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('city', $term, caseSensitive: false)
                            ->orWhereLike('code', $term, caseSensitive: false))
                        ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle
                            ->whereLike('identifier', $term, caseSensitive: false)
                            ->orWhereLike('maker', $term, caseSensitive: false));
                });
            })
            ->orderBy('station_id')
            ->orderBy('vehicle_id')
            ->paginate(20)
            ->withQueryString();

        $vehicles = Vehicle::whereHas('stationAssignments', fn (Builder $query) => $query
            ->whereIn('station_id', $stationIds)
            ->activeOn())
            ->orderBy('identifier')
            ->get(['id', 'identifier', 'vehicle_type_id', 'seat_count', 'maker', 'active']);

        $stations = Station::whereIn('id', $stationIds)->orderBy('name')->get(['id', 'name', 'code', 'city']);

        return Inertia::render('Fleet/StationVehicleAssignments/Index', [
            'assignments' => $assignments,
            'stations' => $stations,
            'vehicles' => $vehicles,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'station_id' => $filters['station_id'] ?? '',
                'vehicle_id' => $filters['vehicle_id'] ?? '',
            ],
            'title' => 'Véhicules de ma gare',
            'subtitle' => 'Flotte actuellement affectée à vos gares',
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
            'filterRoute' => route('seller.settings.vehicles'),
        ]);
    }

    public function team()
    {
        $stationIds = auth()->user()->getActiveStationIds();

        $users = User::with(['stationAssignments.station'])
            ->whereIn('role', ['seller', 'supervisor'])
            ->whereHas('stationAssignments', fn (Builder $query) => $query
                ->whereIn('station_id', $stationIds)
                ->where('active', true))
            ->orderBy('name')
            ->paginate(20);

        $stations = Station::whereIn('id', $stationIds)->orderBy('name')->get(['id', 'name', 'city']);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'stations' => $stations,
            'title' => 'Équipe de ma gare',
            'subtitle' => 'Vendeurs et superviseurs de vos gares d\'affectation',
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
        ]);
    }

    public function assignments()
    {
        $user = auth()->user();
        $stationIds = $user->getActiveStationIds();

        $filters = request()->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'station_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $assignments = UserStationAssignment::query()
            ->with(['user', 'station'])
            ->whereIn('station_id', $stationIds)
            ->when($filters['station_id'] ?? null, fn (Builder $query, string $stationId) => $query->where('station_id', $stationId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, string $userId) => $query->where('user_id', $userId))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.trim($search).'%';
                $query->where(function (Builder $searchQuery) use ($term) {
                    $searchQuery
                        ->whereHas('user', fn (Builder $user) => $user
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('email', $term, caseSensitive: false))
                        ->orWhereHas('station', fn (Builder $station) => $station
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('city', $term, caseSensitive: false)
                            ->orWhereLike('code', $term, caseSensitive: false));
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(40)
            ->withQueryString();

        $assignments->getCollection()->each(function ($assignment) use ($user) {
            $assignment->is_self = $assignment->user_id === $user->id;
        });

        $users = User::whereIn('role', ['seller', 'supervisor'])
            ->whereHas('stationAssignments', fn (Builder $query) => $query
                ->whereIn('station_id', $stationIds)
                ->where('active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $stations = Station::whereIn('id', $stationIds)->orderBy('name')->get(['id', 'name', 'code', 'city']);

        $routes = BusRoute::with(['originStation', 'destinationStation', 'routeStopOrders'])
            ->where('active', true)
            ->where(function (Builder $query) use ($stationIds) {
                $query->whereIn('origin_station_id', $stationIds)
                    ->orWhereIn('destination_station_id', $stationIds)
                    ->orWhereHas('routeStopOrders', fn (Builder $stopOrder) => $stopOrder->whereIn('station_id', $stationIds));
            })
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Assignments/Index', [
            'assignments' => $assignments,
            'users' => $users,
            'stations' => $stations,
            'routes' => $routes,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'station_id' => $filters['station_id'] ?? '',
                'user_id' => $filters['user_id'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
            'filterRoute' => route('seller.settings.assignments'),
        ]);
    }

    public function trips()
    {
        $user = auth()->user();

        $trips = Trip::with(['route.originStation', 'route.destinationStation', 'route.routeStopOrders', 'vehicle', 'tickets.toStation'])
            ->withCount(['tickets as tickets_count' => function ($query) {
                $query->where('status', 'issued');
            }])
            ->whereIn('route_id', $user->accessibleRoutesQuery()->pluck('id'))
            ->upcomingFirst()
            ->paginate(20);

        $vehicleIds = $trips->pluck('vehicle_id')->unique()->filter()->toArray();
        if (! empty($vehicleIds)) {
            $minDate = $trips->min('departure_at');
            $maxDate = $trips->max('departure_at');

            $assignments = VehicleCrewAssignment::whereIn('vehicle_id', $vehicleIds)
                ->where(function ($query) use ($minDate) {
                    $query->whereNull('assigned_to')
                        ->orWhere('assigned_to', '>', $minDate);
                })
                ->where('assigned_from', '<=', $maxDate)
                ->with('crewMember')
                ->get();

            $trips->getCollection()->transform(function ($trip) use ($assignments) {
                $tripCrew = $assignments->filter(function ($assignment) use ($trip) {
                    return $assignment->vehicle_id === $trip->vehicle_id
                        && $assignment->assigned_from <= $trip->departure_at
                        && (is_null($assignment->assigned_to) || $assignment->assigned_to > $trip->departure_at);
                });

                $trip->crew_info = $tripCrew->map(fn ($assignment) => [
                    'role' => $assignment->role,
                    'crew_member' => $assignment->crewMember ? [
                        'id' => $assignment->crewMember->id,
                        'name' => $assignment->crewMember->name,
                        'phone' => $assignment->crewMember->phone,
                        'role' => $assignment->crewMember->role,
                    ] : null,
                ])->values();

                return $trip;
            });
        } else {
            $trips->getCollection()->transform(function ($trip) {
                $trip->crew_info = collect();

                return $trip;
            });
        }

        $routes = $user->accessibleRoutesQuery()
            ->with(['originStation', 'destinationStation', 'routeStopOrders.station'])
            ->orderBy('name')
            ->get();

        $vehicles = Vehicle::whereIn('id', $trips->pluck('vehicle_id')->unique()->filter())
            ->orderBy('identifier')
            ->get(['id', 'identifier']);

        $stations = Station::whereIn('id', $user->getActiveStationIds())->orderBy('name')->get(['id', 'name', 'city']);

        return Inertia::render('Admin/Trips/Index', [
            'trips' => $trips,
            'routes' => $routes,
            'vehicles' => $vehicles,
            'stations' => $stations,
            'title' => 'Voyages de mes trajets',
            'subtitle' => 'Départs prévus sur vos lignes autorisées',
            'permissions' => $this->readOnlyPermissions(),
            'hideTripSidebar' => true,
        ]);
    }

    public function profile()
    {
        $user = auth()->user();

        return Inertia::render('Seller/Settings/Profile', [
            'stats' => $this->settingsStats($user),
            'profile' => $this->profileData($user),
            'directives' => $this->sellerDirectives(),
        ]);
    }

    /**
     * Permissions de consultation du vendeur (lecture seule, export possible).
     */
    private function readOnlyPermissions(): array
    {
        return [
            'canView' => true,
            'canCreate' => false,
            'canUpdate' => false,
            'canDelete' => false,
            'canExport' => true,
            'canManageStops' => false,
            'canManageFares' => false,
        ];
    }

    /**
     * Catalogue public des récompenses Okohi (aucune clé, secret ou URL technique).
     */
    private function publicRewardsCatalog(): array
    {
        $base = rtrim(config('services.okohi.base_url', 'http://127.0.0.1:8001'), '/');

        if (! str_contains($base, '/api/v1/partner')) {
            $base .= '/api/v1/partner';
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Okohi-Integration-Key' => TicketSetting::getSettings()->okohi_integration_key,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/rewards');

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json('data') ?? $response->json();

            return $payload['rewards'] ?? $payload['data'] ?? (is_array($payload) ? $payload : []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Affectations de véhicules actives et valides à la date du jour.
     */
    private function currentStationVehicleAssignments(array $stationIds): Builder
    {
        return StationVehicleAssignment::query()
            ->whereIn('station_id', $stationIds)
            ->activeOn();
    }

    /**
     * Destinations réellement accessibles depuis le périmètre du vendeur.
     */
    private function accessibleDestinations(array $stationIds): Collection
    {
        $destinationIds = BusRoute::where(function (Builder $query) use ($stationIds) {
            $query->whereIn('origin_station_id', $stationIds)
                ->orWhereIn('destination_station_id', $stationIds)
                ->orWhereHas('routeStopOrders', fn (Builder $stopOrder) => $stopOrder->whereIn('station_id', $stationIds));
        })
            ->get(['origin_destination_id', 'target_destination_id'])
            ->flatMap(fn ($route) => [$route->origin_destination_id, $route->target_destination_id])
            ->unique()
            ->filter()
            ->values();

        return Destination::with(['stations' => fn ($query) => $query->whereIn('id', $stationIds)->orderBy('name')])
            ->whereIn('id', $destinationIds)
            ->orderBy('name')
            ->get(['id', 'name', 'settings']);
    }

    /**
     * Gares du périmètre, formatées pour la sélection dans les modales.
     */
    private function stationOptions(array $stationIds): array
    {
        return Station::with('destination')
            ->whereIn('id', $stationIds)
            ->orderBy('name')
            ->get()
            ->map(fn ($station) => [
                'id' => $station->id,
                'name' => $station->name,
                'city' => $station->destination ? $station->destination->name : $station->city,
                'destination_id' => $station->destination_id,
            ])
            ->all();
    }

    /**
     * Compteurs du périmètre vendeur (affichés dans le menu et la grille).
     */
    private function settingsStats(User $user): array
    {
        $stationIds = $user->getActiveStationIds();
        $routeIds = $user->accessibleRoutesQuery()->pluck('id');

        return [
            'stations' => count($stationIds),
            'destinations' => $user->accessibleRoutesQuery()
                ->with('destinationStation:id')
                ->get()
                ->pluck('destinationStation.id')
                ->filter()
                ->unique()
                ->count(),
            'routes' => count($routeIds),
            'vehicles' => $this->currentStationVehicleAssignments($stationIds)->count(),
            'vehicleTypes' => 0,
            'trips' => Trip::whereIn('route_id', $routeIds)
                ->where('departure_at', '>=', now()->subHours(2))
                ->count(),
            'fares' => 0,
            'users' => 0,
            'assignments' => UserStationAssignment::whereIn('station_id', $stationIds)
                ->where('active', true)
                ->count(),
            'crewMembers' => 0,
            'crewAssignments' => 0,
            'team' => User::whereIn('role', ['seller', 'supervisor'])
                ->whereHas('stationAssignments', fn (Builder $query) => $query->whereIn('station_id', $stationIds)->where('active', true))
                ->count(),
        ];
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
        $stationIds = $user->getActiveStationIds();
        $supervisors = collect();

        if (! empty($stationIds)) {
            $supervisors = User::where('role', 'supervisor')
                ->whereHas('stationAssignments', fn (Builder $query) => $query
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
     * Directives et procédures de vente (lecture seule).
     */
    private function sellerDirectives(): array
    {
        return [
            ['title' => 'Vente de billets', 'content' => 'Sélectionnez le voyage, la gare de départ, la destination puis le nombre de places avant de confirmer. Chaque billet est imprimé avec un code QR.'],
            ['title' => 'Remboursement & compensation', 'content' => 'Une annulation ou compensation doit respecter les règles de la compagnie. Au-delà du plafond autorisé, la demande doit être approuvée par le superviseur.'],
            ['title' => 'Réimpression', 'content' => 'Un billet peut être réimprimé depuis l\'historique des ventes si l\'impression initiale a échoué.'],
            ['title' => 'Poste de travail', 'content' => 'Les ventes ne sont autorisées que sur les appareils enregistrés et approuvés par l\'administrateur.'],
        ];
    }
}
