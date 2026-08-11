<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ticketing\EvaluateTripSalesReadiness;
use App\Http\Controllers\Controller;
use App\Models\DepartureSchedule;
use App\Models\DepartureScheduleException;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\Station;
use App\Models\VehicleType;
use App\Services\DepartureScheduleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DepartureScheduleController extends Controller
{
    public function __construct(
        private readonly DepartureScheduleCalendar $calendar,
        private readonly EvaluateTripSalesReadiness $readiness,
    ) {}

    public function index(Request $request)
    {
        $query = DepartureSchedule::with([
            'station',
            'route.originStation',
            'route.destinationStation',
            'originStation',
            'destinationStation',
            'defaultVehicleType',
            'exceptions',
        ])->withCount(['trips']);

        if ($request->filled('station_id')) {
            $query->where('station_id', $request->input('station_id'));
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $schedules = $query->orderBy('departure_time')->paginate(25)->withQueryString();

        $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city']);
        $routes = Route::where('active', true)->with(['originStation', 'destinationStation'])->orderBy('name')->get(['id', 'name']);
        $vehicleTypes = VehicleType::where('active', true)->orderBy('name')->get(['id', 'name', 'seat_count']);

        return Inertia::render('Admin/DepartureSchedules/Index', [
            'schedules' => $schedules,
            'stations' => $stations,
            'routes' => $routes,
            'vehicleTypes' => $vehicleTypes,
            'filters' => $request->only(['station_id', 'active']),
            'companyDefaultPolicy' => $this->readiness->companyDefaultPolicy(),
            'stats' => [
                'departureSchedules' => DepartureSchedule::count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DepartureSchedule::create(array_merge($data, [
            'created_by' => auth()->id(),
        ]));

        return back()->with('success', 'Programme de départ créé.');
    }

    public function update(Request $request, DepartureSchedule $departure_schedule)
    {
        $data = $this->validated($request, $departure_schedule);

        $departure_schedule->update($data);

        return back()->with('success', 'Programme de départ mis à jour.');
    }

    public function destroy(DepartureSchedule $departure_schedule)
    {
        // Les voyages déjà matérialisés conservent leur historique (champs copiés).
        $departure_schedule->exceptions()->delete();
        $departure_schedule->delete();

        return back()->with('success', 'Programme de départ supprimé.');
    }

    public function storeException(Request $request, DepartureSchedule $schedule)
    {
        $data = $request->validate([
            'service_date' => 'required|date',
            'type' => 'required|in:'.implode(',', DepartureScheduleException::TYPES),
            'replacement_time' => 'nullable|date_format:H:i|required_if:type,time_changed',
            'replacement_capacity' => 'nullable|integer|min:1|required_if:type,capacity_changed',
            'reason' => 'nullable|string|max:500',
        ]);

        $schedule->exceptions()->updateOrCreate(
            ['service_date' => $data['service_date']],
            [
                'type' => $data['type'],
                'replacement_time' => $data['replacement_time'] ?? null,
                'replacement_capacity' => $data['replacement_capacity'] ?? null,
                'reason' => $data['reason'] ?? null,
                'created_by' => auth()->id(),
            ]
        );

        return back()->with('success', 'Exception calendaire enregistrée.');
    }

    public function destroyException(DepartureSchedule $schedule, DepartureScheduleException $exception)
    {
        $exception->delete();

        return back()->with('success', 'Exception calendaire supprimée.');
    }

    /**
     * Calendrier calculé d'un programme sur une plage, sans matérialisation.
     */
    public function calendar(Request $request, DepartureSchedule $schedule)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = CarbonImmutable::parse($request->input('from'));
        $to = CarbonImmutable::parse($request->input('to'))->endOfDay();

        $days = [];
        $day = $from->copy();

        while ($day->lte($to) && count($days) < 370) {
            $occurrences = $this->calendar->occurrencesForDate($schedule, $day);

            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->isoFormat('dddd D MMMM YYYY'),
                'occurrences' => $occurrences->map(fn ($occ) => [
                    'time' => $occ['departure_time']->format('H:i'),
                    'capacity' => $occ['capacity'],
                    'cancelled' => $occ['cancelled'],
                    'exception_type' => $occ['exception']?->type,
                ])->values(),
            ];

            $day = $day->addDay();
        }

        return response()->json([
            'schedule_id' => $schedule->id,
            'days' => $days,
        ]);
    }

    /**
     * Occurrences à matérialiser pour la prochaine journée opérationnelle
     * (aperçu, sans création).
     */
    public function previewNextDay(Request $request)
    {
        $instant = now();
        $settings = OperationalSetting::current();
        $startHour = $settings->operationalDayStartHour();
        $start = CarbonImmutable::parse($instant->startOfDay()->addHours($startHour));
        if ($instant->lt($start)) {
            $start = $start->subDay();
        }
        $end = $start->addHours($settings->scheduledTripLookaheadHours());

        $schedules = DepartureSchedule::with('exceptions')->where('active', true)->get();
        $occurrences = $this->calendar->occurrencesForDateAcross($schedules, $start->toDateString());

        return response()->json([
            'operational_day' => $start->toDateString(),
            'occurrences' => $occurrences->map(fn ($occ) => [
                'schedule_id' => $occ['schedule']->id,
                'schedule_label' => $occ['schedule']->display_label,
                'route_label' => $occ['schedule']->route_label,
                'service_date' => $occ['service_date'],
                'time' => $occ['departure_time']->format('H:i'),
                'capacity' => $occ['capacity'],
            ])->values(),
        ]);
    }

    private function validated(Request $request, ?DepartureSchedule $schedule = null): array
    {
        $scheduleId = $schedule?->id;

        $data = $request->validate([
            'station_id' => 'required|uuid|exists:stations,id',
            'route_id' => 'required|uuid|exists:routes,id',
            'origin_station_id' => 'required|uuid|exists:stations,id',
            'destination_station_id' => 'required|uuid|exists:stations,id|different:origin_station_id',
            'departure_time' => 'required|date_format:H:i',
            'days_of_week' => 'required|array|min:1',
            'days_of_week.*' => 'integer|between:1,7|distinct',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'timezone' => 'required|string|max:64',
            'planned_capacity' => 'nullable|integer|min:1',
            'confirmed_return_quota' => 'nullable|integer|min:0',
            'default_vehicle_type_id' => 'required|uuid|exists:vehicle_types,id',
            'vehicle_assignment_policy' => 'nullable|in:require_real_vehicle,allow_planned_capacity',
            'booking_type' => 'nullable|in:seat_assignment,bulk,semi_intelligent',
            'sales_control' => 'nullable|in:open,closed',
            'allows_open_connections' => 'nullable|boolean',
            'automatic_connection_allocation' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ]);

        // Un programme en vente sur capacité planifiée exige une capacité prévisionnelle.
        $policy = $data['vehicle_assignment_policy']
            ?? $this->readiness->companyDefaultPolicy();

        if ($policy === DepartureSchedule::POLICY_ALLOW_PLANNED_CAPACITY
            && ($data['planned_capacity'] ?? null) === null) {
            throw ValidationException::withMessages([
                'planned_capacity' => 'La vente sur capacité planifiée exige une capacité prévisionnelle positive.',
            ]);
        }

        if ($data['confirmed_return_quota'] !== null
            && $data['planned_capacity'] !== null
            && $data['confirmed_return_quota'] > $data['planned_capacity']) {
            throw ValidationException::withMessages([
                'confirmed_return_quota' => 'Le quota de billets prioritaires ne peut pas dépasser la capacité prévisionnelle.',
            ]);
        }

        // La gare propriétaire du programme doit être l'origine.
        if ($data['station_id'] !== $data['origin_station_id']) {
            throw ValidationException::withMessages([
                'station_id' => 'La gare du programme doit correspondre à la gare d’origine du départ.',
            ]);
        }

        // Les deux gares doivent appartenir au trajet.
        $route = Route::with('routeStopOrders')->findOrFail($data['route_id']);
        $routeStationIds = collect([$route->origin_station_id])
            ->concat($route->routeStopOrders->pluck('station_id'))
            ->push($route->destination_station_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach (['origin_station_id', 'destination_station_id'] as $field) {
            if (! in_array($data[$field], $routeStationIds, true)) {
                throw ValidationException::withMessages([
                    $field => 'Cette gare n’appartient pas au trajet sélectionné.',
                ]);
            }
        }

        // Pas de doublon actif pour le même trajet, origine, destination,
        // horaire et période (sauf le programme lui-même en édition).
        $duplicate = DepartureSchedule::where('route_id', $data['route_id'])
            ->where('origin_station_id', $data['origin_station_id'])
            ->where('destination_station_id', $data['destination_station_id'])
            ->whereTime('departure_time', $data['departure_time'])
            ->where('active', true)
            ->where('id', '!=', $scheduleId)
            ->where(function ($query) use ($data) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $data['valid_from']);
            })
            ->where(function ($query) use ($data) {
                $query->whereNull($data['valid_until'] ? 'valid_until' : 'valid_from')
                    ->orWhere('valid_from', '<=', $data['valid_until'] ?? $data['valid_from']);
            })
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'departure_time' => 'Un programme actif existe déjà pour ce trajet, cet horaire et cette période.',
            ]);
        }

        $data['days_of_week'] = array_values(array_map('intval', $data['days_of_week']));
        $data['allows_open_connections'] = (bool) ($data['allows_open_connections'] ?? false);
        $data['automatic_connection_allocation'] = (bool) ($data['automatic_connection_allocation'] ?? false);
        $data['active'] = (bool) ($data['active'] ?? true);
        $data['booking_type'] = $data['booking_type'] ?? 'seat_assignment';
        $data['sales_control'] = $data['sales_control'] ?? 'open';

        return $data;
    }
}
