<?php

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait InteractsWithTenantTicketing
{
    protected function ensureTenantTicketingTablesExist(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('telephone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('seller')->index();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        if (! Schema::hasTable('stations')) {
            Schema::create('stations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->string('city')->nullable();
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_station_assignments')) {
            Schema::create('user_station_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('station_id')->index();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_route_assignments')) {
            Schema::create('user_route_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('route_id')->index();
                $table->uuid('station_id')->nullable()->index();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'route_id', 'station_id']);
            });
        }

        if (! Schema::hasTable('routes')) {
            Schema::create('routes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->uuid('origin_station_id')->nullable();
                $table->uuid('destination_station_id')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedInteger('estimated_duration_minutes')->nullable();
                $table->boolean('automatic_connection_allocation')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('route_stop_orders')) {
            Schema::create('route_stop_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('route_id')->index();
                $table->uuid('station_id')->index();
                $table->unsignedInteger('stop_index');
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('route_fares')) {
            Schema::create('route_fares', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('from_station_id')->index();
                $table->uuid('to_station_id')->index();
                $table->unsignedInteger('amount');
                $table->boolean('is_bidirectional')->default(true);
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('destinations')) {
            Schema::create('destinations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('region')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_types')) {
            Schema::create('vehicle_types', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->json('seat_map')->nullable();
                $table->unsignedInteger('seat_count');
                $table->string('seat_configuration')->nullable();
                $table->unsignedInteger('last_row_seats')->nullable();
                $table->json('door_positions')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('identifier')->unique();
                $table->string('maker')->nullable();
                $table->uuid('vehicle_type_id')->index();
                $table->unsignedInteger('seat_count');
                $table->date('insurance_expiry_date')->nullable()->after('seat_count');
                $table->boolean('active')->default(true);
                $table->string('inactive_reason')->nullable();
                $table->boolean('is_placeholder')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('station_vehicle_assignments')) {
            Schema::create('station_vehicle_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('station_id')->index();
                $table->uuid('vehicle_id')->index();
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trips')) {
            Schema::create('trips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code')->nullable();
                $table->uuid('route_id')->index();
                $table->uuid('vehicle_id')->nullable();
                $table->uuid('origin_station_id')->index();
                $table->uuid('destination_station_id')->index();
                $table->dateTime('departure_at');
                $table->string('status')->default('scheduled');
                $table->string('sales_control')->default('open');
                $table->string('booking_type')->default('seat_assignment');
                $table->unsignedInteger('total_seats')->default(50);
                $table->boolean('allows_open_connections')->default(false);
                $table->boolean('automatic_connection_allocation')->default(false);
                $table->boolean('is_replicable')->default(false);
                $table->json('settings')->nullable();
                $table->uuid('departure_schedule_id')->nullable()->index();
                $table->date('service_date')->nullable()->index();
                $table->timestamp('opened_at')->nullable();
                $table->uuid('opened_by')->nullable();
                $table->boolean('sales_ready')->default(false);
                $table->boolean('operational_ready')->default(false);
                $table->unsignedInteger('planned_capacity_snapshot')->nullable();
                $table->string('vehicle_assignment_policy')->default('require_real_vehicle');
                $table->unsignedInteger('seat_assignment_version')->default(0);
                $table->timestamp('vehicle_assignment_deferred_at')->nullable();
                $table->uuid('vehicle_assignment_deferred_by')->nullable();
                $table->string('vehicle_assignment_deferred_reason')->nullable();
                $table->dateTime('actual_departed_at')->nullable();
                $table->dateTime('planned_arrival_at')->nullable();
                $table->dateTime('estimated_arrival_at')->nullable();
                $table->timestamps();
                $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_service_date');
            });
        }

        if (! Schema::hasTable('trip_seat_occupancies')) {
            Schema::create('trip_seat_occupancies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->unsignedInteger('seat_number');
                $table->uuid('ticket_id')->nullable()->index();
                $table->uuid('ticket_journey_id')->nullable()->index();
                $table->uuid('from_station_id')->nullable()->index();
                $table->uuid('to_station_id')->nullable()->index();
                $table->uuid('okohi_reward_request_id')->nullable()->index();
                $table->timestamp('expires_at')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->unique(['trip_id', 'seat_number'], 'uniq_trip_seat');
            });
        }

        if (! Schema::hasTable('trip_status_logs')) {
            Schema::create('trip_status_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->string('status');
                $table->uuid('changed_by_user_id')->nullable();
                $table->uuid('changed_by_crew_member_id')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ticket_number')->unique();
                $table->uuid('trip_id')->index();
                $table->uuid('vehicle_id')->nullable();
                $table->uuid('from_station_id')->index();
                $table->uuid('to_station_id')->index();
                $table->uuid('final_destination_station_id')->nullable();
                $table->uuid('transfer_station_id')->nullable();
                $table->unsignedInteger('seat_number')->nullable();
                $table->string('passenger_name')->nullable();
                $table->string('passenger_phone')->nullable();
                $table->unsignedInteger('price');
                $table->uuid('seller_id')->nullable();
                $table->uuid('crew_member_id')->nullable();
                $table->uuid('station_id')->nullable();
                $table->string('status')->default('issued');
                $table->string('boarding_group')->nullable();
                $table->string('qr_code')->nullable();
                $table->json('qr_payload')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->uuid('cancelled_by')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->string('payment_method')->default('cash');
                $table->unsignedInteger('gross_amount')->nullable();
                $table->unsignedInteger('discount_amount')->default(0);
                $table->unsignedInteger('amount_collected')->nullable();
                $table->string('okohi_customer_number')->nullable();
                $table->string('okohi_reward_id')->nullable();
                $table->string('okohi_transaction_id')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_connections')) {
            Schema::create('ticket_connections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->unique();
                $table->uuid('trip_id')->nullable()->index();
                $table->uuid('transfer_station_id')->index();
                $table->uuid('destination_station_id')->index();
                $table->uuid('route_id')->nullable()->index();
                $table->unsignedInteger('seat_number')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('planned_ready_at')->nullable();
                $table->timestamp('estimated_ready_at')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_settings')) {
            Schema::create('ticket_settings', function (Blueprint $table) {
                $table->id();
                $table->string('company_name')->default('TEST TRANSPORT');
                $table->json('phone_numbers')->nullable();
                $table->string('cc_label')->nullable();
                $table->json('footer_messages')->nullable();
                $table->text('baggage_policy_message')->nullable();
                $table->boolean('print_qr_code')->default(true);
                $table->string('okohi_integration_url')->nullable();
                $table->string('okohi_integration_key')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_settings')) {
            Schema::create('operational_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('automatic_connection_allocation')->default(false);
                $table->unsignedInteger('connection_transfer_buffer_minutes')->default(15);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        // Remise globale aller-retour (montant fixe en FCFA), stockée dans les
        // réglages d'exploitation.
        $settings = \App\Models\OperationalSetting::current();
        if ($settings->roundTripDiscountAmount() === 0) {
            $settings->setRoundTripDiscountAmount(0);
        }

        if (! Schema::hasTable('crew_members')) {
            Schema::create('crew_members', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->enum('role', ['driver', 'assistant'])->index();
                $table->string('license_number')->nullable();
                $table->date('license_expiry_date')->nullable();
                $table->string('pin')->nullable();
                $table->string('push_token')->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_crew_assignments')) {
            Schema::create('vehicle_crew_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('vehicle_id')->index();
                $table->uuid('crew_member_id')->index();
                $table->enum('role', ['driver', 'assistant'])->index();
                $table->dateTime('assigned_from');
                $table->dateTime('assigned_to')->nullable();
                $table->json('settings')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('departure_schedules')) {
            Schema::create('departure_schedules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('station_id')->index();
                $table->uuid('route_id')->index();
                $table->uuid('origin_station_id')->index();
                $table->uuid('destination_station_id')->index();
                $table->time('departure_time');
                $table->json('days_of_week');
                $table->date('valid_from');
                $table->date('valid_until')->nullable();
                $table->string('timezone')->default('UTC');
                $table->unsignedInteger('planned_capacity')->nullable();
                $table->unsignedInteger('confirmed_return_quota')->nullable();
                $table->uuid('default_vehicle_type_id')->index();
                $table->string('vehicle_assignment_policy')->nullable();
                $table->string('booking_type')->default('seat_assignment');
                $table->string('sales_control')->default('open');
                $table->boolean('allows_open_connections')->default(false);
                $table->boolean('automatic_connection_allocation')->default(false);
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('departure_schedule_exceptions')) {
            Schema::create('departure_schedule_exceptions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('departure_schedule_id')->index();
                $table->date('service_date');
                $table->string('type');
                $table->time('replacement_time')->nullable();
                $table->unsignedInteger('replacement_capacity')->nullable();
                $table->string('reason')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_exception_date');
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_compensations')) {
            Schema::create('ticket_compensations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('reference')->unique();
                $table->uuid('ticket_id')->index();
                $table->uuid('ticket_connection_id')->nullable();
                $table->string('incident_type');
                $table->string('compensation_type');
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('status')->default('pending_approval');
                $table->text('reason');
                $table->uuid('requested_by');
                $table->uuid('approved_by')->nullable();
                $table->uuid('executed_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->uuid('replacement_trip_id')->nullable();
                $table->unsignedInteger('replacement_seat_number')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('okohi_ticket_outbox')) {
            Schema::create('okohi_ticket_outbox', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->index();
                $table->string('external_ticket_id')->index();
                $table->string('status')->default('pending')->index();
                $table->string('operation')->default('create');
                $table->unsignedInteger('version')->default(1);
                $table->string('idempotency_key')->unique();
                $table->json('payload')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->string('last_error_code', 80)->nullable()->index();
                $table->json('last_response')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_journeys')) {
            Schema::create('ticket_journeys', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->index();
                $table->string('direction')->index();
                $table->unique(['ticket_id', 'direction'], 'uniq_journey_direction_per_ticket');
                $table->uuid('from_station_id')->index();
                $table->uuid('to_station_id')->index();
                $table->string('selection_mode')->default('fixed_trip');
                $table->uuid('departure_schedule_id')->nullable()->index();
                $table->date('desired_travel_date')->nullable()->index();
                $table->time('desired_departure_time')->nullable();
                $table->uuid('trip_id')->nullable()->index();
                $table->uuid('vehicle_id')->nullable()->index();
                $table->unsignedInteger('seat_number')->nullable();
                $table->string('seat_assignment_status')->default('unassigned');
                $table->string('status')->default('pending')->index();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->uuid('assigned_by')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_journey_assignments')) {
            Schema::create('ticket_journey_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_journey_id')->index();
                $table->uuid('previous_trip_id')->nullable();
                $table->uuid('new_trip_id')->nullable();
                $table->unsignedInteger('previous_seat_number')->nullable();
                $table->unsignedInteger('new_seat_number')->nullable();
                $table->string('reason')->nullable();
                $table->string('mode')->default('manual');
                $table->uuid('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crew_offline_actions')) {
            Schema::create('crew_offline_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('crew_member_id')->index();
                $table->uuid('trip_id')->index();
                $table->string('type', 20);
                $table->string('status', 20)->default('confirmed')->after('type')->index();
                $table->char('payload_hash', 64)->nullable()->after('status')->index();
                $table->json('request_payload')->nullable()->after('payload_hash');
                $table->json('result');
                $table->unsignedSmallInteger('attempt_count')->default(1)->after('result');
                $table->string('error_code', 80)->nullable()->after('attempt_count')->index();
                $table->timestamp('processed_at')->nullable()->after('error_code');
                $table->timestamp('expires_at')->nullable()->after('processed_at')->index();
                $table->timestamps();
            });
        }

        // Colonnes Phase 2 sur tickets (ajoutées aux schémas existants).
        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'public_token')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('journey_type')->default('one_way')->index();
                $table->string('public_token')->nullable()->unique();
                $table->unsignedInteger('normal_total_amount')->nullable();
                $table->unsignedInteger('round_trip_discount_amount')->default(0);
                $table->timestamp('return_valid_until')->nullable();
                $table->string('okohi_delivery_status')->default('not_requested')->index();
            });
        }
    }

    /**
     * Configure la remise globale aller-retour (montant fixe en FCFA).
     */
    protected function setRoundTripDiscount(int $amount): void
    {
        \App\Models\OperationalSetting::current()->setRoundTripDiscountAmount($amount);
    }
}
