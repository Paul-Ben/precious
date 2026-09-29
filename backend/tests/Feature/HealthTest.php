<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthTest extends TestCase
{
    #[Test]
    public function health_endpoint_reports_ok_with_standard_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonStructure(['success', 'message', 'data' => ['status', 'time', 'checks'], 'meta']);
    }

    #[Test]
    public function a_valid_incoming_request_id_is_echoed_back(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'abc12345-trace'])
            ->assertHeader('X-Request-Id', 'abc12345-trace');
    }
}
