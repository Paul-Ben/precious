<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * M6 — expenses and daily closing (spec §34, owner rules P29–P34).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();                 // EXP-2026-00001
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->date('expense_date')->index();
            $table->string('description', 255);
            $table->string('payee', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);                           // CASH | BANK_TRANSFER | POS | CHEQUE
            $table->string('reference', 120)->nullable();
            $table->string('status', 12)->index();                  // PENDING | APPROVED | REJECTED | VOID
            $table->string('receipt_disk', 30)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_name', 190)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'expense_date']);
        });

        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12);                           // CLOSED | REOPENED
            $table->json('summary');                                // snapshot of the figures at closing
            $table->decimal('cash_expected', 14, 2);
            $table->decimal('cash_counted', 14, 2);
            $table->decimal('cash_difference', 14, 2);              // counted − expected
            $table->string('note', 500)->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->foreignUuid('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'VOID'))");
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_method_check CHECK (method IN ('CASH', 'BANK_TRANSFER', 'POS', 'CHEQUE'))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE daily_closings ADD CONSTRAINT daily_closings_status_check CHECK (status IN ('CLOSED', 'REOPENED'))");
        }

        $this->seedCategories();
        $this->grantPermissions();
    }

    /** P29 standard categories, for databases that already have the property (fresh installs: PropertySeeder). */
    private function seedCategories(): void
    {
        $property = DB::table('properties')->orderBy('id')->first();

        if (! $property) {
            return;
        }

        foreach (config('hotel.expense_categories') as $name) {
            DB::table('expense_categories')->insertOrIgnore([
                'property_id' => $property->id, 'name' => $name, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** New permissions for roles that already exist (new installs get them from RoleSeeder). */
    private function grantPermissions(): void
    {
        $roleClass = config('permission.models.role');
        $permissionClass = config('permission.models.permission');

        $grants = [
            'Hotel Manager' => ['finance.expenses', 'finance.expenses.approve', 'finance.close_day'],
            'Accountant' => ['finance.close_day'],
            'Administrator' => ['finance.expenses.approve', 'finance.close_day', 'finance.reopen_day'],
        ];

        // givePermissionTo() throws for unknown names, so make sure every granted one exists.
        foreach (array_unique(array_merge(...array_values($grants))) as $name) {
            $permissionClass::findOrCreate($name, 'web');
        }

        foreach ($grants as $roleName => $permissions) {
            $role = $roleClass::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
