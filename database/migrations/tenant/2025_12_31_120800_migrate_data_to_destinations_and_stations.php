<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migrate Station Cities to Destinations
        $stations = DB::table('stations')->get();
        foreach ($stations as $station) {
            if ($station->city) {
                // Find or create destination
                $destination = DB::table('destinations')->where('name', $station->city)->first();
                if (! $destination) {
                    $destinationId = (string) Str::uuid();
                    DB::table('destinations')->insert([
                        'id' => $destinationId,
                        'name' => $station->city,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $destinationId = $destination->id;
                }

                // Link station to destination
                DB::table('stations')->where('id', $station->id)->update(['destination_id' => $destinationId]);
            }
        }

        // 2. Migrate Stops to Stations
        // Each stop becomes a Station.
        // We need to link these new Stations to the correct Destination.
        // If the Stop belongs to a Station, use that Station's Destination.

        $stops = DB::table('stops')->get();
        foreach ($stops as $stop) {
            $parentStation = DB::table('stations')->where('id', $stop->station_id)->first();
            $destinationId = null;
            $city = null;

            if ($parentStation && $parentStation->destination_id) {
                $destinationId = $parentStation->destination_id;
                // Ideally we get the destination name, but destination_id is enough for the FK
            } else {
                // Fallback: Create a "Unknown" destination? Or leave null?
                // Let's leave null for now or try to deduce.
            }

            // Create new Station representing this Stop
            // Reuse the Stop ID as the Station ID?
            // If we reuse the ID, then `route_stop_orders` (which has `stop_id`) will naturally map to `station_id` (if we rename the column).
            // BUT: Station IDs and Stop IDs must not collide if they are in the same table? No, they are in different tables.
            // If we move Stops to Stations table, we can keep the UUID.

            // Check if station with this ID already exists (unlikely given UUIDs, but safe check)
            $exists = DB::table('stations')->where('id', $stop->id)->exists();

            if (! $exists) {
                DB::table('stations')->insert([
                    'id' => $stop->id, // KEEP SAME ID so references in route_stop_orders remain valid!
                    'name' => $stop->name,
                    'destination_id' => $destinationId,
                    'active' => true,
                    'created_at' => $stop->created_at,
                    'updated_at' => $stop->updated_at,
                    // 'seat_count' removed as it doesn't exist on stations
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Difficult to reverse data split cleanly without logic,
        // but generally we would ideally delete the created Stations that were Stops.
        // For now, we leave down empty or do minimal cleanup.
    }
};
