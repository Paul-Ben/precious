<?php

namespace Tests\Feature\Finance;

use PHPUnit\Framework\Attributes\Test;

class FinanceReportsTest extends FinanceTestCase
{
    #[Test]
    public function p32_the_dashboard_shows_revenue_expenses_and_net_position(): void
    {
        $this->cashBarSale();                                        // 18,447 received
        $this->expense('5000');                                      // approved
        $this->expense('80000');                                     // pending: not counted
        $owing = $this->openTab(['customer_name' => 'Chidi']);       // 18,447 owed
        $this->deliver($this->placeStandardOrder($owing));

        $data = $this->actingAsUser($this->accountant)
            ->getJson('/api/v1/finance/summary?from=2026-10-01&to=2026-10-05')
            ->assertOk()
            ->json('data');

        $this->assertSame('18447.00', $data['revenue']['received']);
        $this->assertSame('18447.00', $data['revenue']['bar']);
        $this->assertSame('0.00', $data['revenue']['hotel']);
        $this->assertSame('18447.00', collect($data['revenue']['by_method'])->firstWhere('method', 'CASH')['amount']);
        $this->assertSame('5000.00', $data['expenses']['total']);
        $this->assertSame(1, $data['expenses']['pending_count']);
        $this->assertSame('80000.00', $data['expenses']['pending_amount']);
        $this->assertSame('Utilities (power, diesel, water)', $data['expenses']['by_category'][0]['category']);
        $this->assertSame('13447.00', $data['net_position']);
        $this->assertSame('18447.00', $data['outstanding']['bar_tabs']);
        $this->assertSame(1, $data['outstanding']['count']);

        $this->assertCount(5, $data['days']);
        $this->assertSame('2026-10-05', $data['days'][4]['date']);
        $this->assertSame('13447.00', $data['days'][4]['net']);
        $this->assertSame('0.00', $data['days'][0]['received']);

        // Default range: this month to date.
        $this->getJson('/api/v1/finance/summary')->assertOk()->assertJsonPath('data.from', '2026-10-01')->assertJsonPath('data.to', '2026-10-05');
        $this->getJson('/api/v1/finance/summary?from=2026-10-05&to=2026-10-01')->assertStatus(422);
    }

    #[Test]
    public function outstanding_bills_list_guests_and_bar_customers_who_owe(): void
    {
        $owing = $this->openTab(['customer_name' => 'Chidi']);
        $this->placeStandardOrder($owing);
        $this->openTab(['customer_name' => 'Nothing ordered']);    // ₦0: not listed

        $this->actingAsUser($this->accountant)->getJson('/api/v1/finance/outstanding')
            ->assertOk()
            ->assertJsonCount(1, 'data.bar_tabs')
            ->assertJsonPath('data.bar_tabs.0.customer', 'Chidi')
            ->assertJsonPath('data.bar_tabs.0.balance', '18447.00')
            ->assertJsonCount(0, 'data.reservations')
            ->assertJsonPath('data.total', '18447.00');
    }

    #[Test]
    public function p34_reports_download_as_csv_and_excel(): void
    {
        $this->cashBarSale();
        $this->expense('5000');

        $this->actingAsUser($this->accountant);
        $csv = $this->get('/api/v1/finance/export?type=expenses&from=2026-10-01&to=2026-10-05')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $lines = array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertStringStartsWith('Date,Number,Category', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('EXP-2026-00001', $lines[1]);

        $summary = $this->get('/api/v1/finance/export?type=summary&from=2026-10-05&to=2026-10-05')->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-10-05,18447.00,0.00,5000.00,13447.00,No', $summary);

        $xlsx = $this->get('/api/v1/finance/export?type=outstanding&format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->streamedContent();
        $this->assertStringStartsWith('PK', $xlsx);

        $this->get('/api/v1/finance/export?type=refunds&format=xlsx')->assertOk();
        $this->getJson('/api/v1/finance/export?type=everything')->assertStatus(422);
    }

    #[Test]
    public function finance_needs_finance_permissions(): void
    {
        $this->actingAsUser($this->staff('Receptionist'))->getJson('/api/v1/finance/summary')->assertForbidden();
        $this->actingAsUser($this->waiter)->getJson('/api/v1/finance/outstanding')->assertForbidden();
        $this->actingAsUser($this->staff('Auditor'))->getJson('/api/v1/finance/summary')->assertOk();
        $this->getJson('/api/v1/finance/export?type=summary')->assertOk();
    }
}
