<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5 — staff records, shift templates, shifts (assignments) and attendance.
 * Spec §30–31, owner rules P23–P28.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_number', 20)->unique();      // EMP-0001
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position', 80)->nullable();
            $table->string('employment_status', 12)->default('ACTIVE')->index(); // ACTIVE | ON_LEAVE | LEFT
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('photo_disk', 30)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');                              // earlier than start = ends next day
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('shift_templates')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 80)->nullable();
            $table->date('date');                                  // hotel calendar day the shift starts
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('SCHEDULED')->index(); // SCHEDULED | CANCELLED
            $table->string('notes', 500)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'status']);
            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->unique()->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->index();                 // CLOCKED_IN | CLOCKED_OUT | ABSENT
            $table->timestamp('clock_in_at')->nullable();
            $table->timestamp('clock_out_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->boolean('auto_closed')->default(false);        // P26: forgot to clock out
            $table->boolean('needs_review')->default(false)->index();
            $table->foreignUuid('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correction_reason', 255)->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_status_check CHECK (employment_status IN ('ACTIVE', 'ON_LEAVE', 'LEFT'))");
            DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('SCHEDULED', 'CANCELLED'))");
            DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_times_check CHECK (ends_at > starts_at)');
            // P24: one person cannot hold two overlapping scheduled shifts.
            DB::statement(<<<'SQL'
                ALTER TABLE shifts ADD CONSTRAINT shifts_no_overlap
                EXCLUDE USING gist (user_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&)
                WHERE (status = 'SCHEDULED')
            SQL);
            DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_status_check CHECK (status IN ('CLOCKED_IN', 'CLOCKED_OUT', 'ABSENT'))");
            DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT attendance_times_check CHECK (clock_out_at IS NULL OR clock_in_at IS NULL OR clock_out_at >= clock_in_at)');
        }

        $this->backfill();
    }

    /** Existing staff accounts get a profile and number; the three standard shifts are created. */
    private function backfill(): void
    {
        $property = DB::table('properties')->orderBy('id')->first();

        if (! $property) {
            return; // fresh install: PropertySeeder creates the property and the templates.
        }

        $now = now();

        foreach ([['Morning', '07:00', '15:00'], ['Afternoon', '15:00', '23:00'], ['Night', '23:00', '07:00']] as [$name, $start, $end]) {
            DB::table('shift_templates')->insertOrIgnore([
                'property_id' => $property->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $staff = DB::table('users')->where('type', 'staff')->whereNull('deleted_at')->orderBy('created_at')->get(['id', 'created_at']);
        $n = 0;

        foreach ($staff as $user) {
            $n++;
            DB::table('staff_profiles')->insert([
                'property_id' => $property->id,
                'user_id' => $user->id,
                'employee_number' => sprintf('EMP-%04d', $n),
                'employment_status' => 'ACTIVE',
                'start_date' => substr((string) $user->created_at, 0, 10) ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($n > 0) {
            DB::table('document_sequences')->insert([
                'prefix' => 'EMP', 'year' => 0, 'last_value' => $n, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('staff_profiles');
        DB::table('document_sequences')->where('prefix', 'EMP')->delete();
    }
};
