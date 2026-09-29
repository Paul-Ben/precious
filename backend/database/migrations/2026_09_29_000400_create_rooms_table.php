<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->string('floor', 20)->nullable();
            // Operational (housekeeping) status; future availability uses reservations and room_blocks.
            $table->string('status', 20)->default('AVAILABLE')->index();
            $table->text('notes')->nullable();
            $table->text('maintenance_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'number']);
            $table->index(['room_type_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_status_check CHECK (status IN ('AVAILABLE','RESERVED','OCCUPIED','DIRTY','CLEANING','MAINTENANCE','OUT_OF_SERVICE','BLOCKED'))");
        }

        // Date ranges when a room cannot be sold (maintenance, out of service, owner block).
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on'); // exclusive, like a check-out date
            $table->string('reason', 20);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'starts_on', 'ends_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_dates_check CHECK (ends_on > starts_on)');
            DB::statement("ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_reason_check CHECK (reason IN ('MAINTENANCE','OUT_OF_SERVICE','BLOCKED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
        Schema::dropIfExists('rooms');
    }
};
