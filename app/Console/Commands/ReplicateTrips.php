<?php

namespace App\Console\Commands;

use App\Events\TripCreated;
use App\Models\Tenant;
use App\Models\Trip;
use App\Services\TripTimingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReplicateTrips extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trips:replicate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replicate trips marked as replicable for the next day, without assigning a vehicle or crew.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failed = false;

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::count() > 0) {
            $tenants = Tenant::all();

            foreach ($tenants as $tenant) {
                $this->info("Replicating trips for tenant: {$tenant->id}");

                tenancy()->initialize($tenant);

                try {
                    $this->replicateTenantTrips();
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("Failed to replicate trips for tenant {$tenant->id}: ".$e->getMessage());
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $this->info('Running replication in single-tenant/local context.');
            try {
                $this->replicateTenantTrips();
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Failed to replicate trips: '.$e->getMessage());
            }
        }

        if ($failed) {
            $this->error('Trips replication completed with errors.');

            return self::FAILURE;
        }

        $this->info('Trips replication completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Replicate trips for the active tenant context.
     */
    protected function replicateTenantTrips()
    {
        // Replicate replicable trips scheduled for today
        $today = Carbon::today();

        $replicableTrips = Trip::where('is_replicable', true)
            ->whereDate('departure_at', $today)
            ->get();

        $this->info("Found {$replicableTrips->count()} replicable trips for today.");

        $count = 0;
        foreach ($replicableTrips as $trip) {
            $tomorrowDeparture = $trip->departure_at->copy()->addDay();

            // Check if already replicated for tomorrow to avoid duplicates
            $alreadyExists = Trip::where('route_id', $trip->route_id)
                ->where('departure_at', $tomorrowDeparture)
                ->where('origin_station_id', $trip->origin_station_id)
                ->where('destination_station_id', $trip->destination_station_id)
                ->exists();

            if ($alreadyExists) {
                $this->warn("Trip for route {$trip->route_id} at {$tomorrowDeparture} already exists, skipping.");

                continue;
            }

            // Create replicated trip
            $newTrip = Trip::create([
                'route_id' => $trip->route_id,
                'vehicle_id' => null, // created without assigning a vehicle/bus
                'departure_at' => $tomorrowDeparture,
                'status' => 'scheduled',
                'booking_type' => $trip->booking_type,
                'sales_control' => $trip->sales_control,
                'allows_open_connections' => $trip->allows_open_connections,
                'automatic_connection_allocation' => $trip->automatic_connection_allocation,
                'is_replicable' => true,
                'origin_station_id' => $trip->origin_station_id,
                'destination_station_id' => $trip->destination_station_id,
                'settings' => $trip->settings,
            ]);

            // Sync planned timing estimates
            $newTrip = app(TripTimingService::class)->syncPlannedTimes($newTrip);
            try {
                TripCreated::dispatch($newTrip);
            } catch (\Throwable $exception) {
                $this->warn("Trip {$newTrip->id} replicated but its realtime event could not be broadcast.");
            }
            $count++;
        }

        $this->info("Successfully replicated {$count} trips.");
    }
}
