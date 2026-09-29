<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bar_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });

        Schema::create('bar_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('bar_categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // P19: menu prices are before service charge and VAT.
            $table->decimal('price', 14, 2);
            // Sold out right now (bartender can switch) vs. on the menu at all.
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'name']);
        });

        Schema::create('bar_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('area', 60)->nullable();
            $table->string('status', 12)->default('AVAILABLE');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'name']);
        });

        // One visit's running bill (a customer may place several orders).
        Schema::create('bar_tabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 30)->unique();            // TAB-2026-00001
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('bar_tables')->nullOnDelete();
            $table->foreignUuid('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('status', 12)->index();               // OPEN | CLOSED | CANCELLED
            $table->string('settlement', 20)->nullable();        // PAID | CHARGED_TO_ROOM
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->foreignUuid('discount_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('service_charge', 14, 2)->default(0);
            $table->decimal('vat', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            // Charge to room (P9).
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_charge_id')->nullable()->constrained('reservation_charges')->nullOnDelete();
            // Customer pay-link token, encrypted (it is re-sent in every bill email).
            $table->text('pay_token')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_note', 255)->nullable();
            $table->timestamps();

            $table->index(['table_id', 'status']);
        });

        Schema::create('bar_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 30)->unique();              // ORD-2026-000001
            $table->foreignUuid('tab_id')->constrained('bar_tabs')->restrictOnDelete();
            $table->foreignUuid('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->index();               // PLACED … DELIVERED | CANCELLED
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->timestamp('placed_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignUuid('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('bar_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained('bar_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('bar_products')->nullOnDelete();
            $table->string('name', 120);                         // snapshot
            $table->decimal('unit_price', 14, 2);                // snapshot
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 14, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('reservation_charges', function (Blueprint $table) {
            $table->foreignUuid('bar_tab_id')->nullable()->after('service_id')->constrained('bar_tabs')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bar_products ADD CONSTRAINT bar_products_price_check CHECK (price >= 0)');
            DB::statement("ALTER TABLE bar_tables ADD CONSTRAINT bar_tables_status_check CHECK (status IN ('AVAILABLE','OCCUPIED','RESERVED','CLEANING','BLOCKED'))");
            DB::statement("ALTER TABLE bar_tabs ADD CONSTRAINT bar_tabs_status_check CHECK (status IN ('OPEN','CLOSED','CANCELLED'))");
            DB::statement("ALTER TABLE bar_tabs ADD CONSTRAINT bar_tabs_settlement_check CHECK (settlement IS NULL OR settlement IN ('PAID','CHARGED_TO_ROOM'))");
            DB::statement('ALTER TABLE bar_tabs ADD CONSTRAINT bar_tabs_money_check CHECK (subtotal >= 0 AND discount >= 0 AND discount <= subtotal AND total = subtotal - discount + service_charge + vat AND amount_paid >= 0)');
            DB::statement("ALTER TABLE bar_orders ADD CONSTRAINT bar_orders_status_check CHECK (status IN ('PLACED','ACCEPTED','PREPARING','READY','DELIVERED','CANCELLED'))");
            DB::statement('ALTER TABLE bar_order_items ADD CONSTRAINT bar_order_items_check CHECK (quantity > 0 AND unit_price >= 0 AND line_total = unit_price * quantity)');

            // Bar tabs charged to a room appear on the hotel bill as BAR lines.
            DB::statement('ALTER TABLE reservation_charges DROP CONSTRAINT IF EXISTS reservation_charges_category_check');
            DB::statement("ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_category_check CHECK (category IN ('SERVICE','EXTRA_NIGHT','LATE_CHECKOUT','ADJUSTMENT','OTHER','BAR'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservation_charges DROP CONSTRAINT IF EXISTS reservation_charges_category_check');
            DB::statement("ALTER TABLE reservation_charges ADD CONSTRAINT reservation_charges_category_check CHECK (category IN ('SERVICE','EXTRA_NIGHT','LATE_CHECKOUT','ADJUSTMENT','OTHER'))");
        }

        Schema::table('reservation_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bar_tab_id');
        });
        Schema::dropIfExists('bar_order_items');
        Schema::dropIfExists('bar_orders');
        Schema::dropIfExists('bar_tabs');
        Schema::dropIfExists('bar_tables');
        Schema::dropIfExists('bar_products');
        Schema::dropIfExists('bar_categories');
    }
};
