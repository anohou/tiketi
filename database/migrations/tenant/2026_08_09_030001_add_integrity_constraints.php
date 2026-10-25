<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrainte de cardinalité : au maximum un droit outbound et un droit
        // return par billet (index uniques partiels, compatibles SQLite et
        // PostgreSQL).
        Schema::table('ticket_journeys', function (Blueprint $table) {
            $table->unique(['ticket_id', 'direction'], 'uniq_journey_direction_per_ticket');
        });

        // Clés étrangères des nouvelles tables (SQLite n'applique pas les FK
        // par défaut ; PostgreSQL les applique. Ajoutées en fin de cycle pour
        // ne pas casser les jeux de données existants).
        $this->safeForeign('departure_schedules', 'station_id', 'stations', 'id', 'fk_schedule_station');
        $this->safeForeign('departure_schedules', 'route_id', 'routes', 'id', 'fk_schedule_route');
        $this->safeForeign('departure_schedules', 'origin_station_id', 'stations', 'id', 'fk_schedule_origin');
        $this->safeForeign('departure_schedules', 'destination_station_id', 'stations', 'id', 'fk_schedule_destination');
        $this->safeForeign('departure_schedules', 'default_vehicle_type_id', 'vehicle_types', 'id', 'fk_schedule_vehicle_type');

        $this->safeForeign('departure_schedule_exceptions', 'departure_schedule_id', 'departure_schedules', 'id', 'fk_exception_schedule');

        $this->safeForeign('round_trip_fares', 'from_station_id', 'stations', 'id', 'fk_rtf_from');
        $this->safeForeign('round_trip_fares', 'to_station_id', 'stations', 'id', 'fk_rtf_to');

        $this->safeForeign('ticket_journeys', 'ticket_id', 'tickets', 'id', 'fk_journey_ticket');
        $this->safeForeign('ticket_journeys', 'trip_id', 'trips', 'id', 'fk_journey_trip');
        $this->safeForeign('ticket_journeys', 'departure_schedule_id', 'departure_schedules', 'id', 'fk_journey_schedule');

        $this->safeForeign('ticket_journey_assignments', 'ticket_journey_id', 'ticket_journeys', 'id', 'fk_assign_journey');

        $this->safeForeign('okohi_ticket_outbox', 'ticket_id', 'tickets', 'id', 'fk_outbox_ticket');

        $this->safeForeign('trips', 'departure_schedule_id', 'departure_schedules', 'id', 'fk_trip_schedule');
    }

    private function safeForeign(string $table, string $column, string $references, string $referencedColumn, string $name): void
    {
        // `migrate --pretend` evaluates every pending migration against the
        // unchanged database. Tables/columns created by an earlier pending
        // migration therefore cannot be observed here, even though its SQL is
        // correctly ordered before this migration's SQL.
        if (DB::connection()->pretending()) {
            $this->addForeign($table, $column, $references, $referencedColumn, $name);

            return;
        }

        if (! Schema::hasTable($table) || ! Schema::hasTable($references)) {
            throw new RuntimeException(
                "add_integrity_constraints: table manquante pour la clé étrangère {$name} ({$table}.{$column} → {$references}.{$referencedColumn})."
            );
        }

        if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($references, $referencedColumn)) {
            throw new RuntimeException(
                "add_integrity_constraints: colonne manquante pour la clé étrangère {$name} ({$table}.{$column} → {$references}.{$referencedColumn})."
            );
        }

        // Toute autre erreur (type incompatible, données orphelines…) fait
        // ÉCHOUER la migration : jamais de capture silencieuse.
        $this->addForeign($table, $column, $references, $referencedColumn, $name);
    }

    private function addForeign(string $table, string $column, string $references, string $referencedColumn, string $name): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $referencedColumn, $name) {
            $blueprint->foreign($column, $name)
                ->references($referencedColumn)
                ->on($references)
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'departure_schedules' => ['fk_schedule_station', 'fk_schedule_route', 'fk_schedule_origin', 'fk_schedule_destination', 'fk_schedule_vehicle_type'],
            'departure_schedule_exceptions' => ['fk_exception_schedule'],
            'round_trip_fares' => ['fk_rtf_from', 'fk_rtf_to'],
            'ticket_journeys' => ['fk_journey_ticket', 'fk_journey_trip', 'fk_journey_schedule'],
            'ticket_journey_assignments' => ['fk_assign_journey'],
            'okohi_ticket_outbox' => ['fk_outbox_ticket'],
            'trips' => ['fk_trip_schedule'],
        ] as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                // PostgreSQL : DROP CONSTRAINT IF EXISTS est idempotent et ne
                // marque PAS la transaction comme aborted (contrairement à une
                // exception PHP catchée autour de dropForeign()).
                foreach ($names as $name) {
                    try {
                        DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$name}\"");
                    } catch (Throwable $e) {
                        // Constrainte absente ou état inattendu.
                    }
                }

                continue;
            }

            try {
                Schema::table($table, function (Blueprint $blueprint) use ($names) {
                    foreach ($names as $name) {
                        $blueprint->dropForeign($name);
                    }
                });
            } catch (Throwable $e) {
                // Ignoré : la FK peut ne pas exister.
            }
        }

        Schema::table('ticket_journeys', function (Blueprint $table) {
            $table->dropUnique('uniq_journey_direction_per_ticket');
        });
    }
};
