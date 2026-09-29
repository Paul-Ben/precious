<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Our reference, sent to the gateway (Paystack reference / Flutterwave tx_ref).
            $table->string('reference', 64)->unique();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            // What is being paid for: a reservation now, bar bills from M4.
            $table->string('payable_type', 50);
            $table->uuid('payable_id');
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20);                 // GATEWAY | CASH | POS | BANK_TRANSFER
            $table->string('gateway', 32)->nullable();    // paystack | flutterwave
            $table->string('gateway_mode', 8)->nullable(); // test | live
            $table->string('purpose', 20);                // DEPOSIT | BALANCE | FULL | PART
            $table->string('status', 20)->index();        // PENDING | SUCCESSFUL | FAILED | ABANDONED
            $table->char('currency', 3)->default('NGN');
            // amount = credited to the bill; customer_fee = gateway fee passed on to the payer;
            // charged_amount = amount + customer_fee (what the gateway charges the card).
            $table->decimal('amount', 14, 2);
            $table->decimal('customer_fee', 14, 2)->default(0);
            $table->decimal('charged_amount', 14, 2);
            // Fee the gateway actually deducted (from its verify response).
            $table->decimal('gateway_fee', 14, 2)->nullable();
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->string('channel', 40)->nullable();     // card, bank_transfer, ussd …
            $table->text('authorization_url')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('external_reference', 100)->nullable(); // POS slip / transfer ref
            $table->string('note', 500)->nullable();
            $table->string('failure_reason', 255)->nullable();
            // Money received that could not be applied normally (late or mismatched payment).
            $table->boolean('needs_attention')->default(false)->index();
            $table->string('attention_reason', 50)->nullable();
            $table->unsignedSmallInteger('verify_attempts')->default(0);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();       // RCP-2026-000001 (P13)
            $table->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');                     // frozen receipt content
            $table->timestamp('issued_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 30)->unique();       // RFD-2026-00001
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500);
            $table->string('status', 20)->index();        // REQUESTED | APPROVED | REJECTED | COMPLETED
            $table->boolean('requires_second_approval')->default(false);
            $table->string('method', 20)->nullable();     // GATEWAY_DASHBOARD | CASH | BANK_TRANSFER
            $table->string('external_reference', 100)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            // Gateway event identity, used to process each delivery once.
            $table->string('event_key', 150);
            $table->string('event_type', 80)->nullable();
            $table->string('reference', 64)->nullable()->index();
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 50)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('GATEWAY','CASH','POS','BANK_TRANSFER'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('PENDING','SUCCESSFUL','FAILED','ABANDONED'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_purpose_check CHECK (purpose IN ('DEPOSIT','BALANCE','FULL','PART'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_money_check CHECK (amount > 0 AND customer_fee >= 0 AND charged_amount = amount + customer_fee AND refunded_amount >= 0 AND refunded_amount <= amount)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_gateway_check CHECK ((method = 'GATEWAY') = (gateway IS NOT NULL))");
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('REQUESTED','APPROVED','REJECTED','COMPLETED'))");
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
            // P14: the second approver must be someone else.
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_second_approver_check CHECK (NOT requires_second_approval OR approved_by IS NULL OR approved_by <> requested_by)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payments');
    }
};
