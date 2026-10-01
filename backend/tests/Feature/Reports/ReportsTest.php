<?php

namespace Tests\Feature\Reports;

use App\Models\BarTab;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Bar\BarTestCase;

class ReportsTest extends BarTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
    }

    /** Two nights in one of two Deluxe rooms, plus one paid bar bill (₦18,447). */
    private function seedActivity(): BarTab
    {
        $this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201', '202']), fromDay: 0, nights: 2);

        $tabId = $this->openTab();
        $this->deliver($this->placeStandardOrder($tabId));
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/payments", ['method' => 'POS', 'amount' => '18447', 'external_reference' => 'POS-1'])->assertCreated();
        $this->postJson("/api/v1/bar/tabs/{$tabId}/close")->assertOk();

        return BarTab::findOrFail($tabId);
    }

    #[Test]
    public function the_summary_reports_occupancy_revenue_bar_sales_and_payments(): void
    {
        $this->seedActivity();

        $data = $this->actingAsUser($this->staff('Accountant'))
            ->getJson('/api/v1/reports/summary?from=2026-10-05&to=2026-10-06')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['days']);
        $this->assertSame(2, $data['hotel']['rooms']);
        $this->assertSame(2, $data['hotel']['room_nights']);
        $this->assertEquals(50, $data['hotel']['occupancy_percent']);
        $this->assertSame('100000.00', $data['hotel']['room_revenue']);
        $this->assertSame('50000.00', $data['hotel']['adr']);
        $this->assertSame('25000.00', $data['hotel']['revpar']);

        $this->assertSame(1, $data['bar']['bills']);
        $this->assertSame('15600.00', $data['bar']['sales']);
        $this->assertSame('18447.00', $data['bar']['total']);
        $this->assertSame('Mojito', $data['bar']['top_products'][0]['name']);
        $this->assertSame('12000.00', $data['bar']['top_products'][0]['revenue']);

        $this->assertSame('18447.00', $data['payments']['received']);
        $this->assertSame('18447.00', $data['payments']['bar']);
        $this->assertSame('18447.00', collect($data['payments']['by_method'])->firstWhere('method', 'POS')['amount']);

        $this->assertCount(2, $data['daily']);
        $this->assertSame(1, $data['daily'][0]['room_nights']);
        $this->assertSame('15600.00', $data['daily'][0]['bar_sales']);
        $this->assertSame('0.00', $data['daily'][1]['bar_sales']);
    }

    #[Test]
    public function nights_outside_the_range_are_not_counted(): void
    {
        $this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']), fromDay: 0, nights: 3);

        $this->actingAsUser($this->staff('Hotel Manager'))
            ->getJson('/api/v1/reports/summary?from=2026-10-06&to=2026-10-10')
            ->assertOk()
            ->assertJsonPath('data.hotel.room_nights', 2)
            ->assertJsonPath('data.hotel.room_revenue', '100000.00');
    }

    #[Test]
    public function exports_are_csv_downloads(): void
    {
        $tab = $this->seedActivity();

        $response = $this->actingAsUser($this->staff('Accountant'))
            ->get('/api/v1/reports/export?type=bar-sales&from=2026-10-05&to=2026-10-05')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $lines = array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertStringStartsWith('"Closed at",Bill,Table,Waiter', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString($tab->number, $lines[1]);
        $this->assertStringContainsString('18447.00', $lines[1]);

        $payments = $this->get('/api/v1/reports/export?type=payments&from=2026-10-05&to=2026-10-05')->assertOk()->streamedContent();
        $this->assertStringContainsString('POS-1', $payments);

        $this->get('/api/v1/reports/export?type=reservations&from=2026-10-05&to=2026-10-05')->assertOk();
    }

    #[Test]
    public function reports_need_the_right_permissions_and_a_sane_range(): void
    {
        $this->actingAsUser($this->staff('Receptionist'))->getJson('/api/v1/reports/summary')->assertForbidden();
        $this->actingAsUser($this->waiter)->getJson('/api/v1/reports/export?type=payments')->assertForbidden();

        $this->actingAsUser($this->staff('Accountant'));
        $this->getJson('/api/v1/reports/summary')->assertOk()->assertJsonPath('data.to', '2026-10-05')->assertJsonPath('data.from', '2026-09-29');
        $this->getJson('/api/v1/reports/summary?from=2026-10-05&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/v1/reports/summary?from=2025-01-01&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/v1/reports/export?type=everything')->assertStatus(422);
    }
}
