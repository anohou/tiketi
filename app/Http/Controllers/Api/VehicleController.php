<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleType;

class VehicleController extends Controller
{
    /**
     * Liste tous les véhicules
     * GET /api/vehicles
     */
    public function index()
    {
        $vehicles = Vehicle::with('vehicleType')->get()->map(function ($vehicle) {
            return [
                'id' => $vehicle->id,
                'identifier' => $vehicle->identifier,
                'maker' => $vehicle->maker,
                'seat_count' => $vehicle->seat_count,
                'vehicle_type' => [
                    'id' => $vehicle->vehicleType->id,
                    'name' => $vehicle->vehicleType->name,
                    'seat_configuration' => $vehicle->vehicleType->seat_configuration,
                    'door_count' => $vehicle->vehicleType->door_count,
                    'door_positions' => $vehicle->vehicleType->door_positions,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $vehicles,
            'message' => 'Véhicules récupérés avec succès',
        ]);
    }

    /**
     * Affiche le plan d'un véhicule avec sa configuration
     * GET /api/vehicles/{id}/plan
     */
    public function plan(string $id)
    {
        $vehicle = Vehicle::with('vehicleType')->findOrFail($id);
        $vehicleType = $vehicle->vehicleType;

        return response()->json([
            'success' => true,
            'data' => [
                'vehicle_id' => $vehicle->id,
                'identifier' => $vehicle->identifier,
                'maker' => $vehicle->maker,
                'seat_count' => $vehicle->seat_count,
                'type' => [
                    'id' => $vehicleType->id,
                    'name' => $vehicleType->name,
                    'seat_configuration' => $vehicleType->seat_configuration,
                    'door_count' => $vehicleType->door_count,
                    'door_positions' => $vehicleType->door_positions,
                    'seat_map' => $vehicleType->seat_map,
                    'svg_template_path' => $vehicleType->svg_template_path,
                ],
            ],
            'message' => 'Plan du véhicule récupéré avec succès',
        ]);
    }

    /**
     * Liste tous les types de véhicules
     * GET /api/vehicle-types
     */
    public function types()
    {
        $types = VehicleType::all()->map(function ($type) {
            return [
                'id' => $type->id,
                'name' => $type->name,
                'seat_count' => $type->seat_count,
                'seat_configuration' => $type->seat_configuration,
                'door_count' => $type->door_count,
                'door_positions' => $type->door_positions,
                'seat_map' => $type->seat_map,
                'svg_template_path' => $type->svg_template_path,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $types,
            'message' => 'Types de véhicules récupérés avec succès',
        ]);
    }
}
