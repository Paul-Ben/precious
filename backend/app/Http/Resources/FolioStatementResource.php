<?php

namespace App\Http\Resources;

use App\Models\FolioStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FolioStatement
 */
class FolioStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'reservation_id' => $this->reservation_id,
            'issued_at' => $this->issued_at->toIso8601String(),
            'emailed_at' => $this->emailed_at?->toIso8601String(),
            ...$this->snapshot,
        ];
    }
}
