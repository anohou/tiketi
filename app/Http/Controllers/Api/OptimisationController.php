<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OptimisationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OptimisationController extends Controller
{
    protected $optimisationService;

    public function __construct(OptimisationService $optimisationService)
    {
        $this->optimisationService = $optimisationService;
    }

    /**
     * Obtient les suggestions de sièges pour un voyage
     * POST /api/trips/{tripId}/suggest-seats
     *
     * Body: {
     *   "destination_stop_id": "uuid"
     * }
     */
    public function suggestSeats(Request $request, string $tripId)
    {
        $validator = Validator::make($request->all(), [
            'destination_stop_id' => 'required|exists:stations,id',
            'boarding_station_id' => 'nullable|exists:stations,id',
            'quantity' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Données invalides',
            ], 422);
        }

        try {
            $suggestions = $this->optimisationService->getSuggestedSeats(
                $tripId,
                $request->destination_stop_id,
                (int) ($request->quantity ?? 1),
                $request->boarding_station_id
            );

            $stats = $this->optimisationService->getTripOccupancyStats($tripId);

            return response()->json([
                'success' => true,
                'data' => [
                    'suggestions' => $suggestions,
                    'booking_type' => $stats['booking_type'],
                    'vehicle_type' => $stats['vehicle_type'],
                    'occupancy' => [
                        'total_seats' => $stats['total_seats'],
                        'occupied_seats' => $stats['occupied_seats'],
                        'available_seats' => $stats['available_seats'],
                        'occupancy_rate' => $stats['occupancy_rate'],
                    ],
                ],
                'message' => count($suggestions) > 0
                    ? 'Suggestions de sièges générées avec succès'
                    : 'Aucune suggestion (mode en vrac ou véhicule complet)',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération des suggestions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtient les statistiques d'occupation d'un voyage
     * GET /api/trips/{tripId}/occupancy
     */
    public function occupancy(string $tripId)
    {
        try {
            $stats = $this->optimisationService->getTripOccupancyStats($tripId);

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Statistiques d\'occupation récupérées avec succès',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques: '.$e->getMessage(),
            ], 500);
        }
    }
}
