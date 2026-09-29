<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();          // e.g. auth.login, roles.permissions_synced
            $table->string('auditable_type', 100)->nullable(); // short entity name, e.g. user, role
            $table->string('auditable_id', 64)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Audit records are append-only: block UPDATE and DELETE at the database level.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only (% not allowed)', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_immutable();');
        }

        Schema::dropIfExists('audit_logs');
    }
};
