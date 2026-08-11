<?php

namespace App\Http\Controllers\Seller;

use App\Domain\Ticketing\EvaluateTripSalesReadiness;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Http\Controllers\Concerns\ChecksStationAccess;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\AssignRealVehicleToTrip;
use App\Services\AuthorizePlannedCapacitySales;
use App\Services\TripCapacityService;
use App\Services\VehiclePoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Tableau des départs du jour d'une gare + actions d'affectation du car réel
 * et de report explicite de l'affectation (vente sur capacité planifiée).
 */
class DepartureBoardController extends Controller
{
    use ChecksStationAccess;

    public function __construct(
        private readonly EvaluateTripSalesReadiness $readiness,
        private readonly AssignRealVehicleToTrip $assignVehicle,
        private readonly AuthorizePlannedCapacitySales $authorizePlanned,
        private readonly TripCapacityService $capacity,
        private readonly VehiclePoolService $vehiclePool,
    ) {}

    public function index(Request $request, Station $station)
    {
        // Point E : le vendeur ne consulte que ses gares actives.
        $this->assertUserCanAccessStation($station);

        $date = $request->input('date', now()->toDateString());

        $trips = Trip::with([
            'route.originStation',
            'route.destinationStation',
            'originStation',
            'destinationStation',
            'vehicle.vehicleType',
            'departureSchedule',
        ])
            ->where('origin_station_id', $station->id)
            ->whereDate('departure_at', $date)
            ->orderBy('departure_at')
            ->get()
            ->map(fn (Trip $trip) => $this->presentTrip($trip))
            ->values();

        // Point G : le pool est limité au tenant, aux actifs, aux non
        // techniques et aux affectations valides à la date du voyage.
        $vehicles = $this->vehiclePool->listForStation(
            $station,
            $date,
            auth()->user()?->isAdmin() ?? false,
        );

        return Inertia::render('Seller/DepartureBoard', [
            'station' => $station->only(['id', 'name', 'city']),
            'date' => $date,
            'trips' => $trips,
            'vehicles' => $vehicles,
            'companyDefaultPolicy' => $this->readiness->companyDefaultPolicy(),
        ]);
    }

    public function assignVehicle(Request $request, Trip $trip)
    {
        // Point E : le vendeur n'affecte un car que sur ses voyages accessibles.
        $this->assertUserCanAccessTrip($trip);

        $request->validate([
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $vehicle = Vehicle::findOrFail($request->input('vehicle_id'));

        try {
            $trip = $this->assignVehicle->assign($trip, $vehicle, auth()->user(), $request->input('reason'));
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode], $e->httpStatus);
        }

        return response()->json([
            'message' => 'Car réel affecté. Les ventes sont ouvertes et les sièges sans place ont été attribués.',
            'trip' => $this->presentTrip($trip->fresh([
                'route.originStation',
                'route.destinationStation',
                'originStation',
                'destinationStation',
                'vehicle.vehicleType',
                'departureSchedule',
            ])),
        ]);
    }

    public function deferVehicleAssignment(Request $request, Trip $trip)
    {
        // Point E : le vendeur ne reporte le car que sur ses voyages accessibles.
        $this->assertUserCanAccessTrip($trip);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $trip = $this->authorizePlanned->authorize($trip, auth()->user(), $request->input('reason'));
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode], $e->httpStatus);
        }

        return response()->json([
            'message' => 'Vente sur capacité planifiée ouverte. Le car réel reste obligatoire avant l’embarquement et le départ.',
            'trip' => $this->presentTrip($trip->fresh([
                'route.originStation',
                'route.destinationStation',
                'originStation',
                'destinationStation',
                'vehicle.vehicleType',
                'departureSchedule',
            ])),
        ]);
    }

    private function presentTrip(Trip $trip): array
    {
        $readiness = $this->readiness->evaluate($trip);

        return [
            'id' => $trip->id,
            'code' => $trip->code,
            'departure_at' => $trip->departure_at?->toDateTimeString(),
            'service_date' => $trip->service_date?->toDateString(),
            'status' => $trip->status,
            'route_label' => $trip->display_name,
            'origin' => $trip->originStation?->name,
            'destination' => $trip->destinationStation?->name,
            'from_schedule' => (bool) $trip->departure_schedule_id,
            'schedule_label' => $trip->departureSchedule?->display_label,
            'vehicle' => $trip->vehicle ? [
                'id' => $trip->vehicle->id,
                'identifier' => $trip->vehicle->identifier,
                'is_placeholder' => (bool) $trip->vehicle->is_placeholder,
                'seat_count' => $trip->vehicle->seat_count ?? $trip->vehicle->vehicleType?->seat_count,
            ] : null,
            'capacity' => $trip->capacity(),
            'sales_ready' => $trip->isSalesReady(),
            'operational_ready' => $trip->isOperationalReady(),
            'awaiting_real_vehicle' => $trip->isAwaitingRealVehicle(),
            'policy' => $trip->vehiclePolicy(),
            'allows_planned_capacity' => $trip->allowsPlannedCapacitySales(),
            'sales_blocked' => ! $readiness->allowed,
            'sales_blocked_reason' => $readiness->message,
            'engaged' => $this->capacity->activeEngagements($trip),
            'remaining' => $this->capacity->remainingCapacity($trip),
            'deferred_at' => $trip->vehicle_assignment_deferred_at?->toDateTimeString(),
            'deferred_by' => $trip->vehicle_assignment_deferred_by,
            'deferred_reason' => $trip->vehicle_assignment_deferred_reason,
            'seat_assignment_version' => $trip->seat_assignment_version,
        ];
    }
}
