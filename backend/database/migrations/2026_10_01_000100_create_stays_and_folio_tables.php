<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Priced extras staff can add to a guest bill (spec §21).
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('category', 40);
            $table->string('description', 500)->nullable();
            $table->decimal('price', 14, 2);
            $table->boolean('charges_vat')->default(true);
            $table->boolean('charges_service_charge')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'name']);
        });

        // One stay per room occupancy period (a room move closes one stay and opens another).
        Schema::create('stays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_room_id')->constrained('reservation_rooms')->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('guest_id')->constrained()->restrictOnDelete();
            $table->string('status', 10)->index();          // OPEN | CLOSED
            $table->string('close_reason', 20)->nullable(); // CHECKED_OUT | MOVED
            $table->timestamp('checked_in_at');
            $table->foreignUuid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            // ID seen at the desk when no document is on file (P15). Number encrypted.
            $table->string('id_type', 30)->nullable();
            $table->text('id_number')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'status']);
        });

        // Guest bill items beyond the booked accommodation (spec §19-21).
        Schema::create('reservation_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20);   // SERVICE | EXTRA_NIGHT | LATE_CHECKOUT | ADJUSTMENT | OTHER
            $table->string('description', 255);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('service_charge', 14, 2)->default(0);
            $table->decimal('vat', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('status', 10)->default('ACTIVE')->index(); // ACTIVE | VOIDED
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
        });

        // The final bill issued at check-out (spec §19 "Final Receipt").
        Schema::create('folio_statements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();   // FOL-2026-00001
            $table->foreignUuid('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->timestamp('issued_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Active reservation_charges total; grand total = total + charges_total.
            $table->decimal('charges_total', 14, 2)->default(0)->after('total');
            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->foreignUuid('checked_out_by')->nullable()->after('checked_out_at')->constrained('users')->nullOnDelete();
            // P18 override: left with money owed (e.g. company account).
            $table->decimal('balance_at_checkout', 14, 2)->nullable()->after('checked_out_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_check CHECK (price >= 0)');
            DB::statement("ALTER TABLE stays ADD CONSTRAINT stays_status_check CHECK (status IN ('OPEN','CLOSED'))");
            // A room holds at most one open stay.
            DB::statement("CREATE UNIQUE INDEX stays_one_open_per_room ON stays (room_id) WHERE status = 'OPEN'");
            DB::statement("ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_category_check CHECK (category IN ('SERVICE','EXTRA_NIGHT','LATE_CHECKOUT','ADJUSTMENT','OTHER'))");
            DB::statement("ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_status_check CHECK (status IN ('ACTIVE','VOIDED'))");
            // Only adjustments (discounts / reductions) may be negative.
            DB::statement("ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_sign_check CHECK ((category = 'ADJUSTMENT' AND total <= 0) OR (category <> 'ADJUSTMENT' AND total >= 0 AND unit_price >= 0))");
            DB::statement('ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_total_check CHECK (total = subtotal + service_charge + vat AND quantity > 0)');
        }

        // P18: new permission for existing installations (fresh installs get it from the seeders).
        $this->grantOverridePermission();
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_out_by');
            $table->dropColumn(['charges_total', 'checked_in_at', 'checked_out_at', 'balance_at_checkout']);
        });
        Schema::dropIfExists('folio_statements');
        Schema::dropIfExists('reservation_charges');
        Schema::dropIfExists('stays');
        Schema::dropIfExists('services');
    }

    private function grantOverridePermission(): void
    {
        $roleClass = config('permission.models.role');
        $permissionClass = config('permission.models.permission');
        $manager = $roleClass::query()->where('name', 'Hotel Manager')->where('guard_name', 'web')->first();

        if (! $manager) {
            return;
        }

        $permission = $permissionClass::findOrCreate('checkouts.override_balance', 'web');
        $manager->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
