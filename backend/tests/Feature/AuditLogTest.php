<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    #[Test]
    public function audit_logs_are_immutable(): void
    {
        $log = app(AuditService::class)->record('test.event', 'thing', null, ['a' => 1]);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    #[Test]
    public function the_database_rejects_updates_to_audit_logs(): void
    {
        $log = app(AuditService::class)->record('test.event');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    #[Test]
    public function secrets_are_redacted(): void
    {
        $log = app(AuditService::class)->record('test.event', null, null, [
            'name' => 'Ada',
            'password' => 'hunter2',
            'nested' => ['secret_key' => 'sk_live_x', 'label' => 'ok'],
        ]);

        $this->assertSame([
            'name' => 'Ada',
            'password' => AuditService::REDACTED,
            'nested' => ['secret_key' => AuditService::REDACTED, 'label' => 'ok'],
        ], $log->new_values);
    }

    #[Test]
    public function auditors_can_browse_the_log_and_others_cannot(): void
    {
        app(AuditService::class)->record('test.event');

        $this->actingAsUser($this->staff('Auditor'))
            ->getJson('/api/v1/audit-logs?action=test.')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'test.event');

        $this->actingAsUser($this->staff('Receptionist'))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }
}
