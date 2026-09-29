<?php

namespace Tests\Feature\Hotel;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuestTest extends TestCase
{
    #[Test]
    public function guests_can_be_created_and_searched_by_name_phone_email_or_reservation(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $number = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(2)))->json('data.reservation.number');

        $this->actingAsUser($this->staff('Receptionist'));
        $this->postJson('/api/v1/guests', ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348055551234', 'email' => 'NGOZI@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ngozi@example.com');

        $this->getJson('/api/v1/guests?search=ngozi eze')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/guests?search=55551234')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/guests?search=john.doe@')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/guests?search='.$number)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'John Doe');
    }

    #[Test]
    public function id_documents_are_private_encrypted_and_every_view_is_audited(): void
    {
        Storage::fake('local');
        $guest = Guest::factory()->create(['last_name' => 'Doe']);
        $this->actingAsUser($this->staff('Receptionist'));

        $docId = $this->postJson("/api/v1/guests/{$guest->id}/documents", [
            'type' => 'PASSPORT',
            'number' => 'A12345678',
            'file' => UploadedFile::fake()->image('passport.jpg', 800, 600),
        ])->assertCreated()
            ->assertJsonPath('data.number_masked', '•••••5678')
            ->assertJsonMissingPath('data.path')
            ->json('data.id');

        // Stored encrypted, not in plain text.
        $raw = DB::table('guest_documents')->where('id', $docId)->value('number');
        $this->assertStringNotContainsString('A12345678', $raw);
        $this->assertSame('A12345678', GuestDocument::find($docId)->number);

        $this->get("/api/v1/guests/{$guest->id}/documents/{$docId}/download")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'guests.document_viewed')->exists());
    }

    #[Test]
    public function staff_without_document_permission_cannot_see_id_documents(): void
    {
        Storage::fake('local');
        $guest = Guest::factory()->create();

        $this->actingAsUser($this->staff('Auditor'))->getJson("/api/v1/guests/{$guest->id}")->assertOk();
        $this->getJson("/api/v1/guests/{$guest->id}/documents")->assertForbidden();

        $this->actingAsUser($this->staff('Waiter'))->getJson("/api/v1/guests/{$guest->id}")->assertForbidden();
    }

    #[Test]
    public function only_documents_and_images_are_accepted(): void
    {
        Storage::fake('local');
        $guest = Guest::factory()->create();

        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/guests/{$guest->id}/documents", [
                'type' => 'NATIONAL_ID',
                'file' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[Test]
    public function guests_with_reservations_cannot_be_deleted(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(2)))->assertCreated();
        $guest = Guest::firstOrFail();

        $this->actingAsUser($this->staff('Hotel Manager'))
            ->deleteJson("/api/v1/guests/{$guest->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'GUEST_HAS_RESERVATIONS');
    }
}
