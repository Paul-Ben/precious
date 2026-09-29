<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 30)->unique();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('guest_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->string('status', 20)->index();
            $table->string('payment_status', 20)->default('UNPAID')->index();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->char('currency', 3)->default('NGN');
            // Price snapshot at booking time (spec §10 rule 8).
            $table->decimal('subtotal', 14, 2);
            $table->decimal('service_charge_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('deposit_percent', 5, 2);
            $table->decimal('deposit_amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->jsonb('pricing_snapshot');
            $table->unsignedSmallInteger('free_cancellation_hours');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('refund_eligible')->nullable();
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('lookup_token_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['check_in', 'status']);
            $table->index(['check_out', 'status']);
            $table->index(['status', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_dates_check CHECK (check_out > check_in)');
            DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ('DRAFT','PENDING_PAYMENT','CONFIRMED','PARTIALLY_PAID','CHECK_IN_PENDING','CHECKED_IN','CHECKED_OUT','CANCELLED','EXPIRED','NO_SHOW'))");
            DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_payment_status_check CHECK (payment_status IN ('UNPAID','DEPOSIT_PAID','PARTIALLY_PAID','PAID','REFUNDED'))");
            DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ('WEBSITE','FRONT_DESK','PHONE','WALK_IN'))");
            DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_money_check CHECK (subtotal >= 0 AND total >= 0 AND deposit_amount >= 0 AND amount_paid >= 0)');
        }

        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            // Denormalised dates so the database can enforce "no double booking".
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->decimal('nightly_rate', 14, 2);
            $table->unsignedSmallInteger('nights');
            $table->decimal('subtotal', 14, 2);
            // True while this line holds the room (pending, confirmed, in house).
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['room_id', 'check_in', 'check_out']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservation_rooms ADD CONSTRAINT reservation_rooms_dates_check CHECK (check_out > check_in)');
            // Spec §74: two active bookings can never hold the same room on overlapping nights.
            DB::statement(<<<'SQL'
                ALTER TABLE reservation_rooms
                ADD CONSTRAINT reservation_rooms_no_overlap
                EXCLUDE USING gist (
                    room_id WITH =,
                    daterange(check_in, check_out, '[)') WITH &&
                ) WHERE (is_active)
            SQL);
        }

        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['reservation_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
        Schema::dropIfExists('reservation_rooms');
        Schema::dropIfExists('reservations');
    }
};
