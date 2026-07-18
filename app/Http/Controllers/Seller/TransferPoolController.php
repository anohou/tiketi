<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\TicketConnection;
use App\Models\Trip;
use App\Services\AutomaticConnectionAllocator;
use App\Services\OpenConnectionService;
use App\Services\TripTimingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TransferPoolController extends Controller
{
    public function index(Request $request)
    {
        $stationIds = $request->user()->getActiveStationIds();
        $query = TicketConnection::with([
            'ticket.fromStation', 'ticket.trip', 'transferStation', 'destinationStation', 'route', 'trip.vehicle',
        ])->whereIn('status', ['pending', 'ready', 'assigned', 'boarded', 'missed']);

        if (! $request->user()->isAdmin()) {
            $query->whereIn('transfer_station_id', $stationIds);
        }

        if ($request->filled('station_id')) {
            abort_unless($request->user()->isAdmin() || in_array($request->string('station_id')->toString(), $stationIds, true), 403);
            $query->where('transfer_station_id', $request->string('station_id')->toString());
        }

        $connections = $query->orderByRaw("CASE status WHEN 'ready' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->oldest()->get();

        if ($request->expectsJson()) {
            return response()->json(['connections' => $connections]);
        }

        $tripQuery = Trip::with(['route.routeStopOrders.station', 'originStation', 'destinationStation', 'vehicle'])
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('departure_at', '>=', now()->subHour());
        if (! $request->user()->isAdmin()) {
            $tripQuery->whereIn('route_id', $request->user()->accessibleRoutesQuery()->pluck('id'))
                ->whereIn('origin_station_id', $stationIds);
        }

        return Inertia::render('Seller/TransferPool', [
            'connections' => $connections,
            'trips' => $tripQuery->orderBy('departure_at')->get(),
            'stations' => Station::whereIn('id', $connections->pluck('transfer_station_id')->unique())
                ->orderBy('name')->get(['id', 'name', 'city']),
        ]);
    }

    public function markReady(Request $request, TicketConnection $connection)
    {
        $this->assertStationAccess($request, $connection);
        $connection = DB::transaction(function () use ($connection) {
            $locked = TicketConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['connection' => 'Cette correspondance ne peut pas être marquée présente.']);
            }

            $locked->update(['status' => 'ready', 'ready_at' => now()]);

            return $locked->fresh();
        });
        app(AutomaticConnectionAllocator::class)->allocateAllUpcoming($request->user());

        return response()->json(['message' => 'Passager présent au point de correspondance.', 'connection' => $connection->fresh()]);
    }

    public function assign(Request $request, Trip $trip, OpenConnectionService $service)
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'uuid', 'exists:ticket_connections,id'],
            'seat_number' => ['required', 'integer', 'min:1'],
        ]);
        $connection = TicketConnection::findOrFail($validated['connection_id']);
        $this->assertStationAccess($request, $connection);

        $assigned = $service->assign($connection, $trip, (int) $validated['seat_number'], $request->user(), 'manual', true);

        return response()->json(['message' => 'Correspondance affectée avec le siège choisi, sans réédition du ticket.', 'connection' => $assigned->fresh()]);
    }

    public function depart(Request $request, Trip $trip, TripTimingService $timing)
    {
        abort_unless($request->user()->isAdmin() || in_array($trip->origin_station_id, $request->user()->getActiveStationIds(), true), 403);
        abort_if(in_array($trip->status, ['departed', 'arrived', 'cancelled'], true), 422, 'Ce voyage ne peut plus être marqué comme parti.');

        $validated = $request->validate(['departed_at' => ['nullable', 'date']]);
        $updated = $timing->markDeparted($trip, isset($validated['departed_at']) ? now()->parse($validated['departed_at']) : now());

        return response()->json(['message' => 'Départ enregistré.', 'trip' => $updated]);
    }

    public function autoAllocate(Request $request, Trip $trip, AutomaticConnectionAllocator $allocator)
    {
        abort_unless($request->user()->isAdmin() || in_array($trip->origin_station_id, $request->user()->getActiveStationIds(), true), 403);

        $assigned = $allocator->allocateForTrip($trip, $request->user(), true);

        return response()->json([
            'message' => $assigned->count().' passager(s) affecté(s) automatiquement.',
            'assigned_count' => $assigned->count(),
        ]);
    }

    private function assertStationAccess(Request $request, TicketConnection $connection): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless(in_array($connection->transfer_station_id, $request->user()->getActiveStationIds(), true), 403);
    }
}
