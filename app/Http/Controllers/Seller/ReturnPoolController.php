<?php

namespace App\Http\Controllers\Seller;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Services\ChangeReturnPreference;
use App\Services\ReturnEngagementReportService;
use App\Services\ReturnJourneyAllocator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReturnPoolController extends Controller
{
    public function index(Request $request)
    {
        $stationIds = $request->user()->getActiveStationIds();

        $query = TicketJourney::with([
            'ticket', 'trip.vehicle', 'fromStation', 'toStation', 'departureSchedule',
        ])->where('direction', TicketJourney::DIRECTION_RETURN)
            ->whereIn('status', [
                TicketJourney::STATUS_PENDING,
                TicketJourney::STATUS_AWAITING_TRIP,
                TicketJourney::STATUS_READY,
                TicketJourney::STATUS_ASSIGNED,
            ]);

        if (! $request->user()->isAdmin()) {
            $query->whereIn('from_station_id', $stationIds);
        }

        if ($request->filled('station_id')) {
            abort_unless($request->user()->isAdmin() || in_array($request->string('station_id')->toString(), $stationIds, true), 403);
            $query->where('from_station_id', $request->string('station_id')->toString());
        }
        if ($request->filled('mode')) {
            $query->where('selection_mode', $request->string('mode')->toString());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('date')) {
            $query->whereDate('desired_travel_date', $request->string('date')->toString());
        }

        $journeys = $query->orderByRaw("CASE status WHEN 'awaiting_trip' THEN 0 WHEN 'ready' THEN 1 WHEN 'assigned' THEN 2 ELSE 3 END")
            ->orderBy('desired_travel_date')
            ->orderBy('created_at')
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['journeys' => $journeys]);
        }

        $tripQuery = Trip::with(['route.routeStopOrders.station', 'originStation', 'destinationStation', 'vehicle'])
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('departure_at', '>=', now()->subHour());
        if (! $request->user()->isAdmin()) {
            $tripQuery->whereIn('route_id', $request->user()->accessibleRoutesQuery()->pluck('id'))
                ->whereIn('origin_station_id', $stationIds);
        }

        return Inertia::render('Seller/ReturnPool', [
            'journeys' => $journeys,
            'trips' => $tripQuery->orderBy('departure_at')->get(),
            'stations' => Station::whereIn('id', $journeys->pluck('from_station_id')->unique())
                ->orderBy('name')->get(['id', 'name', 'city']),
        ]);
    }

    public function report(Request $request, ReturnEngagementReportService $service)
    {
        $stationId = $request->filled('station_id') ? $request->string('station_id')->toString() : null;

        if ($stationId && ! $request->user()->isAdmin()) {
            abort_unless(in_array($stationId, $request->user()->getActiveStationIds(), true), 403);
        }

        return response()->json($service->report($stationId));
    }

    public function assign(Request $request, TicketJourney $journey, ReturnJourneyAllocator $allocator)
    {
        $this->assertAccess($request, $journey);

        $validated = $request->validate([
            'trip_id' => ['required', 'uuid', 'exists:trips,id'],
            'seat_number' => ['nullable', 'integer', 'min:1'],
        ]);

        $trip = Trip::findOrFail($validated['trip_id']);

        try {
            $journey = $allocator->assign($journey, $trip, $validated['seat_number'] ?? null, $request->user());
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        }

        return response()->json(['message' => 'Retour affecté au voyage.', 'journey' => $journey->load(['trip.vehicle', 'fromStation', 'toStation'])]);
    }

    public function unassign(Request $request, TicketJourney $journey, ReturnJourneyAllocator $allocator)
    {
        $this->assertAccess($request, $journey);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $journey = $allocator->unassign($journey, $request->user(), $validated['reason'] ?? null);
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        }

        return response()->json(['message' => 'Retour retiré du voyage (remis dans le pool).', 'journey' => $journey->fresh()]);
    }

    public function updatePreference(Request $request, TicketJourney $journey, ReturnJourneyAllocator $allocator)
    {
        $this->assertAccess($request, $journey);

        $validated = $request->validate([
            'desired_travel_date' => ['nullable', 'date'],
            'desired_departure_time' => ['nullable', 'date_format:H:i'],
            'departure_schedule_id' => ['nullable', 'uuid', 'exists:departure_schedules,id'],
        ]);

        try {
            $journey = app(ChangeReturnPreference::class)->change(
                $journey,
                $request->user(),
                [
                    'desired_travel_date' => $validated['desired_travel_date'] ?? null,
                    'desired_departure_time' => $validated['desired_departure_time'] ?? null,
                    'departure_schedule_id' => $validated['departure_schedule_id'] ?? null,
                ],
            );
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        }

        return response()->json(['message' => 'Préférence du retour mise à jour.', 'journey' => $journey->fresh()]);
    }

    private function assertAccess(Request $request, TicketJourney $journey): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        $stationIds = $request->user()->getActiveStationIds();
        abort_unless(in_array($journey->from_station_id, $stationIds, true), 403, 'Vous n’êtes pas autorisé à gérer ce retour.');
    }
}
