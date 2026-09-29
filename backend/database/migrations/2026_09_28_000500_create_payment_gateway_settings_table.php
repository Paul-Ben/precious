<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32)->unique();          // paystack | flutterwave
            $table->string('display_name', 100);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('mode', 8)->default('test');       // test | live
            // Encrypted JSON (APP_KEY) holding test_* and live_* keys.
            $table->text('credentials')->nullable();
            $table->jsonb('options')->nullable();              // non-secret options (channels, etc.)
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_mode', 8)->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->string('last_test_message')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_settings ADD CONSTRAINT payment_gateway_settings_mode_check CHECK (mode IN ('test', 'live'))");
            // At most one default gateway.
            DB::statement('CREATE UNIQUE INDEX payment_gateway_settings_single_default ON payment_gateway_settings (is_default) WHERE is_default = true');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
    }
};
