<?php

namespace App\Http\Resources;

use App\Models\GuestDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes the stored file path or the full document number.
 *
 * @mixin GuestDocument
 */
class GuestDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'number_masked' => $this->maskedNumber(),
            'expires_on' => $this->expires_on?->toDateString(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
