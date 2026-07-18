<?php

namespace Database\Seeders;

use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Models\TicketConnection;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class CorrespondenceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $stations = Station::whereIn('code', [
            'ABJ-NORD',
            'YAK-CENTRE',
            'DIV-MAIN',
            'GAG-MAIN',
            'KGO-MAIN',
        ])->get()->keyBy('code');

        $gagnoaRoute = Route::where('name', 'Abidjan ↔ Gagnoa via Yamoussoukro et Divo')->first();
        $korhogoRoute = Route::where('name', 'Abidjan ↔ Korhogo')->first();
        $vehicles = Vehicle::whereIn('identifier', ['MASSA-001', 'MASSA-002', 'MASSA-003', 'BUS-001'])
            ->get()
            ->keyBy('identifier');

        $requiredStations = ['ABJ-NORD', 'YAK-CENTRE', 'DIV-MAIN', 'GAG-MAIN', 'KGO-MAIN'];
        $hasAllStations = collect($requiredStations)->every(fn ($code) => $stations->has($code));

        if (! $hasAllStations || ! $gagnoaRoute || ! $korhogoRoute || ! $vehicles->has('MASSA-001') || ! $vehicles->has('MASSA-002')) {
            $this->command?->warn('Scénarios de correspondance ignorés : lignes, gares ou véhicules incomplets.');

            return;
        }

        $abidjan = $stations['ABJ-NORD'];
        $yamoussoukro = $stations['YAK-CENTRE'];
        $divo = $stations['DIV-MAIN'];
        $gagnoa = $stations['GAG-MAIN'];
        $korhogo = $stations['KGO-MAIN'];

        $settings = OperationalSetting::current();
        $settings->update([
            'automatic_connection_allocation' => false,
            'connection_transfer_buffer_minutes' => 15,
            'settings' => array_merge($settings->settings ?? [], [
                'seller_compensation_enabled' => true,
                'seller_compensation_max_amount' => 5000,
            ]),
        ]);

        // Le voyage reste bien affiché Abidjan → Korhogo. Les clients qui ont
        // acheté Divo/Gagnoa n'occupent leur siège que jusqu'à Yamoussoukro.
        $trunkTrip = Trip::updateOrCreate(['code' => 'DEMO-ABJ-KGO-CORRESPONDANCES'], [
            'route_id' => $korhogoRoute->id,
            'vehicle_id' => ($vehicles['BUS-001'] ?? $vehicles['MASSA-001'])->id,
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $korhogo->id,
            'departure_at' => now()->subMinutes(30),
            'planned_arrival_at' => now()->addHours(9)->addMinutes(30),
            'actual_departed_at' => now()->subMinutes(20),
            'estimated_arrival_at' => now()->addHours(9)->addMinutes(40),
            'status' => 'departed',
            'sales_control' => 'closed',
            'booking_type' => 'seat_assignment',
            'allows_open_connections' => true,
            'automatic_connection_allocation' => false,
        ]);

        // À Yamoussoukro, le client change pour la ligne Divo/Gagnoa.
        $earlyConnectionTrip = Trip::updateOrCreate(['code' => 'DEMO-YAK-DIV-GAG-CONFLIT'], [
            'route_id' => $gagnoaRoute->id,
            'vehicle_id' => $vehicles['MASSA-001']->id,
            'origin_station_id' => $yamoussoukro->id,
            'destination_station_id' => $gagnoa->id,
            'departure_at' => now()->addMinutes(45),
            'planned_arrival_at' => now()->addHours(3),
            'status' => 'boarding',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'automatic_connection_allocation' => false,
        ]);

        $laterConnectionTrip = Trip::updateOrCreate(['code' => 'DEMO-YAK-DIV-GAG-SUIVANT'], [
            'route_id' => $gagnoaRoute->id,
            'vehicle_id' => $vehicles['MASSA-002']->id,
            'origin_station_id' => $yamoussoukro->id,
            'destination_station_id' => $gagnoa->id,
            'departure_at' => now()->addHours(3),
            'planned_arrival_at' => now()->addHours(6),
            'status' => 'scheduled',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'automatic_connection_allocation' => false,
        ]);

        // Futurs trajets actifs pour simulation de vente de correspondance en temps réel
        Trip::updateOrCreate(['code' => 'DEMO-LIVE-ABJ-KGO-TRUNK'], [
            'route_id' => $korhogoRoute->id,
            'vehicle_id' => ($vehicles['BUS-001'] ?? $vehicles['MASSA-001'])->id,
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $korhogo->id,
            'departure_at' => now()->addHours(2),
            'planned_arrival_at' => now()->addHours(12),
            'status' => 'scheduled',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'allows_open_connections' => true,
            'automatic_connection_allocation' => false,
        ]);

        Trip::updateOrCreate(['code' => 'DEMO-LIVE-YAK-DIV-GAG-CONN'], [
            'route_id' => $gagnoaRoute->id,
            'vehicle_id' => $vehicles['MASSA-001']->id,
            'origin_station_id' => $yamoussoukro->id,
            'destination_station_id' => $gagnoa->id,
            'departure_at' => now()->addHours(5),
            'planned_arrival_at' => now()->addHours(8),
            'status' => 'scheduled',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'allows_open_connections' => true,
            'automatic_connection_allocation' => false,
        ]);
        Trip::updateOrCreate([
            'route_id' => $korhogoRoute->id,
            'departure_at' => now()->setTime(8, 0, 0)->toDateTimeString(),
        ], [
            'vehicle_id' => null,
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $korhogo->id,
            'status' => 'scheduled',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'allows_open_connections' => true,
            'automatic_connection_allocation' => false,
            'is_replicable' => true,
        ]);

        Trip::updateOrCreate([
            'route_id' => $gagnoaRoute->id,
            'departure_at' => now()->setTime(14, 0, 0)->toDateTimeString(),
        ], [
            'vehicle_id' => null,
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $gagnoa->id,
            'status' => 'scheduled',
            'sales_control' => 'open',
            'booking_type' => 'seat_assignment',
            'allows_open_connections' => true,
            'automatic_connection_allocation' => false,
            'is_replicable' => true,
        ]);
        $seller = User::where('email', 'guichet.abidjan@transport.ci')->first()
            ?? User::where('role', 'admin')->firstOrFail();

        $scenarios = [
            [
                'number' => 'DEMO-ABJ-DIV-PENDING',
                'name' => 'Awa Attendue',
                'destination' => $divo,
                'price' => 5000,
                'status' => 'pending',
            ],
            [
                'number' => 'DEMO-ABJ-DIV-READY',
                'name' => 'Mariam Présente',
                'destination' => $divo,
                'price' => 5000,
                'status' => 'ready',
            ],
            [
                'number' => 'DEMO-ABJ-GAG-ASSIGNED',
                'name' => 'Jean (Gagnoa)',
                'destination' => $gagnoa,
                'price' => 7500,
                'status' => 'ready',
            ],
            [
                'number' => 'DEMO-ABJ-DIV-CONFLICT',
                'name' => 'Fatou (Divo)',
                'destination' => $divo,
                'price' => 5000,
                'status' => 'ready',
            ],
            [
                'number' => 'DEMO-ABJ-DIV-BOARDED',
                'name' => 'Koffi (Divo)',
                'destination' => $divo,
                'price' => 5000,
                'status' => 'ready',
            ],
            [
                'number' => 'DEMO-ABJ-GAG-COMPENSE',
                'name' => 'Nadia (Gagnoa)',
                'destination' => $gagnoa,
                'price' => 7500,
                'status' => 'ready',
                'compensated' => true,
            ],
        ];

        foreach ($scenarios as $index => $scenario) {
            $connectionTrip = $scenario['connection_trip'] ?? null;
            $connectionSeat = $scenario['connection_seat'] ?? null;
            $conflict = $scenario['conflict'] ?? null;
            $status = $scenario['status'];

            $ticket = Ticket::updateOrCreate(['ticket_number' => $scenario['number']], [
                'trip_id' => $trunkTrip->id,
                'vehicle_id' => $trunkTrip->vehicle_id,
                'seat_number' => $index + 1,
                'from_station_id' => $abidjan->id,
                'to_station_id' => $yamoussoukro->id,
                'final_destination_station_id' => $scenario['destination']->id,
                'transfer_station_id' => $yamoussoukro->id,
                'price' => $scenario['price'],
                'seller_id' => $seller->id,
                'station_id' => $abidjan->id,
                'status' => 'issued',
                'passenger_name' => $scenario['name'],
                'passenger_phone' => '+225070000'.str_pad((string) ($index + 10), 2, '0', STR_PAD_LEFT),
                'qr_code' => 'QR-'.$scenario['number'],
            ]);
            $ticket->load(['fromStation', 'toStation']);
            $ticket->update(['qr_payload' => $ticket->qrPayloadData()]);

            TripSeatOccupancy::updateOrCreate(
                ['trip_id' => $trunkTrip->id, 'ticket_id' => $ticket->id],
                [
                    'seat_number' => $index + 1,
                    'from_station_id' => $abidjan->id,
                    'to_station_id' => $yamoussoukro->id,
                ]
            );

            $connection = TicketConnection::updateOrCreate(['ticket_id' => $ticket->id], [
                'transfer_station_id' => $yamoussoukro->id,
                'destination_station_id' => $scenario['destination']->id,
                'route_id' => $gagnoaRoute->id,
                'trip_id' => $connectionTrip?->id,
                'seat_number' => $connectionSeat,
                'status' => $status,
                'planned_ready_at' => now()->addMinutes(75),
                'estimated_ready_at' => now()->addMinutes(90),
                'ready_at' => in_array($status, ['ready', 'boarded'], true) ? now()->subMinutes(5) : null,
                'assigned_at' => $connectionTrip ? now()->subMinutes(10) : null,
                'assignment_mode' => $connectionTrip ? 'automatic' : null,
                'boarded_at' => $status === 'boarded' ? now() : null,
                'settings' => $conflict ? [
                    'has_conflict' => true,
                    'conflict_reason' => $conflict,
                    'conflict_detected_at' => now()->toIso8601String(),
                ] : null,
            ]);

            if ($connectionTrip) {
                TripSeatOccupancy::updateOrCreate(
                    ['trip_id' => $connectionTrip->id, 'ticket_id' => $ticket->id],
                    [
                        'seat_number' => $connectionSeat,
                        'from_station_id' => $yamoussoukro->id,
                        'to_station_id' => $scenario['destination']->id,
                    ]
                );
            }

            if ($scenario['compensated'] ?? false) {
                TicketCompensation::updateOrCreate(['reference' => 'CMP-DEMO-AVOIR'], [
                    'ticket_id' => $ticket->id,
                    'ticket_connection_id' => $connection->id,
                    'incident_type' => 'missed_connection',
                    'compensation_type' => 'credit',
                    'amount' => 1500,
                    'status' => 'executed',
                    'reason' => 'Avoir de démonstration après attente prolongée à Yamoussoukro.',
                    'requested_by' => $seller->id,
                    'approved_by' => $seller->id,
                    'executed_by' => $seller->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);
            }

            if ($conflict) {
                TicketCompensation::updateOrCreate(['reference' => 'CMP-DEMO-PENDING'], [
                    'ticket_id' => $ticket->id,
                    'ticket_connection_id' => $connection->id,
                    'incident_type' => 'missed_connection',
                    'compensation_type' => 'credit',
                    'amount' => 2500,
                    'status' => 'pending_approval',
                    'reason' => 'Correspondance Yamoussoukro → Divo en conflit horaire.',
                    'requested_by' => $seller->id,
                ]);
            }
        }

        $this->command?->info('✅ Billets Abidjan → Divo/Gagnoa avec changement de ligne à Yamoussoukro créés.');
    }
}
