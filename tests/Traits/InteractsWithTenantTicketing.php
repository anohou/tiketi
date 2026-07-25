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
            $table->rememberToken();
            $table->timestamps();
        });

        if (! Schema::hasTable('stations')) {
            Schema::create('stations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->string('city')->nullable();
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
                $table->uuid('vehicle_type_id')->index();
                $table->unsignedInteger('seat_count');
                $table->boolean('active')->default(true);
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
                $table->unsignedInteger('total_seats')->default(50);
                $table->boolean('allows_open_connections')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trip_status_logs')) {
            Schema::create('trip_status_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->string('status');
                $table->uuid('changed_by_user_id')->nullable();
                $table->uuid('changed_by_crew_member_id')->nullable();
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
    }
}
