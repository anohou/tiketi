<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trips\CrewTripAccessPolicy;
use App\Http\Controllers\Controller;
use App\Models\CrewStatusReport;
use App\Models\Trip;
use Illuminate\Http\Request;

class CrewStatusController extends Controller
{
    public function index(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $reports = CrewStatusReport::with('crewMember')
            ->where('trip_id', $trip->id)
            ->orderByDesc('reported_at')
            ->get()
            ->map(fn (CrewStatusReport $report) => $this->reportPayload($report));

        return response()->json([
            'reports' => $reports,
        ]);
    }

    public function store(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:normal,traffic_jam,accident,mechanical_trouble'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $crewMember = $request->user();

        $report = CrewStatusReport::create([
            'trip_id' => $trip->id,
            'crew_member_id' => $crewMember->id,
            'status' => $validated['status'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'note' => $validated['note'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'reported_at' => now(),
        ]);

        return response()->json([
            'message' => 'Statut enregistré.',
            'report' => $this->reportPayload($report->load('crewMember')),
        ], 201);
    }

    public function latestPosition(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $report = CrewStatusReport::with('crewMember')
            ->where('trip_id', $trip->id)
            ->latest('reported_at')
            ->first();

        return response()->json([
            'report' => $report ? $this->reportPayload($report) : null,
        ]);
    }

    private function reportPayload(CrewStatusReport $report): array
    {
        return [
            'id' => $report->id,
            'trip_id' => $report->trip_id,
            'crew_member' => $report->crewMember ? [
                'id' => $report->crewMember->id,
                'name' => $report->crewMember->name,
            ] : null,
            'status' => $report->status,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            'note' => $report->note,
            'metadata' => $report->metadata,
            'reported_at' => $report->reported_at?->toIso8601String(),
        ];
    }

    private function assertCrewVehicleAccess(Request $request, Trip $trip): void
    {
        abort_unless(
            app(CrewTripAccessPolicy::class)->canAccess($request->user(), $trip),
            403,
            'Ce voyage ne correspond pas à vos affectations.',
        );
    }
}
