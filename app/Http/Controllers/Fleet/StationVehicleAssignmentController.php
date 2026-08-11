<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Vehicle;
use App\Services\VehicleOperationalStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StationVehicleAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'station_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'operational_status' => ['nullable', 'string'],
        ]);

        $query = StationVehicleAssignment::query()
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
                            ->orWhereLike('maker', $term, caseSensitive: false)
                            ->orWhereHas('vehicleType', fn (Builder $type) => $type->whereLike('name', $term, caseSensitive: false)));
                });
            })
            ->orderByDesc('active')
            ->orderBy('station_id')
            ->orderBy('vehicle_id');

        $assignments = $query->paginate(40)->withQueryString();

        // Si aucune gare spécifique n'est sélectionnée, inclure les véhicules actifs non affectés
        if (empty($filters['station_id'])) {
            $assignedVehicleIds = StationVehicleAssignment::query()
                ->where('active', true)
                ->pluck('vehicle_id')
                ->all();

            $unassignedVehicles = Vehicle::with('vehicleType')
                ->where('active', true)
                ->where('is_placeholder', false)
                ->whereNotIn('id', $assignedVehicleIds)
                ->when($filters['vehicle_id'] ?? null, fn ($q, $vId) => $q->where('id', $vId))
                ->when($filters['search'] ?? null, function ($q, $search) {
                    $term = '%'.trim($search).'%';
                    $q->where(function ($sub) use ($term) {
                        $sub->whereLike('identifier', $term, caseSensitive: false)
                            ->orWhereLike('maker', $term, caseSensitive: false)
                            ->orWhereHas('vehicleType', fn ($type) => $type->whereLike('name', $term, caseSensitive: false));
                    });
                })
                ->get();

            $collection = $assignments->getCollection();
            foreach ($unassignedVehicles as $vehicle) {
                $virtual = new StationVehicleAssignment([
                    'id' => 'unassigned-'.$vehicle->id,
                    'station_id' => null,
                    'vehicle_id' => $vehicle->id,
                    'valid_from' => null,
                    'valid_until' => null,
                    'active' => false,
                    'notes' => 'Non affecté à une gare',
                ]);
                $virtual->setRelation('station', null);
                $virtual->setRelation('vehicle', $vehicle);
                $virtual->setAttribute('is_unassigned', true);

                $collection->push($virtual);
            }
            $assignments->setCollection($collection);
        }

        $service = app(VehicleOperationalStatusService::class);

        $allVehicles = $assignments->getCollection()->pluck('vehicle')->filter();
        $operationalMap = $service->mapForVehicles($allVehicles);

        $assignments->through(function (StationVehicleAssignment $assignment) use ($operationalMap, $service) {
            $op = $operationalMap[$assignment->vehicle_id]
                ?? ($assignment->vehicle ? $service->forVehicle($assignment->vehicle) : null);

            $assignment->setAttribute('operational', $op);
            if ($assignment->vehicle) {
                $assignment->vehicle->setAttribute('operational', $op);
            }

            return $assignment;
        });

        if (! empty($filters['operational_status'])) {
            $targetStatus = $filters['operational_status'];
            $assignments->setCollection(
                $assignments->getCollection()->filter(function (StationVehicleAssignment $assignment) use ($targetStatus) {
                    $op = $assignment->getAttribute('operational');

                    return ($op['status'] ?? 'available') === $targetStatus;
                })->values()
            );
        }

        $operationalSummary = $service->summaryForVehicles($this->fleetVehiclesQuery());

        return Inertia::render('Fleet/StationVehicleAssignments/Index', [
            'assignments' => $assignments,
            'stations' => Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city', 'code']),
            'vehicles' => Vehicle::with('vehicleType')->where('active', true)->where('is_placeholder', false)->orderBy('identifier')
                ->get(['id', 'identifier', 'maker', 'seat_count', 'vehicle_type_id']),
            'operationalSummary' => $operationalSummary,
            'stats' => [
                'stationVehicleAssignments' => StationVehicleAssignment::activeOn()->count(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'station_id' => $filters['station_id'] ?? '',
                'vehicle_id' => $filters['vehicle_id'] ?? '',
                'operational_status' => $filters['operational_status'] ?? '',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->ensureNoOverlap($data);
        StationVehicleAssignment::create($data);

        return back()->with('success', 'Véhicule ajouté au pool de la gare.');
    }

    public function update(Request $request, StationVehicleAssignment $stationVehicleAssignment)
    {
        $data = $this->validated($request);
        $this->ensureNoOverlap($data, $stationVehicleAssignment);
        $stationVehicleAssignment->update($data);

        return back()->with('success', 'Affectation véhicule–gare mise à jour.');
    }

    public function destroy(StationVehicleAssignment $stationVehicleAssignment)
    {
        $stationVehicleAssignment->delete();

        return back()->with('success', 'Véhicule retiré du pool de la gare.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'station_id' => ['required', 'uuid', Rule::exists('stations', 'id')->where('active', true)],
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('active', true)],
            'permanent' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'required_if:permanent,false', 'date'],
            'valid_until' => ['nullable', 'required_if:permanent,false', 'date', 'after_or_equal:valid_from'],
            'active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['permanent']) {
            $data['valid_from'] = null;
            $data['valid_until'] = null;
        }

        unset($data['permanent']);

        return $data;
    }

    private function ensureNoOverlap(array $data, ?StationVehicleAssignment $ignored = null): void
    {
        if (! $data['active']) {
            return;
        }

        $start = isset($data['valid_from']) ? Carbon::parse($data['valid_from'])->toDateString() : '0001-01-01';
        $end = isset($data['valid_until']) ? Carbon::parse($data['valid_until'])->toDateString() : '9999-12-31';

        $conflict = StationVehicleAssignment::query()
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('active', true)
            ->when($ignored, fn (Builder $query) => $query->where($ignored->getKeyName(), '!=', $ignored->getKey()))
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $end))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $start))
            ->with('station:id,name')
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'vehicle_id' => "Ce véhicule appartient déjà au pool de {$conflict->station->name} sur cette période.",
            ]);
        }
    }

    /**
     * Flotte concernée par l'écran, limitée aux véhicules du gestionnaire
     * pour le rôle fleet_manager (les placeholders techniques sont exclus).
     */
    private function fleetVehiclesQuery(): Collection
    {
        $query = Vehicle::query()
            ->where('is_placeholder', false)
            ->get(['id', 'active', 'inactive_reason']);

        $user = auth()->user();
        if ($user && $user->role === 'fleet_manager') {
            $query = Vehicle::query()
                ->where('is_placeholder', false)
                ->whereHas('managers', fn (Builder $query) => $query->where('users.id', $user->id))
                ->get(['id', 'active', 'inactive_reason']);
        }

        return $query;
    }
}
