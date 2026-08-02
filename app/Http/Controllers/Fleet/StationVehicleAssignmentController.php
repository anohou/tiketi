<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        ]);

        $assignments = StationVehicleAssignment::query()
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
            ->orderBy('vehicle_id')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Fleet/StationVehicleAssignments/Index', [
            'assignments' => $assignments,
            'stations' => Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city', 'code']),
            'vehicles' => Vehicle::with('vehicleType')->where('active', true)->orderBy('identifier')
                ->get(['id', 'identifier', 'maker', 'seat_count', 'vehicle_type_id']),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'station_id' => $filters['station_id'] ?? '',
                'vehicle_id' => $filters['vehicle_id'] ?? '',
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
}
