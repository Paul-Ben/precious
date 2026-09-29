<?php

namespace App\Domain\Guests;

use App\Domain\Audit\AuditService;
use App\Enums\GuestDocumentType;
use App\Exceptions\BusinessRuleException;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestService
{
    private const AUDITED = [
        'first_name', 'last_name', 'email', 'phone', 'nationality', 'date_of_birth',
        'address', 'company', 'is_vip', 'notes',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Guest
    {
        return DB::transaction(function () use ($data) {
            $guest = Guest::create($this->normalise($data));
            $this->audit->record('guests.created', $guest, null, $this->snapshot($guest));

            return $guest;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Guest $guest, array $data): Guest
    {
        return DB::transaction(function () use ($guest, $data) {
            $before = $this->snapshot($guest);
            $guest->fill($this->normalise($data))->save();
            [$old, $new] = $this->audit->diff($before, $this->snapshot($guest));

            if ($new !== []) {
                $this->audit->record('guests.updated', $guest, $old, $new);
            }

            return $guest->refresh();
        });
    }

    /**
     * The guest profile linked to a customer account, created on first use.
     */
    public function forCustomer(User $user, array $fallback = []): Guest
    {
        $existing = Guest::query()->where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        [$first, $last] = array_pad(preg_split('/\s+/', trim($user->name), 2) ?: [], 2, '');

        return $this->create([
            'user_id' => $user->id,
            'first_name' => $fallback['first_name'] ?? ($first ?: $user->name),
            'last_name' => $fallback['last_name'] ?? ($last ?: '-'),
            'email' => $user->email,
            'phone' => $fallback['phone'] ?? $user->phone,
        ]);
    }

    public function storeDocument(Guest $guest, UploadedFile $file, GuestDocumentType $type, ?string $number, ?string $expiresOn, User $actor): GuestDocument
    {
        $disk = config('hotel.documents_disk');
        $path = $file->storeAs(
            "guest-documents/{$guest->id}",
            Str::uuid()->toString().'.'.strtolower($file->extension() ?: 'bin'),
            ['disk' => $disk, 'visibility' => 'private']
        );

        return DB::transaction(function () use ($guest, $file, $type, $number, $expiresOn, $actor, $disk, $path) {
            $document = $guest->documents()->create([
                'type' => $type,
                'number' => $number,
                'expires_on' => $expiresOn,
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
                'uploaded_by' => $actor->id,
            ]);

            $this->audit->record('guests.document_uploaded', $guest, null, [
                'document_id' => $document->id,
                'type' => $type->value,
            ]);

            return $document;
        });
    }

    public function downloadDocument(Guest $guest, GuestDocument $document): StreamedResponse
    {
        abort_unless($document->guest_id === $guest->id, 404);

        $this->audit->record('guests.document_viewed', $guest, metadata: [
            'document_id' => $document->id,
            'type' => $document->type->value,
        ]);

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->type->value.'-'.$guest->last_name.'.'.pathinfo($document->path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'no-store, private']
        );
    }

    public function verifyDocument(Guest $guest, GuestDocument $document, User $actor): GuestDocument
    {
        abort_unless($document->guest_id === $guest->id, 404);

        return DB::transaction(function () use ($guest, $document, $actor) {
            $document->forceFill(['verified_at' => now(), 'verified_by' => $actor->id])->save();
            $this->audit->record('guests.document_verified', $guest, metadata: ['document_id' => $document->id]);

            return $document;
        });
    }

    public function deleteDocument(Guest $guest, GuestDocument $document): void
    {
        abort_unless($document->guest_id === $guest->id, 404);

        DB::transaction(function () use ($guest, $document) {
            $document->delete();
            $this->audit->record('guests.document_deleted', $guest, ['document_id' => $document->id, 'type' => $document->type->value], null);
        });

        Storage::disk($document->disk)->delete($document->path);
    }

    public function delete(Guest $guest): void
    {
        if ($guest->reservations()->exists()) {
            throw new BusinessRuleException('Guests with reservations cannot be deleted.', 'GUEST_HAS_RESERVATIONS');
        }

        DB::transaction(function () use ($guest) {
            $snapshot = $this->snapshot($guest);
            $guest->delete();
            $this->audit->record('guests.deleted', $guest, $snapshot, null);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (array_key_exists('email', $data)) {
            $data['email'] = $data['email'] ? mb_strtolower(trim($data['email'])) : null;
        }

        foreach (['first_name', 'last_name'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Guest $guest): array
    {
        $data = $guest->only(self::AUDITED);
        $data['date_of_birth'] = $guest->date_of_birth?->toDateString();

        return $data;
    }
}
