<?php

namespace App\Models;

use App\Enums\GuestDocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identity document scan. Stored on a PRIVATE disk; only readable through the
 * authorised, audited download endpoint. The document number is encrypted.
 */
class GuestDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'guest_id', 'type', 'number', 'expires_on', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'uploaded_by', 'verified_at', 'verified_by',
    ];

    protected $hidden = ['path', 'disk', 'number'];

    protected function casts(): array
    {
        return [
            'type' => GuestDocumentType::class,
            'number' => 'encrypted',
            'expires_on' => 'date',
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** "A1234567" → "••••4567" */
    public function maskedNumber(): ?string
    {
        $number = $this->number;

        if (! $number) {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($number) - 4)).mb_substr($number, -4);
    }
}
