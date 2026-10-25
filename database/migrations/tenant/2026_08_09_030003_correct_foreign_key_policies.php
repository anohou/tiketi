<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction des politiques de clés étrangères (point 9).
 *
 * La migration 030001 appliquait nullOnDelete() UNIFORMÉMENT — inadapté.
 * Cette migration additive dépose les FK 030001 puis recrée chaque contrainte
 * avec une politique explicite :
 *
 * - CASCADE  : données strictement dépendantes de leur parent
 *              (droits d'un billet, historique d'affectation, exceptions,
 *              publications outbox).
 * - RESTRICT : références structurantes ou historiques NON NULL (gares,
 *              trajets, types de véhicule, voyages) — refus de suppression.
 * - SET NULL : colonnes réellement nullable dont la perte de référence est
 *              fonctionnellement acceptable (auteurs facultatifs, véhicule
 *              d'un droit non encore affecté, voyages précédent/nouveau).
 *
 * Chaque relation possède UNE SEULE politique finale. down() parcourt les
 * TROIS groupes explicitement (aucune fusion superficielle par table).
 *
 * SQLite n'applique pas les FK par défaut (les tests ne sont pas affectés) ;
 * PostgreSQL les applique — la validation finale se fait donc sur PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. RESTRICT (colonnes NOT NULL structurantes).
        foreach ($this->restrictDefinitions() as $table => $columns) {
            foreach ($columns as $column => [$references, $oldName]) {
                $newName = "fk3_{$table}_{$column}";
                $this->assertColumn($table, $column, $references, $newName);
                $this->dropForeign($table, $oldName);
                $this->dropForeign($table, $newName);

                Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $newName)
                    ->references('id')->on($references)->restrictOnDelete()->restrictOnUpdate());
            }
        }

        // 2. CASCADE (dépendances strictes).
        foreach ($this->cascadeDefinitions() as $table => $columns) {
            foreach ($columns as $column => [$references, $oldName]) {
                $newName = "fk3_{$table}_{$column}";
                $this->assertColumn($table, $column, $references, $newName);
                $this->dropForeign($table, $oldName);
                $this->dropForeign($table, $newName);

                Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $newName)
                    ->references('id')->on($references)->cascadeOnDelete()->cascadeOnUpdate());
            }
        }

        // 3. SET NULL (colonnes nullable).
        foreach ($this->setNullDefinitions() as $table => $columns) {
            foreach ($columns as $column => [$references, $oldName]) {
                $newName = "fk3_{$table}_{$column}";
                $this->assertColumn($table, $column, $references, $newName);
                $this->dropForeign($table, $oldName);
                $this->dropForeign($table, $newName);

                Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $newName)
                    ->references('id')->on($references)->nullOnDelete()->cascadeOnUpdate());
            }
        }

        // 4. trip_seat_occupancies.ticket_journey_id : FK + index + unicité
        //    (un droit = une occupation par voyage). Les lignes legacy avec
        //    ticket_journey_id NULL sont préservées (les NULL ne se
        //    dupliquent pas sous une contrainte unique PostgreSQL).
        $this->assertColumn('trip_seat_occupancies', 'ticket_journey_id', 'ticket_journeys', 'fk3_occupancy_journey');
        Schema::table('trip_seat_occupancies', function (Blueprint $t) {
            $t->foreign('ticket_journey_id', 'fk3_occupancy_journey')
                ->references('id')->on('ticket_journeys')->cascadeOnDelete()->cascadeOnUpdate();
            $t->index('ticket_journey_id', 'idx_occupancy_journey');
            $t->unique(['trip_id', 'ticket_journey_id'], 'uniq_occupancy_journey_per_trip');
        });
    }

    public function down(): void
    {
        // Ordre : unicité → index → FK d'occupation, puis toutes les FK fk3.
        if (Schema::hasTable('trip_seat_occupancies')) {
            $this->safePgsqlStatement('ALTER TABLE "trip_seat_occupancies" DROP CONSTRAINT IF EXISTS "uniq_occupancy_journey_per_trip"');
            $this->safePgsqlStatement('DROP INDEX IF EXISTS "idx_occupancy_journey"');
            $this->safePgsqlStatement('ALTER TABLE "trip_seat_occupancies" DROP CONSTRAINT IF EXISTS "fk3_occupancy_journey"');
        }

        $groups = [
            $this->restrictDefinitions(),
            $this->cascadeDefinitions(),
            $this->setNullDefinitions(),
        ];

        foreach ($groups as $definitions) {
            foreach ($definitions as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                foreach (array_keys($columns) as $column) {
                    $name = "fk3_{$table}_{$column}";
                    if (DB::getDriverName() === 'pgsql') {
                        $this->safePgsqlStatement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$name}\"");
                    } else {
                        try {
                            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
                        } catch (Throwable $e) {
                            // La FK peut ne pas exister (rollback partiel).
                        }
                    }
                }
            }
        }

        // Restaurer exactement l'état laissé par 030001 lorsqu'on annule
        // uniquement cette migration. Sans cela, un rollback d'un seul cran
        // supprimait silencieusement toute intégrité référentielle.
        foreach ($groups as $definitions) {
            foreach ($definitions as $table => $columns) {
                foreach ($columns as $column => [$references, $oldName]) {
                    if (! Schema::hasTable($table) || ! Schema::hasTable($references)) {
                        continue;
                    }

                    Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $oldName)
                        ->references('id')->on($references)->nullOnDelete());
                }
            }
        }
    }

    /** Colonnes NOT NULL structurantes → RESTRICT (une seule politique finale). */
    private function restrictDefinitions(): array
    {
        return [
            'departure_schedules' => [
                'station_id' => ['stations', 'fk_schedule_station'],
                'route_id' => ['routes', 'fk_schedule_route'],
                'origin_station_id' => ['stations', 'fk_schedule_origin'],
                'destination_station_id' => ['stations', 'fk_schedule_destination'],
                'default_vehicle_type_id' => ['vehicle_types', 'fk_schedule_vehicle_type'],
            ],
            'round_trip_fares' => [
                'from_station_id' => ['stations', 'fk_rtf_from'],
                'to_station_id' => ['stations', 'fk_rtf_to'],
            ],
            'ticket_journeys' => [
                'from_station_id' => ['stations', 'fk2_journey_from'],
                'to_station_id' => ['stations', 'fk2_journey_to'],
                'trip_id' => ['trips', 'fk_journey_trip'],
                'departure_schedule_id' => ['departure_schedules', 'fk_journey_schedule'],
            ],
            'trips' => [
                'departure_schedule_id' => ['departure_schedules', 'fk_trip_schedule'],
            ],
        ];
    }

    /** Dépendances strictes → CASCADE. */
    private function cascadeDefinitions(): array
    {
        return [
            'ticket_journeys' => [
                'ticket_id' => ['tickets', 'fk_journey_ticket'],
            ],
            'ticket_journey_assignments' => [
                'ticket_journey_id' => ['ticket_journeys', 'fk_assign_journey'],
            ],
            'departure_schedule_exceptions' => [
                'departure_schedule_id' => ['departure_schedules', 'fk_exception_schedule'],
            ],
            'okohi_ticket_outbox' => [
                'ticket_id' => ['tickets', 'fk_outbox_ticket'],
            ],
        ];
    }

    /** Colonnes nullable dont la perte de référence est acceptable → SET NULL. */
    private function setNullDefinitions(): array
    {
        return [
            'ticket_journeys' => [
                'vehicle_id' => ['vehicles', 'fk2_journey_vehicle'],
                'assigned_by' => ['users', 'fk2_journey_assigned_by'],
                'boarded_by' => ['users', 'fk2_journey_boarded_by'],
            ],
            'ticket_journey_assignments' => [
                'previous_trip_id' => ['trips', 'fk2_assign_previous_trip'],
                'new_trip_id' => ['trips', 'fk2_assign_new_trip'],
                'assigned_by' => ['users', 'fk2_assign_assigned_by'],
            ],
            'departure_schedules' => [
                'created_by' => ['users', 'fk2_schedule_created_by'],
            ],
            'departure_schedule_exceptions' => [
                'created_by' => ['users', 'fk2_exception_created_by'],
            ],
            'trips' => [
                'opened_by' => ['users', 'fk2_trip_opened_by'],
            ],
        ];
    }

    private function dropForeign(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // PostgreSQL : `DROP CONSTRAINT IF EXISTS` ne marque PAS la
        // transaction comme aborted (une exception PHP catchée, elle, la
        // marque). C'est la seule approche sûre pour une migration idempotente.
        if (DB::getDriverName() === 'pgsql') {
            $this->safePgsqlStatement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$name}\"");

            return;
        }

        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
        } catch (Throwable $e) {
            // La FK n'existe pas (migration 030001 non appliquée sur ce schéma).
        }
    }

    private function safePgsqlStatement(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Throwable $e) {
            // DROP ... IF EXISTS est idempotent ; une erreur ici indique un
            // état PostgreSQL anormal qu'il faut remonter, sauf cas connus.
            if (! $this->isIgnorablePgsqlError($e)) {
                throw $e;
            }
        }
    }

    private function isIgnorablePgsqlError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'does not exist');
    }

    private function assertColumn(string $table, string $column, string $references, string $name): void
    {
        // Pretend mode does not execute earlier pending migrations, so schema
        // introspection cannot see the tables and columns their SQL creates.
        // The real migration path below remains strict.
        if (DB::connection()->pretending()) {
            return;
        }

        if (! Schema::hasTable($table) || ! Schema::hasTable($references)) {
            throw new RuntimeException(
                "030003 : table manquante pour la clé étrangère {$name} ({$table}.{$column} → {$references})."
            );
        }

        if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($references, 'id')) {
            throw new RuntimeException(
                "030003 : colonne manquante pour la clé étrangère {$name} ({$table}.{$column} → {$references}.id)."
            );
        }
    }
};
